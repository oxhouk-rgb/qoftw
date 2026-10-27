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
 * Карта комнаты из состояния (ленивая загрузка из data/<ID>.json).
 * Возвращает null если комната неизвестна/файл битый — вызывающие обязаны
 * tolerate null (не ронять мир из-за отсутствующего файла).
 */
function room_get($state, $room_id) {
    if ($room_id === null || $room_id === '') return null;
    if (isset($state['rooms'][$room_id])) return $state['rooms'][$room_id];
    $path = __DIR__ . '/../../data/' . basename((string)$room_id) . '.json';
    if (!file_exists($path)) return null;
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
 */
function room_cell_blocked($state, $room, $x, $y) {
    if (!isValidPosition($x, $y)) return true;
    if ($room === null) return true; // комнаты нет — клетка недоступна
    $g = $room['grid'][$y][$x] ?? 0;
    if ((int)$g !== 1) return true;
    foreach ($state['objects'] as $obj) {
        if (($obj['ROOM_ID'] ?? null) !== ($room['room_id'] ?? null)) continue;
        if ($obj['STATUS'] === 'DESTROYED') continue;
        $pos = parseXY($obj['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return true;
    }
    foreach ($state['npcs'] as $npc) {
        if (($npc['ROOM_ID'] ?? null) !== ($room['room_id'] ?? null)) continue;
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
 *   - если ничего не найдено — возвращается null (вызывающий решает).
 */
function room_find_spawn_cell($state, $room, $door_x, $door_y, $exclude_pid) {
    if ($room === null) return null;
    foreach (room_candidate_cells($state, $room, $door_x, $door_y) as $c) {
        if (room_cell_blocked($state, $room, $c['x'], $c['y'])) continue;
        if (is_player_at_position($state, $c['x'], $c['y'], $exclude_pid, $room['room_id'] ?? null)) continue;
        return formatXY($c['x'], $c['y']);
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
 *   3) START_POSITION_XY обновляется (личная точка появления в новой комнате —
 *      её видит клиент и future-механики);
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
    if ($to_room_id === '' || $to_room_id === null) { unset($player); return ['success' => false, 'message' => 'Неизвестная комната']; }
    if ($from_room === $to_room_id) { unset($player); return ['success' => false, 'message' => 'Та же комната']; }

    $room = room_get($state, $to_room_id);
    if ($room === null) {
        $state = world_log($state, "{$player_id}: переход в {$to_room_id} отклонён — карта комнаты не найдена");
        unset($player);
        return ['success' => false, 'message' => 'Комната не найдена'];
    }

    // Точка появления у двери: предпочтительна из exit_rooms новой комнаты,
    // иначе — любая её exit-клетка, иначе центр карты (страховка).
    $door = null;
    if ($reason === 'respawn') {
        // Респаун после смерти: появление у личной START_POSITION_XY (см. ниже),
        // дверь как ориентир не нужна, но поиск свободной клетки идём от старта.
        $sp = parseXY($player['START_POSITION_XY'] ?? '0,0');
        $door = [$sp['x'], $sp['y']];
    } else {
        $er = $room['exit_rooms'] ?? [];
        foreach ($er as $key => $rr) {
            if ($rr === $from_room) { $p = parseXY($key); $door = [$p['x'], $p['y']]; break; }
        }
        if ($door === null) {
            foreach (array_keys($room['exits'] ?? []) as $key) {
                $p = parseXY($key); $door = [$p['x'], $p['y']]; break;
            }
        }
    }
    if ($door === null) $door = [(int)floor($room['width'] / 2), (int)floor($room['height'] / 2)];

    // Если личная стартовая клетка (респаун) свободна — встаём ровно на неё
    $spawn_xy = null;
    if ($reason === 'respawn') {
        $start = $player['START_POSITION_XY'] ?? null;
        if ($start !== null) {
            $sp = parseXY($start);
            if (!room_cell_blocked($state, $room, $sp['x'], $sp['y']) &&
                !is_player_at_position($state, $sp['x'], $sp['y'], $player_id, $to_room_id)) {
                $spawn_xy = $start;
            }
        }
    }
    if ($spawn_xy === null) {
        $spawn_xy = room_find_spawn_cell($state, $room, $door[0], $door[1], $player_id);
    }
    if ($spawn_xy === null) {
        $state = world_log($state, "{$player_id}: переход в {$to_room_id} отменён — нет свободной клетки у двери");
        unset($player);
        return ['success' => false, 'message' => 'Нет свободной клетки'];
    }

    // Выписка из старой комнаты: движение обрывается навсегда
    $player['STATUS'] = 'IDLE';
    unset($player['MOVE_START_TIME'], $player['MOVE_END_TIME'], $player['MOVE_DISTANCE']);
    $player['ROOM_ID'] = $to_room_id;
    $player['CUR_POSITION_XY'] = $spawn_xy;
    $player['START_POSITION_XY'] = $spawn_xy;
    $player['END_POSITION_XY'] = $spawn_xy;
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
