<?php
/**
 * engine/world_builder.php — ЕДИНСТВЕННЫЙ сборщик ИЗНАЧАЛЬНОГО состояния мира.
 *
 * ★ ОТВЕТ НА ВОПРОС «ГДЕ ТЕПЕРЬ НАЧАЛЬНОЕ СОСТОЯНИЕ МИРА, ЕСЛИ ХРАНИЛИЩЕ — REDIS?»
 * Redis — только рантайм-хранилище ЖИВОГО боя. Изначальное состояние мира в нём
 * не хранится и восстанавливается из двух источников:
 *   1) характеристики ТИПОВ объектов/ловушек/NPC — код: systems/objects.php
 *      → getObjectTemplates() (+ objectEntityDefaults / objects_build_entity);
 *   2) РАССТАНОВКА этих типов по клеткам — JSON-карты комнат data/<ID>.json
 *      (секции objects/traps/npcs), читаемые через room_get();
 *   3) бойцы — снимок стартовых характеристик data/initial_snapshot.json
 *      (секция players). Это ВРЕМЕННАЯ подставка под будущий аккаунт игрока:
 *      client (game.html) сегодня имитирует отправку персонажа в боевую
 *      сессию, а когда появится система экипировки — эти же поля начнёт
 *      присылать с аккаунта. Формат секции players = контракт, который
 *      принимает world_builder_normalize_player() (наследие упразднённого
 *      data/battledata.json — от файла отказались, формат полей сохранили).
 *
 * Отсюда — ВОСПРОИЗВОДИМОСТЬ: reset = перечитать (1)+(2)+(3) и записать мир
 * заново. Разрушенные бочки снова целы, мина снова INTACT, турель на месте,
 * игроки живы и стоят на стартовых клетках. Именно это и было сломано после
 * переезда в Redis: сид писался один раз, а «перезапуск сессии» читал тот же
 * снапшот конца прошлого боя.
 *
 * КТО ИСПОЛЬЗУЕТ:
 *   - tools/reset_world.php   (CLI-сброс мира; пишет результат в Redis);
 *   - tests/harness_engine.php (тот же путь поверх фейкового in-memory Redis);
 *   - php/init.php             (только player_defaults()/normalize_player() —
 *                               чтобы валидация состава игроков не жила в двух
 *                               местах; сам init мир НЕ пересобирает).
 *
 * ИНВАРИАНТЫ:
 *   - чистая функция: state не мутируется, файловых записей нет;
 *   - карты комнат читаются ЧЕРЕЗ room_get($state,...) — ленивый загрузчик
 *     systems/rooms.php (единственный владелец пути data/<ID>.json);
 *   - игрок обязан иметь ROOM_ID/SPAWN_ROOM: без них неизвестно, где он
 *     появляется (fail-fast вместо тихого спавна в «null»-комнате).
 */

require_once __DIR__ . '/../systems/objects.php'; // реестр шаблонов + objects_layout_from_room
require_once __DIR__ . '/../systems/rooms.php';   // room_get() — чтение карт комнат

/**
 * Дефолты бойца — то, что получает поле, НЕ указанное в снимке игроков
 * (data/initial_snapshot.json) или в присланной с клиента карточке персонажа.
 * Значения совпадают со стартовыми «пустыми» характеристиками движка, поэтому
 * пропущенное поле не превращается в undefined/null в рантайме.
 */
function world_builder_player_defaults() {
    return [
        'TURN' => 'RIGHT', 'STATUS' => 'IDLE', 'LIFE' => 3,
        'MAX_HITPOINTS' => 10, 'HITPOINTS' => 10, 'MAX_SHIELD' => 0, 'SHIELD' => 0,
        'ATTACK' => 2, 'DEFENCE' => 1, 'RESISTANCE' => 0, 'RANGE' => 3,
        'ABILITIES' => [], 'PASSIVES' => [], 'NEGATIVES' => [], 'ALLY' => 'NONE',
        'ACTIONS' => 2, 'MAX_ACTIONS' => 2,
    ];
}

/**
 * Известные комнаты из каталога data/*.json — статический контент (карты), а не
 * рантайм-данные, поэтому чтение каталога допустимо и здесь, и в reset-скрипте.
 * Нужно потому, что seed-файл перечисляет только бойцов: комнату-хаб с
 * расстановкой объектов/ловушек/NPC (B1) иначе никто не попросил бы загрузить.
 */
function world_builder_known_rooms() {
    $out = [];
    foreach ((array)glob(__DIR__ . '/../../data/*.json') as $f) {
        $base = basename($f, '.json');
        if ($base === 'initial_snapshot' || $base === 'SURRENDERED') continue;
        $out[] = $base;
    }
    sort($out);
    return $out;
}

/**
 * Список бойцов из seed-снимка → словарь по ID (NICKNAME/CHAR_ID как ключ).
 * @return array ['players'=>[..], 'errors'=>[..]] — ошибки копятся, чтобы
 *         diagnostic-вывод показал ВСЕ битые записи, а не только первую.
 */
function world_builder_index_players($seed) {
    $players = [];
    $errors = [];
    if (!is_array($seed)) return [$players, ['seed-снимок не является объектом']];
    if (!isset($seed['players']) || !is_array($seed['players']) || !$seed['players']) {
        return [$players, ['в seed-снимке отсутствует непустой список "players"']];
    }
    foreach ($seed['players'] as $i => $p) {
        if (!is_array($p)) { $errors[] = "players[$i]: не объект"; continue; }
        $id = $p['NICKNAME'] ?? ($p['ID'] ?? ($p['CHAR_ID'] ?? null));
        if (!$id) { $errors[] = "players[$i]: нет NICKNAME/ID"; continue; }
        $id = (string)$id;
        if (isset($players[$id])) { $errors[] = "игрок {$id}: дубликат записи"; continue; }
        $players[$id] = $p;
    }
    return [$players, $errors];
}

/**
 * Собрать бойца: дефолты ← нормализация координат ← overrides из снимка.
 * Публичен: init.php использует его для проверки уже лежащего в Redis мира.
 *
 * ★ КЛЕТКА ПОЯВЛЕНИЯ ОПРЕДЕЛЯЕТСЯ ОДИН РАЗ И ПИСЕТСЯ ВО ВСЕ ТРИ ПОЛЯ.
 * Причина правки (баг «первый клик телепортирует игрока»): раньше здесь
 * сохранялась позиция из снимка (CUR_POSITION_XY), а движок при первом же
 * ходе брал направление и маршрут от якоря спавн-клетки (START_POSITION_XY,
 * rules.php::getDirectionFromPlayer + movement.php). Два разных источника
 * давали две разные клетки: спрайт стоял на «1,1», сервер считал, что боец
 * на «8,6», и первое движение начиналось рывком-телепортом к серверной
 * клетке. Теперь стартовая клетка считается тем же кодом, что и респаун
 * (room_initial_spawn_cell → spawn_cells карты комнаты), и CUR/START/END
 * всегда равны ей, пока боец не сделал ход.
 *
 * @param array $raw          запись снимка players (или готовый боец из Redis)
 * @param array $room_map     карта room_id => карта комнаты (для спавн-клетки)
 * @param bool  $keep_stored  true — боец уже лежит в Redis: брать его поля как
 *                            есть, НЕ пересчитывать клетку (иначе каждый вход
 *                            игрока сдвигал бы живого бойца к точке спавна).
 *                            false (сборка мира) — клетка вычисляется заново.
 */
function world_builder_normalize_player($raw, $room_map = [], $keep_stored = false) {
    $id = (string)($raw['ID'] ?? ($raw['NICKNAME'] ?? ($raw['CHAR_ID'] ?? '')));
    $stored = ($raw['CUR_POSITION_XY'] ?? '') ?: ($raw['POSITION_XY'] ?? '');
    $spawn_room = $raw['SPAWN_ROOM'] ?? '';
    $room_id = $raw['ROOM_ID'] ?? '';

    if ($keep_stored) {
        // Рантайм-путь: позиция бойца принадлежит живому бою, а не данным сборки.
        $cell = $stored;
    } else {
        // Сборка мира: единое правило стартовой клетки (spawn_cells карты,
        // иначе позиция из снимка, иначе ближайшее свободное кольцо/центр).
        $cell = room_initial_spawn_cell(['rooms' => $room_map, 'players' => []],
                                        $room_id, $id, $stored);
        if ($cell === null || $cell === '') $cell = $stored;
    }
    $anchor = ($keep_stored && ($raw['START_POSITION_XY'] ?? '') !== '')
        ? $raw['START_POSITION_XY'] : $cell;
    $end = ($keep_stored && ($raw['END_POSITION_XY'] ?? '') !== '')
        ? $raw['END_POSITION_XY'] : $cell;

    $player = world_builder_player_defaults();
    $player = array_merge($player, [
        'ID' => $id,
        'NICKNAME' => $raw['NICKNAME'] ?? $id,
        'ROOM_ID' => $room_id,
        'SPAWN_ROOM' => $spawn_room,
        'CUR_POSITION_XY' => $cell,
        'START_POSITION_XY' => $anchor,
        'END_POSITION_XY' => $end,
        // ★ ВРЕМЯ СТАРТУЕТ НУЛЕМ, а не microtime(true): эти поля попадают в
        // сериализованный снапшот игрока (battle:players). Когда сборка мира
        // повторяется (перезапуск сессии → второй вызов world_build_initial),
        // живое microtime даёт ДРУГУЮ строку JSON — и проверка «сброс точно
        // воспроизводит изначальное состояние» падала бы на ровном месте.
        // Нулевой пары достаточно: движение начинается только в movement.php,
        // который всегда проставляет оба значения сам (plus tick-логика
        // finalizes при now >= MOVE_END_TIME, а 0 < now — финал мгновенный).
        'MOVE_START_TIME' => 0,
        'MOVE_END_TIME' => 0,
    ]);
    foreach ($raw as $k => $v) {
        if ($k === 'POSITION_XY') continue; // легасяное имя клетки уже разложено выше
        $player[$k] = $v;
    }
    $player['ID'] = $id;
    // ★ Ключевые поля позиции НЕ перезаписываем снимком: клетка вычислена выше
    // (spawn_cells карты комнаты) и должна остаться единой для CUR/START/END.
    // Иначе запись вроде "CUR_POSITION_XY":"1,1" из снимка снова разъезжается с
    // серверной спавн-клеткой "8,6" — тот самый баг «первый клик телепортирует».
    // POSITION_XY выбрасывается как легасяное имя той же клетки.
    foreach (['POSITION_XY', 'CUR_POSITION_XY', 'START_POSITION_XY', 'END_POSITION_XY'] as $f) {
        unset($player[$f]);
    }
    $player['CUR_POSITION_XY']   = $cell;
    $player['START_POSITION_XY'] = $anchor;
    $player['END_POSITION_XY']   = $end;
    return $player;
}

/**
 * Проверка собранного бойца на обязательные поля (fail-fast список ошибок).
 */
function world_builder_player_errors($id, $player) {
    $errors = [];
    if (($player['ROOM_ID'] ?? '') === '') {
        $errors[] = "игрок {$id}: нет ROOM_ID — неизвестно, где он появляется";
    }
    if (($player['SPAWN_ROOM'] ?? '') === '') {
        $errors[] = "игрок {$id}: нет SPAWN_ROOM — респаун после смерти некуда";
    }
    foreach (['CUR_POSITION_XY', 'START_POSITION_XY'] as $f) {
        $v = $player[$f] ?? '';
        if (!is_string($v) || !preg_match('/^\d+,\d+$/', $v)) {
            $errors[] = "игрок {$id}: поле {$f} = " . var_export($v, true) . " (ожидается \"x,y\")";
        }
    }
    foreach (['ABILITIES', 'PASSIVES', 'NEGATIVES'] as $f) {
        if (!is_array($player[$f] ?? null)) $errors[] = "игрок {$id}: {$f} не массив";
    }
    return $errors;
}

/**
 * ★ Полный сброс мира к ИЗНАЧАЛЬНОМУ состоянию.
 *
 * @param array $seed_snapshot содержимое data/initial_snapshot.json (секция players)
 * @param array $base_state    состояние с уже загруженными картами комнат
 *                             (достаточно ['rooms'=>['B1'=>$card,...]])
 * @return array ['state'=>[...], 'errors'=>[...]] — при непустом errors state
 *         неполон (вызывающий обязан отказаться сохранять мир).
 */
function world_build_initial($seed_snapshot, $base_state = []) {
    $errors = [];
    $now = time();

    // ── 1) Бойцы ────────────────────────────────────────────────────────────
    list($raw_players, $errs) = world_builder_index_players($seed_snapshot);
    $errors = array_merge($errors, $errs);

    // ── 2) Карты комнат: legacy + все комнаты бойцов ────────────────────────
    $room_ids = [];
    foreach ($raw_players as $p) {
        foreach (['ROOM_ID', 'SPAWN_ROOM'] as $f) {
            $rid = $p[$f] ?? '';
            if (is_string($rid) && $rid !== '' && !in_array($rid, $room_ids, true)) {
                $room_ids[] = $rid;
            }
        }
    }
    $room_map = [];
    // Комнату сущностей (хаб B1) seed-файл не упоминает: добавим все комнаты,
    // известные из каталога data/*.json — лишний read файла дешевле, чем
    // потерянная расстановка (см. «Где изначальное состояние» в шапке файла).
    foreach (world_builder_known_rooms() as $rid2) {
        if (!in_array($rid2, $room_ids, true)) $room_ids[] = $rid2;
    }
    foreach ($room_ids as $rid) {
        $card = room_get($base_state, $rid);
        if ($card === null) {
            $errors[] = "карта комнаты {$rid} не найдена/бита (data/{$rid}.json)";
            continue;
        }
        $room_map[$rid] = $card;
    }
    // «legacy-комната» state['room'] — комната с расстановкой сущностей, иначе
    // первая player-комната (то же правило, что делал старый init.php).
    $entity_room = null;
    foreach ($room_map as $rid => $card) {
        foreach (['objects', 'traps', 'npcs'] as $sec) {
            if (!empty($card[$sec])) { $entity_room = $rid; break 2; }
        }
    }
    $legacy_id = $entity_room ?? ($room_ids[0] ?? '');
    if ($legacy_id === '') $errors[] = 'не удалось определить комнату (нет ни одной карты)';

    // ── 3) Нормализация бойцов (спавн-клетки уже известны) ──────────────────
    $players = [];
    foreach ($raw_players as $id => $raw) {
        $player = world_builder_normalize_player($raw, $room_map);
        $pe = world_builder_player_errors($id, $player);
        if ($pe) { $errors = array_merge($errors, $pe); continue; }
        $players[$id] = $player;
    }
    if (!$players) $errors[] = 'мир не собран: нет ни одного корректного бойца';

    // ── 4) Расстановка объектов/ловушек/NPC ИЗ КАРТ КОМНАТ ──────────────────
    // Единый источник: секции objects/traps/npcs файлов data/<ID>.json +
    // характеристики из реестра getObjectTemplates().
    $objects = $traps = $npcs = [];
    foreach ($room_map as $rid => $card) {
        $layout = objects_layout_from_room($card, $rid);
        foreach ($layout['objects'] as $oid => $e) {
            if (isset($objects[$oid])) $errors[] = "объект {$oid} дублируется (комната {$rid})";
            $objects[$oid] = $e;
        }
        foreach ($layout['traps'] as $tid => $e) {
            if (isset($traps[$tid])) $errors[] = "ловушка {$tid} дублируется (комната {$rid})";
            $traps[$tid] = $e;
        }
        foreach ($layout['npcs'] as $nid => $e) {
            if (isset($npcs[$nid])) $errors[] = "NPC {$nid} дублируется (комната {$rid})";
            $npcs[$nid] = $e;
        }
    }

    // ── 5) Опциональный SURRENDERED.json — перезапуск после поражения ───────
    // (полностью сохранено поведение старого init.php: патч полей игроков)
    $surrendered_path = __DIR__ . '/../../data/SURRENDERED.json';
    if (is_file($surrendered_path)) {
        $patch_all = json_decode((string)file_get_contents($surrendered_path), true);
        if (is_array($patch_all)) {
            foreach ($patch_all as $nick => $patch) {
                if (isset($players[$nick]) && is_array($patch)) {
                    $players[$nick] = array_merge($players[$nick], $patch);
                    $players[$nick]['ID'] = $nick;
                }
            }
        }
    }

    $state = $base_state;
    $state['players'] = $players;
    $state['objects'] = $objects;
    $state['traps'] = $traps;
    $state['npcs'] = $npcs;
    $state['projectiles'] = [];
    $state['turn_time'] = $now;
    $state['log'] = [['time' => $now, 'message' => 'Мир собран из кода + карт комнат (tools/reset_world.php)']];
    if ($legacy_id !== '' && isset($room_map[$legacy_id])) {
        $state['room'] = $room_map[$legacy_id];
    }
    $state['rooms'] = $room_map;

    // ── 6) Зоны угрозы NPC (как делал init.php после сборки state) ───────────
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH'], true)) continue;
        $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
        $state = world_log($state, "NPC {$npc_id}: зона угрозы вычислена ("
            . count($npc['THREAT_ZONE']) . " клеток)");
    }
    unset($npc);

    return ['state' => $state, 'errors' => $errors];
}

/**
 * ★ Публичный алиас для HTTP-слоя (init.php): дефолты бойца. Держим одну точку
 * определения — иначе валидация состава игроков в init.php и сборка мира в
 * reset-скрипте живут в двух местах и расходятся при первой же правке полей.
 */
function player_defaults() { return world_builder_player_defaults(); }

/**
 * ★ Публичный алиас: нормализация бойца (снимок/карточка клиента → поля state).
 */
function normalize_player($raw, $room_map = []) {
    return world_builder_normalize_player($raw, $room_map);
}

/**
 * Прочитать ИЗНАЧАЛЬНЫЙ снимок бойцов с диска. Единственная точка, где путь к
 * файлу определена — и reset-скрипт, и тесты идут через неё (regression-защита
 * от «переименовали файл — сид молча собирает пустой мир»).
 * @return array [$players_list|false, error|null]
 */
function world_builder_read_snapshot($path = null) {
    if ($path === null) $path = __DIR__ . '/../../data/initial_snapshot.json';
    if (!is_file($path)) return [false, "Снимок стартовых бойцов не найден: {$path}"];
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) return [false, "Снимок битый (JSON): {$path} — " . json_last_error_msg()];
    if (!isset($data['players']) || !is_array($data['players'])) {
        return [false, 'В снимке отсутствует секция players: ' . $path];
    }
    return [$data['players'], null];
}

/**
 * ★ Собрать ОДНОГО бойца из карточки, присланной клиентом (game.html сегодня
 * отправляет сюда данные, которые в будущем придут с аккаунта). Возвращает
 * ['player'=>array|null, 'errors'=>[...]] — при ошибках player=null, вызывающий
 * обязан отказаться применять такую карточку (не чинить её молча: состав боя
 * определяет сервер, а не произвольный запрос).
 */
function world_build_player_from_payload($raw, $room_map = []) {
    if (!is_array($raw)) return ['player' => null, 'errors' => ['карточка бойца не является объектом']];
    $id = (string)($raw['ID'] ?? ($raw['NICKNAME'] ?? ($raw['CHAR_ID'] ?? '')));
    if ($id === '') return ['player' => null, 'errors' => ['в карточке бойца нет NICKNAME/ID']];
    $player = world_builder_normalize_player($raw, $room_map);
    $errors = world_builder_player_errors($id, $player);
    if ($errors) return ['player' => null, 'errors' => $errors];
    return ['player' => $player, 'errors' => []];
}
