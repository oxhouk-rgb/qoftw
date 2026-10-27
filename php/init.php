<?php
/**
 * init.php — Инициализация боя (тонкий слой HTTP над движком).
 *
 * Формат данных (КАНОН, сверено с data/battledata.json):
 *   battledata.json = { players:{NICK:{...}}, objects:{ID:{...}}, traps:{ID:{...}}, npcs:{ID:{...}} }
 *   SURRENDERED     = { NICK:{...} }              — опционально, бойцы, сдавшиеся в прошлом бою
 *   комната         = data/<room_id>.json          — только карта: room_id,width,height,grid,exits,stairs
 *
 * Собирает состояние и сохраняет через world_init() (flushDB + save c инкрементом версии).
 * Форматом состояния владеет engine/world.php, правилами — engine/rules.php.
 */

require_once __DIR__ . '/engine/bootstrap.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Проверяем расширение Redis
    if (!extension_loaded('redis')) {
        jsonError('Расширение Redis не установлено. Выполните: pecl install redis');
    }

    $battle_data_path = __DIR__ . '/../data/battledata.json';
    if (!file_exists($battle_data_path)) {
        jsonError('Файл battledata.json не найден по пути: ' . $battle_data_path);
    }

    $json_content = file_get_contents($battle_data_path);
    if ($json_content === false) {
        jsonError('Не удалось прочитать файл battledata.json');
    }

    $battle_data = json_decode($json_content, true);
    if (!$battle_data || !isset($battle_data['players'])) {
        jsonError('Ошибка парсинга JSON в battledata.json или отсутствует секция "players": ' . json_last_error_msg());
    }

    // ★ Мультикомнатный мир: battledata.json описывает бойцов в их стартовых
    // комнатах (A1/A5/E1/E5), но общий центр боя — B1 (бочки/мины/турель).
    // state['room'] — legacy-карта единой карты (её читают debug-эндпоинты и
    // вызовы без room_id); берём её из комнаты с объектами/ловушками/NPC, а
    // при отсутствии таких — из первой player-комнаты.
    $entity_room = null;
    foreach (['objects', 'traps', 'npcs'] as $sec) {
        foreach (($battle_data[$sec] ?? []) as $edata) {
            if (!empty($edata['ROOM_ID'])) { $entity_room = $edata['ROOM_ID']; break 2; }
        }
    }
    $first_player = reset($battle_data['players']);
    $legacy_room_id = $entity_room ?: ($first_player['ROOM_ID'] ?? '');
    $room_id = $legacy_room_id;
    if ($room_id === '') {
        jsonError('В battledata.json у игроков отсутствует ROOM_ID');
    }

    $room_file = $room_id . '.json';
    $room_path = __DIR__ . '/../data/' . $room_file;

    if (!file_exists($room_path)) {
        jsonError('Файл комнаты не найден: ' . $room_path);
    }

    $room_content = file_get_contents($room_path);
    if ($room_content === false) {
        jsonError('Не удалось прочитать файл комнаты: ' . $room_path);
    }

    $room_data = json_decode($room_content, true);
    if (!$room_data) {
        jsonError('Ошибка парсинга JSON комнаты: ' . $room_file);
    }

    // Опциональный файл сдавшихся бойцов (перезапуск после поражения)
    $surrendered_path = __DIR__ . '/../data/SURRENDERED.json';
    $surrendered = [];
    if (file_exists($surrendered_path)) {
        $surrendered_content = file_get_contents($surrendered_path);
        $decoded = json_decode((string)$surrendered_content, true);
        if (is_array($decoded)) {
            $surrendered = $decoded;
        }
    }

    // Игроки: ключ массива = nickname (его шлёт клиент в action.php как player_id)
    $players = [];
    foreach ($battle_data['players'] as $nickname => $pdata) {
        $pos = $pdata['CUR_POSITION_XY'] ?? ($pdata['START_POSITION_XY'] ?? '0,0');
        $players[$nickname] = array_merge([
            'ID' => $nickname,
            'CUR_POSITION_XY' => $pos,
            'START_POSITION_XY' => $pos,
            'END_POSITION_XY' => $pos,
            'TURN' => 'RIGHT',
            'STATUS' => 'IDLE',
            'LIFE' => 3,
            'MAX_HITPOINTS' => 10,
            'HITPOINTS' => 10,
            'MAX_SHIELD' => 0,
            'SHIELD' => 0,
            'ATTACK' => 2,
            'DEFENCE' => 1,
            'RESISTANCE' => 0,
            'RANGE' => 3,
            'ABILITIES' => [],
            'PASSIVES' => [],
            'NEGATIVES' => [],
            'ALLY' => 'NONE',
            'ACTIONS' => 2,
            'MAX_ACTIONS' => 2,
            'MOVE_START_TIME' => microtime(true),
            'MOVE_END_TIME' => microtime(true),
        ], $pdata);
        $players[$nickname]['ID'] = $nickname;

        // Если боец сдавился в прошлом бою — стартуем с его сохранённым состоянием
        if (isset($surrendered[$nickname])) {
            $players[$nickname] = array_merge($players[$nickname], $surrendered[$nickname]);
        }
    }

    // Объекты / ловушки / NPC — прямо из секций battledata.json (канонический формат)
    $objects = [];
    foreach (($battle_data['objects'] ?? []) as $obj_id => $odata) {
        $objects[$obj_id] = array_merge([
            'ID' => $obj_id,
            'IMAGE' => '',
            'POSITION_XY' => '0,0',
            'STATUS' => 'INTACT',
            'HITPOINTS' => 1,
            'ALLY' => 'NONE',
        ], $odata);
        $objects[$obj_id]['ID'] = $obj_id;
    }

    $traps = [];
    foreach (($battle_data['traps'] ?? []) as $trap_id => $tdata) {
        $effect = $tdata['EFFECT'] ?? [];
        if (is_string($effect)) {
            $effect = [$effect];
        }
        $traps[$trap_id] = array_merge([
            'ID' => $trap_id,
            'IMAGE' => '',
            'POSITION_XY' => '0,0',
            'STATUS' => 'INTACT',
            'DAMAGE' => 1,
            'RANGE' => 1,
            'EFFECT' => $effect,
            'HITPOINTS' => 1,
            'ALLY' => 'NONE',
        ], $tdata);
        $traps[$trap_id]['ID'] = $trap_id;
        $traps[$trap_id]['EFFECT'] = $effect;
    }

    $npcs = [];
    foreach (($battle_data['npcs'] ?? []) as $npc_id => $ndata) {
        $npcs[$npc_id] = array_merge([
            'ID' => $npc_id,
            'IMAGE' => '',
            'POSITION_XY' => '0,0',
            'STATUS' => 'IDLE',
            'HITPOINTS' => 5,
            'MAX_HITPOINTS' => 5,
            'ATTACK' => 2,
            'RANGE' => 4,
            'CUR_TARGET' => 'NONE',
            'ALLY' => 'NONE',
            'ACTIONS' => 1,
            'MAX_ACTIONS' => 1,
        ], $ndata);
        $npcs[$npc_id]['ID'] = $npc_id;
    }

    $state = [
        'room' => $room_data,
        // ★ Мультикомнатный словарь: кладём legacy-комнату (в ней объекты/
        // ловушки/NPC) и стартовые комнаты всех игроков — иначе poll для игрока
        // в A1 получил бы null-карту до первого телепорта (движок терпит null,
        // но клиенту показывать нечего). Остальные комнаты грузятся лениво.
        'rooms' => [],
        'objects' => $objects,
        'traps' => $traps,
        'npcs' => $npcs,
        'players' => $players,
        'projectiles' => [],
        'log' => [],
        'turn_time' => time(),
    ];
    $initial_rooms = array_unique(array_filter(
        array_merge([$legacy_room_id], array_column($players, 'ROOM_ID'))
    ));
    foreach ($initial_rooms as $rid) {
        $card = room_get($state, $rid);
        if ($card !== null) $state['rooms'][$rid] = $card;
    }

    // Зоны угрозы NPC (нужны предсказанию движения) — карты комнат уже в state
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if ($npc['STATUS'] !== 'DESTROYED' && $npc['STATUS'] !== 'DEATH') {
            $nroom = $npc['ROOM_ID'] ?? null;
            if ($nroom !== null && $nroom !== '' && !isset($state['rooms'][$nroom])) {
                $card = room_get($state, $nroom);
                if ($card !== null) $state['rooms'][$nroom] = $card;
            }
            $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
            $state = world_log($state, "NPC {$npc_id}: зона угрозы вычислена (" . count($npc['THREAT_ZONE']) . " клеток)");
        }
    }
    unset($npc);

    // Единая точка записи мира (flushDB + save + версия)
    world_init($state);

    closeRedis();

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Бой инициализирован',
        'room' => $room_id,
        'rules' => rules_client_payload(),
        'abilities' => getAbilityDefinitions(),
        'autonomous_objects' => getAutonomousObjectDefinitions(),
        'passives' => getPassiveDefinitions(),
        'effects' => getEffectDefinitions(),
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Критическая ошибка инициализации: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
