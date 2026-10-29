<?php
/**
 * systems/rooms.php — Multi-room мир: карты комнат, переходы (exit) и респаун.
 *
 * АРХИТЕКТУРА (рассчитана на будущие 25 помещений сетки 5×5 A..E × 1..5):
 *   - Состояние хранит словарь $state['rooms'] = {ROOM_ID => карта комнаты}
 *     (формат карты прежний: room_id,width,height,grid,exits,exit_positions,stairs).
 *     Комнаты грузятся лениво из data/<ID>.json при первом обращении — в Redis
 *     физически лежат только реально используемые комнаты; добавление новой
 *     комнаты = новый JSON-файл + запись в exits соседних, без правок кода.
 *   - Игрок привязан к комнате полем ROOM_ID; NPC/объекты/ловушки — тоже.
 *   - Взаимодействие сущностей возможно только внутри одной комнаты — это
 *     обеспечивают фильтры по ROOM_ID в targeting/objects/movement.
 *   - Переход между комнатами — rooms_teleport(): игрок «выписывается» из
 *     старой комнаты (снимается MOVING, клетки не занимает) и появляется у
 *     двери новой комнаты на БЛИЖАЙШЕЙ СВОБОДНОЙ клетке (защита от body-block
 *     двери другим игроком: если ближайшая клетка занята — берётся следующая
 *     по удалённости и т.д.).
 *   - Объект EXIT («дверь») — постоянный триггер в карте комнаты
 *     (exits["x,y"] = "КУДА"), срабатывает по тому же принципу, что LAND_MINE:
 *     достаточно наступить на клетку. После срабатывания НЕ исчезает и доступен
 *     повторно любому игроку.
 *
 * СОБЫТИЕ ШИНЫ: 'room.changed' (fact) — эмитится после успешного переноса.
 */

require_once __DIR__ . '/../engine/geometry.php';
require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/world.php';
require_once __DIR__ . '/targeting.php';

/**
 * Карта комнаты.
 *
 * ★ ДВА РАЗНЫХ ИСТОЧНИКА — и это не случайность, а следствие разделения
 *   «изначальное состояние» / «живой бой»:
 *     1) СТАТИЧЕСКИЙ КОНТЕНТ (карты комнат: grid/exits/spawn_cells/расстановка
 *        objects-traps-npcs) — единственный источник истины data/<ID>.json на
 *        диске. Он читается ПЕРВЫМ всегда.
 *        Причина (regression): Redis хранит копию карты как часть состояния
 *        боя, поэтому после правки файла комнаты «в мир уже загружена старая
 *        версия» — reset-скрипт собирал бы из battle:room:<ID> пустую
 *        расстановку и молча терял объекты/мины/NPC. Правка JSON-карты теперь
 *        действует сразу, без пересохранения мира.
 *     2) РАНТАЙМ-СЛОЙ — state['rooms'] (комната уже оседла в текущем запросе)
 *        и боевая копия battle:room:<ID> (её обновляют teleport/world_save).
 *        Они используются, когда диск недоступен или когда рантайм легитимно
 *        изменил комнату (например, добавил в state карту новой комнаты).
 *   Комнаты грузятся лениво; в Redis физически лежат только реально используемые
 *   комнаты. Добавление новой комнаты = новый JSON-файл + запись в exits
 *   соседних, без правок кода.
 *
 * Возвращает null если комната неизвестна/карта битая — вызывающие обязаны
 * tolerate null (не ронять мир из-за отсутствующей комнаты).
 */
function room_get($state, $room_id) {
    if ($room_id === null || $room_id === '') return null;
    // 1) СТАТИКА: карта комнаты с диска — canonical source контента.
    $disk = room_read_static_card($room_id);
    if ($disk !== null) return $disk;
    // 2) Уже загруженная в этом состоянии копия (ленивый кэш текущего запроса).
    if (isset($state['rooms'][$room_id])) return $state['rooms'][$room_id];
    // 3) Боевая копия из Redis (fallback, если файл недоступен).
    try {
        $raw = getRedis()->get(REDIS_ROOM_KEY_PREFIX . $room_id);
        if ($raw !== false && $raw !== null && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['grid'])) return $data;
        }
    } catch (Throwable $e) {
        // Хранилище недоступно — считаем комнату неизвестной вместо фатала.
    }
    return null;
}

/**
 * Прочитать СТАТИЧЕСКУЮ карту комнаты с диска (data/<ID>.json). Только чтение,
 * без кэша: вызывается при первом обращении к комнате (лениво), а не каждый тик.
 * @return array|null null — файла нет/он битый (не валидная карта с grid).
 */
function room_read_static_card($room_id) {
    $path = __DIR__ . '/../../data/' . basename((string)$room_id) . '.json';
    if (!is_file($path)) return null;
    $content = @file_get_contents($path);
    if ($content === false) return null;
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['grid'])) return null;
    return $data;
}

/**
 * Комната, в которой находится игрок (или null для мёртвых/удалённых —
 * они вне мира и клетки не занимают).
 */
function room_of_player($state, $pid) {
    $player = $state['players'][$pid] ?? null;
    if (!$player) return null;
    if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) return null;
    return $player['ROOM_ID'] ?? null;
}

/**
 * Одна проверка проходимости клетки в конкретной комнате: стена (grid==0),
 * целый объект или живой NPC той же комнаты блокируют.
 * (Exit-клетка проходима — через неё и ходят.)
 * @param string $room_key канонический ID комнаты (ключ state['rooms']);
 *        у JSON-карт поле room_id может отсутствовать — фильтры идём по ключу.
 */
function room_cell_blocked($state, $room, $x, $y, $room_key = null) {
    if ($room_key === null) $room_key = $room['room_id'] ?? null;
    if (!isValidPosition($x, $y)) return true;
    if ($room === null) return true; // комнаты нет — клетка недоступна
    $g = $room['grid'][$y][$x] ?? 0;
    if ((int)$g !== 1) return true;
    foreach ($state['objects'] as $obj) {
        if (($obj['ROOM_ID'] ?? null) !== $room_key) continue;
        if ($obj['STATUS'] === 'DESTROYED') continue;
        $pos = parseXY($obj['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return true;
    }
    foreach ($state['npcs'] as $npc) {
        if (($npc['ROOM_ID'] ?? null) !== $room_key) continue;
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH', 'REMOVED'], true)) continue;
        $pos = parseXY($npc['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return true;
    }
    return false;
}

/**
 * Клетки-кандидаты для появления рядом с дверью, упорядоченные «от лучшей к
 * худшей»:
 *   1) сначала прямые соседи (манхэттен d=1), затем кольцо d=2, d=3 ...;
 *   2) внутри кольца — приоритет направлению ОТ двери вглубь комнаты
 *      (первый шаг кратчайшего пути от двери к центру карты), чтобы
 *      входящий не прижался спиной к двери и не торчал в стену.
 */
function room_candidate_cells($state, $room, $door_x, $door_y) {
    $center_x = ($room['width'] ?? GRID_WIDTH) / 2 - 0.5;
    $center_y = ($room['height'] ?? GRID_HEIGHT) / 2 - 0.5;
    $pref_dx = ($center_x > $door_x) ? 1 : (($center_x < $door_x) ? -1 : 0);
    $pref_dy = ($center_y > $door_y) ? 1 : (($center_y < $door_y) ? -1 : 0);

    $cells = [];
    for ($dx = -4; $dx <= 4; $dx++) {
        for ($dy = -4; $dy <= 4; $dy++) {
            if ($dx === 0 && $dy === 0) continue;
            $x = $door_x + $dx;
            $y = $door_y + $dy;
            if (!isValidPosition($x, $y)) continue;
            $cells[] = ['x' => $x, 'y' => $y, 'd' => abs($dx) + abs($dy)];
        }
    }
    usort($cells, function ($a, $b) use ($pref_dx, $pref_dy) {
        if ($a['d'] !== $b['d']) return $a['d'] <=> $b['d']; // сначала ближние кольца
        // внутри кольца — насколько клетка «ведёт вглубь» от двери
        $pa = $pref_dx * ($a['x'] - $door_x) + $pref_dy * ($a['y'] - $door_y);
        $pb = $pref_dx * ($b['x'] - $door_x) + $pref_dy * ($b['y'] - $door_y);
        if ($pa !== $pb) return $pb <=> $pa;
        return ($a['x'] * 100 + $a['y']) <=> ($b['x'] * 100 + $b['y']); // детерминизм
    });
    return $cells;
}

/**
 * ★ Ближайшая СВОБОДНАЯ клетка появления рядом с дверью (защита от body-block:
 * если ближайшая клетка занята другим игроком — берётся следующая по порядку).
 *   - свободна = проходима (стена/целый объект/живой NPC не подходят) и не
 *     занята живым игроком ТОЙ ЖЕ комнаты (MOVING-игрок блокирует и свою
 *     текущую, и конечную клетку — см. is_player_at_position);
 *   - $forbidden — явный чёрный список клеток ("x,y"); $is_exit — callback,
 *     возвращающий true для КЛЕТКИ-ВЫХОДА: на саму дверь боец вставать не
 *     должен (появление «рядом» с дверью, совпадает с картинкой клиента);
 *   - если ничего не найдено — возвращается null (вызывающий решает).
 */
function room_find_spawn_cell($state, $room, $door_x, $door_y, $exclude_pid, $room_key = null, $forbidden = [], $is_exit = null) {
    if ($room === null) return null;
    if ($room_key === null) $room_key = $room['room_id'] ?? null;
    foreach (room_candidate_cells($state, $room, $door_x, $door_y) as $c) {
        $xy = formatXY($c['x'], $c['y']);
        if (isset($forbidden[$xy])) continue;
        if ($is_exit !== null && $is_exit($xy)) continue;
        if (room_cell_blocked($state, $room, $c['x'], $c['y'], $room_key)) continue;
        if (is_player_at_position($state, $c['x'], $c['y'], $exclude_pid, $room_key)) continue;
        return $xy;
    }
    return null;
}

/**
 * ★ Выделенная СПАВН-КЛЕТКА игрока в комнате — берётся НЕ из позиции смерти
 * и НЕ из «последней занятой клетки», а из карты комнаты: JSON-файл
 * data/<ID>.json, секция "spawn_cells": { "<НИК">: "<x>,<y>" }.
 * Координата обозначена непосредственно в файле комнаты, поэтому её можно
 * передвинуть (например, под будущий тайлсет спавн-комнат) без правок кода.
 * Возвращает "x,y" или null — если для игрока клетка в этой комнате не
 * назначена (или комната о ней ничего не знает).
 */
function room_defined_spawn_cell($room, $player_id) {
    if ($room === null) return null;
    $cells = $room['spawn_cells'] ?? null;
    if (!is_array($cells)) return null;
    $xy = $cells[$player_id] ?? null;
    if (!is_string($xy) || $xy === '') return null;
    return $xy;
}

/**
 * ★ Стартовая клетка бойца при ПЕРВОМ появлении в бою (сборка мира).
 * Единственный владелец этого правила: её же использует движок для респауна
 * (room_player_spawn_cell), поэтому «точка старта» и «точка возвращения после
 * смерти» физически не могут разойтись.
 *   1) выделенная клетка из spawn_cells карты комнаты (если задана для игрока);
 *   2) иначе — позиция из снимка бойцов (CUR_POSITION_XY / POSITION_XY);
 *   3.5) иначе — ближайшая свободная клетка вокруг точки появления;
 *   4) страховка — центр комнаты.
 * Возвращает "x,y" или null (комната неизвестна).
 */
function room_initial_spawn_cell($state, $room_id, $player_id, $fallback_xy = '') {
    if ($room_id === null || $room_id === '' || $player_id === null || $player_id === '') return null;
    $room = room_get($state, $room_id);
    if ($room === null) return null;

    $is_exit = function ($xy) use ($room) {
        return isset($room['exits'][$xy]) || isset($room['exit_rooms'][$xy]);
    };
    $forbidden = [];
    foreach (array_merge(array_keys($room['exits'] ?? []),
                         array_keys($room['exit_rooms'] ?? [])) as $xy) {
        $forbidden[$xy] = true;
    }

    // Точка-предпочтение: спавн-клетка карты, иначе позиция из снимка.
    $preferred = room_defined_spawn_cell($room, $player_id);
    if (($preferred === null || $preferred === '') && is_string($fallback_xy) && $fallback_xy !== '') {
        $preferred = $fallback_xy;
    }

    if ($preferred !== null && $preferred !== '' && !$is_exit($preferred)) {
        $p = parseXY($preferred);
        if (isValidPosition($p['x'], $p['y']) &&
            !room_cell_blocked($state, $room, $p['x'], $p['y'], $room_id) &&
            !is_player_at_position($state, $p['x'], $p['y'], $player_id, $room_id, true)) {
            return $preferred; // точка свободна — встаём ровно на неё
        }
        $near = room_find_spawn_cell($state, $room, $p['x'], $p['y'], $player_id, $room_id, $forbidden, $is_exit);
        if ($near !== null) return $near;
    }

    $cx = (int)floor(($room['width'] ?? GRID_WIDTH) / 2);
    $cy = (int)floor(($room['height'] ?? GRID_HEIGHT) / 2);
    return room_find_spawn_cell($state, $room, $cx, $cy, $player_id, $room_id, $forbidden, $is_exit);
}

/**
 * ★ Личная СПАВН-КЛЕТКА бойца в его SPAWN_ROOM — конкретная ВЫДЕЛЕННАЯ клетка,
 * обозначенная в карте комнаты (data/<ID>.json → "spawn_cells"), для появления
 * после смерти И при первом запуске (а НЕ «те координаты, где погиб»):
 *   1) выделенная клетка из "spawn_cells" комнаты, если она проходима, не
 *      является дверью и сейчас не занята другим бойцом/NPC/объектом;
 *   2) иначе — ближайшая СВОБОДНАЯ клетка ВОКРУГ выделенной (body-block соседа
 *      не должен мешать воскреснуть «у своего дома»; порядок детерминирован);
 *   3) если в комнате нет записи "spawn_cells" для игрока — ближайшая свободная
 *      клетка вокруг личной START_POSITION_XY (legacy-поведение), а при её
 *      отсутствии/чужой комнате — от центра комнаты (страховка).
 * ★ ВАЖНО: "spawn_cells" НУЖНА ТОЛЬКО В СПАВН-КОМНАТАХ (A1/A5/E1/E5 — те, что
 * указаны у игроков в SPAWN_ROOM). Для проходных комнат (хаб B1) её быть НЕ
 * должно: при обычных переходах между комнатами (reason='exit') игрока размещает
 * у обратной двери старый механизм room_find_spawn_cell — эта ветка кода
 * спавн-клетки не касается и работает как прежде. Если же боец погибнет в чужой
 * комнате, его всегда несёт в собственную SPAWN_ROOM (там запись есть), поэтому
 * спавн-клетки в транзитных комнатах не требуются в принципе.
 * На саму дверь спавн никогда не ставится: стоящий на exit-клетке боец при
 * первом же тике получил бы повторный переход (порочный круг
 * «респаун → сразу ушёл в B1»).
 * Возвращает "x,y" или null (вызывающий решает).
 */
function room_player_spawn_cell($state, $room_key, $player_id) {
    $player = $state['players'][$player_id] ?? null;
    if (!$player || $room_key === null || $room_key === '') return null;
    $room = room_get($state, $room_key);
    if ($room === null) return null;

    $is_exit = function ($xy) use ($room) {
        return isset($room['exits'][$xy]) || isset($room['exit_rooms'][$xy]);
    };
    // Чёрный список: ВСЕ двери комнаты (кольцо кандидатов строится вокруг
    // одной точки, но чужая дверь может попасть в радиус — см. rooms_teleport).
    $forbidden = [];
    foreach (array_merge(array_keys($room['exits'] ?? []),
                         array_keys($room['exit_rooms'] ?? [])) as $xy) {
        $forbidden[$xy] = true;
    }

    // ── 1) Выделенная спавн-клетка из файла комнаты ("spawn_cells") ──
    $defined = room_defined_spawn_cell($room, $player_id);
    if ($defined !== null && !$is_exit($defined)) {
        $dp = parseXY($defined);
        if (isValidPosition($dp['x'], $dp['y']) &&
            !room_cell_blocked($state, $room, $dp['x'], $dp['y'], $room_key) &&
            !is_player_at_position($state, $dp['x'], $dp['y'], $player_id, $room_key, true)) {
            return $defined; // выделенная клетка свободна — встаём ровно на неё
        }
        // ── 2) Клетка занята/непроходима — ближайшее свободное кольцо вокруг НЕЁ
        $near = room_find_spawn_cell($state, $room, $dp['x'], $dp['y'], $player_id, $room_key, $forbidden, $is_exit);
        if ($near !== null) return $near;
    }

    // ── 3) Legacy-страховка: якорь = личная START_POSITION_XY (если относится
    // к этой комнате), иначе — центр комнаты. ──
    $anchor = null;
    $start = $player['START_POSITION_XY'] ?? null;
    $home = ($player['SPAWN_ROOM'] ?? '') === '' ? null : $player['SPAWN_ROOM'];
    if ($start !== null && !$is_exit($start) && ($home === null || $home === $room_key)) {
        $sp = parseXY($start);
        if (isValidPosition($sp['x'], $sp['y'])) $anchor = [$sp['x'], $sp['y']];
    }
    if ($anchor === null) {
        $anchor = [(int)floor(($room['width'] ?? GRID_WIDTH) / 2),
                   (int)floor(($room['height'] ?? GRID_HEIGHT) / 2)];
    }
    $cell = room_find_spawn_cell($state, $room, $anchor[0], $anchor[1], $player_id, $room_key, $forbidden, $is_exit);
    if ($cell !== null) return $cell;
    $cx = (int)floor(($room['width'] ?? GRID_WIDTH) / 2);
    $cy = (int)floor(($room['height'] ?? GRID_HEIGHT) / 2);
    if ($cx !== $anchor[0] || $cy !== $anchor[1]) {
        return room_find_spawn_cell($state, $room, $cx, $cy, $player_id, $room_key, $forbidden, $is_exit);
    }
    return null;
}

/**
 * ★ Перенос игрока в другую комнату (аналог срабатывания exit; используется и
 * для респауна после смерти). Идемпотентен по комнате: перенос в ту же, где
 * игрок уже стоит, не делает ничего.
 *
 * Что происходит:
 *   1) игрок «выписывается» из старой комнаты: снимается MOVING (анимация
 *      обрывается, MOVE_*_TIME чистятся), STATUS → IDLE;
 *   2) ROOM_ID перезаписывается, позиция — ближайшая свободная клетка у двери
 *      новой комнаты (body-block исключён поиском следующей клетки);
 *   3) START_POSITION_XY при обычном переходе (reason='exit') НЕ трогаем: это
 *      ЯКОРЬ ЛИЧНОЙ СПАВН-КЛЕТКИ игрока (SPAWN_ROOM из initial_snapshot.json / карт комнат).
 *      ★ Респаун (reason='respawn') целится именно в этот якорь, но встаёт
 *      на ВЫДЕЛЕННУЮ СВОБОДНУЮ КЛЕТКУ спавн-комнаты рядом с ним (см. ниже
 *      «спавн-клетка»): боец возвращается в свою SPAWN_ROOM на конкретную
 *      клетку, а не «в тех же координатах, где погиб»;
 *   4) пишется лог боя (общий журнал — корректен для любой комнаты);
 *   5) эмитится fact-событие 'room.changed'.
 *
 * ВАЖНО: функция мутирует $state и возвращает ['success'=>bool,'message'=>...].
 * Вызывается ИЗ ЦИКЛА movement_update_step (по ссылке) — безопасные мутации
 * элементов массива игроков там уже приняты практикой (processTraps).
 */
function rooms_teleport(&$state, $player_id, $to_room_id, $reason = 'exit') {
    $player = &$state['players'][$player_id];
    if (!$player) { unset($player); return ['success' => false, 'message' => 'Нет игрока']; }
    $from_room = $player['ROOM_ID'] ?? '';

    // Респаун: если комната спавна не задана — остаёмся там, где умерли
    // (прежнее поведение телепорта на START_POSITION_XY внутри своей карты).
    if ($reason === 'respawn' && ($to_room_id === '' || $to_room_id === null)) {
        $to_room_id = $from_room;
    }
    if ($to_room_id === '' || $to_room_id === null) { unset($player); return ['success' => false, 'message' => 'Неизвестная комната']; }

    // Та же комната — переносим только позицию (респаун на личную клетку старта).
    $same_room = ($from_room === $to_room_id);

    $room = room_get($state, $to_room_id);
    if ($room === null) {
        $state = world_log($state, "{$player_id}: переход в {$to_room_id} отклонён — карта комнаты не найдена");
        unset($player);
        return ['success' => false, 'message' => 'Комната не найдена'];
    }
    // Оседлать карту в state['rooms']: room_get грузит лениво из data/*.json,
    // но НЕ мутирует состояние — без этого шага комната «исчезала» между
    // вызовами (каждый следующий читатель снова тащил её с диска, а
    // saveBattleState писал в Redis только уже осевшие карты).
    if (!isset($state['rooms'][$to_room_id])) {
        $state['rooms'][$to_room_id] = $room;
    }
    // Ключ комнаты = её ID в состоянии (карты из state['rooms'] могут не иметь
    // поля room_id — берём переданный $to_room_id, он канонический).
    $room_key = $to_room_id;

    // Клетки-двери этой комнаты: по ним игрок УЖЕ стоит — вставать на саму
    // дверь нельзя (баг «рывок к exit»: серверная позиция на двери расходилась
    // с клиентской картинкой «рядом с дверью», и следующий ход начинался от
    // клетки exit). Предпочтительна обратная дверь (exit_rooms["x,y"] = ОТКУДА
    // пришли), иначе — любая exit-клетка комнаты, иначе центр карты (страховка).
    $door_cells = [];
    $is_exit = function ($xy) use ($room) {
        return isset($room['exits'][$xy]) || isset($room['exit_rooms'][$xy]);
    };
    if ($reason !== 'respawn') {
        $er = $room['exit_rooms'] ?? [];
        foreach ($er as $key => $rr) {
            if ($rr === $from_room) { $p = parseXY($key); $door_cells[] = [$p['x'], $p['y']]; }
        }
        if (!$door_cells) {
            foreach (array_keys($room['exits'] ?? []) as $key) {
                $p = parseXY($key); $door_cells[] = [$p['x'], $p['y']];
            }
        }
    }
    if (!$door_cells) $door_cells[] = [(int)floor(($room['width'] ?? GRID_WIDTH) / 2), (int)floor(($room['height'] ?? GRID_HEIGHT) / 2)];

    // ★ Исключаем из кандидатов ВСЕ двери комнаты: candidate-кольцо строится
    // вокруг первой двери, но вторая (например B1 имеет 4 угла-выхода) может
    // попасть в радиус 4 и стать «ближайшей свободной» — боец встал бы на чужую
    // дверь и при первом же тике получил бы повторный переход.
    $forbidden = [];
    foreach ($door_cells as $d) $forbidden[formatXY($d[0], $d[1])] = true;

    $spawn_xy = null;
    if ($reason === 'respawn') {
        // Респаун после смерти: появление на ВЫДЕЛЕННОЙ спавн-клетке своей
        // SPAWN_ROOM (личная START_POSITION_XY, если она здесь и свободна;
        // иначе ближайшая свободная клетка вокруг якоря/центра). Именно эта
        // правка лечит баг «игрок воскресает в координатах смерти»: раньше
        // ориентиром служила текущая позиция, а legacy-ветка turn_update_death_animations
        // вообще оставляла бойца там, где он погиб.
        $spawn_xy = room_player_spawn_cell($state, $room_key, $player_id);
    }
    if ($spawn_xy === null) {
        $spawn_xy = room_find_spawn_cell($state, $room, $door_cells[0][0], $door_cells[0][1], $player_id, $room_key, $forbidden, $is_exit);
    }
    if ($spawn_xy === null) {
        $state = world_log($state, "{$player_id}: переход в {$to_room_id} отменён — нет свободной клетки у двери");
        unset($player);
        return ['success' => false, 'message' => 'Нет свободной клетки'];
    }

    // Выписка из старой комнаты: движение обрывается навсегда.
    // ★ Клетка двери — мгновенная телепортация, а не путь по сетке: выдаём
    // «виртуальный» финал движения (MOVING с MOVE_END_TIME в прошлом). Тик
    // movement_update на следующем poll сам завершит его: снимет MOVING_* и
    // выставит IDLE (или восстановит STUNNED из активных NEGATIVES). Прямая
    // установка STATUS='IDLE' здесь была причиной двух багов: (а) игрок мог
    // остаться с висевшим STATUS='MOVING' без ходовых данных — клиент
    // блокировал клики и НЕ рисовал подсветку зон; (б) если бы статус
    // сбрасывался, но боец всё ещё стоял на клетке exit (см. ниже), первый же
    // клик возвращал бы спрайт к двери («рывок к exit»).
    $player['STATUS'] = 'MOVING';
    $player['MOVE_START_TIME'] = microtime(true);
    $player['MOVE_END_TIME'] = microtime(true);
    unset($player['MOVE_DISTANCE']);
    $player['ROOM_ID'] = $to_room_id;
    $player['CUR_POSITION_XY'] = $spawn_xy;
    $player['END_POSITION_XY'] = $spawn_xy;
    // ★ START_POSITION_XY синхронизируем с точкой появления. Он остаётся
    // якорем личной спавн-клетки в двух штатных случаях: (а) переход в чужую
    // комнату — там боец ещё не был и его личный «последний занятый» это вход
    // в новую комнату; (б) респаун на свою стартовую клетку — spawn_xy ===
    // START. В промежуточных состояниях (боец умер НЕ на своей стартовой,
    // стартовая занята) иначе получался бы «телепорт через всю карту» при
    // следующем ходе: клиент интерполирует от START к END внутри новой сетки.
    $player['START_POSITION_XY'] = $spawn_xy;
    unset($player['DEATH_TIME'], $player['DECAY_TIME']); // на всякий случай: вне цепочки смерти

    $state = world_log($state, "{$player_id}: {$reason} — переход {$from_room} → {$to_room_id} на клетку ({$spawn_xy})");
    emit($state, 'room.changed', [
        'player_id' => $player_id,
        'from' => $from_room,
        'to' => $to_room_id,
        'position' => $spawn_xy,
        'reason' => $reason,
    ]);
    unset($player);
    return ['success' => true, 'message' => "Переход в {$to_room_id}"];
}

/**
 * ★ Триггер exit: вызывается когда игрок оказался на клетке с exit
 * (тот же принцип, что LAND_MINE — нужно «наступить»). Exit постоянен:
 * после срабатывания остаётся в карте и доступен повторно всем.
 */
function rooms_check_exit_trigger(&$state, $player_id, $x, $y) {
    $player = $state['players'][$player_id] ?? null;
    if (!$player) return false;
    if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) return false;
    $room = room_get($state, $player['ROOM_ID'] ?? '');
    if ($room === null) return false;
    $dest = $room['exits'][formatXY($x, $y)] ?? null;
    if (!$dest) return false;
    $res = rooms_teleport($state, $player_id, $dest, 'exit');
    return !empty($res['success']);
}

/** Регистрация слушателей (пока никаких — событие room.changed доступно подписчикам в будущем) */
function rooms_register_hooks() {}
