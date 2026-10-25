<?php
/**
 * debug.php — Диагностика окружения и движка (сырой вывод, без JSON).
 *
 * Откройте эту страницу чтобы увидеть что именно ломается: PHP/Redis,
 * наличие файлов, синтаксис модулей, загрузка bootstrap-а, полный цикл
 * init → save → load через engine/world.
 *
 * ВАЖНО: этот файл намеренно НЕ использует output buffering; flushDB
 * выполняется только в самом конце, в разделе «Full engine test».
 */

// Включаем ВСЕ ошибки
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

echo "<h1>🔧 PHP Debug</h1>";
echo "<pre>";

echo "=== PHP Version ===\n";
echo phpversion() . "\n\n";

echo "=== Redis Extension ===\n";
if (extension_loaded('redis')) {
    echo "✅ Redis extension loaded\n";
    echo "Redis version: " . phpversion('redis') . "\n";
} else {
    echo "❌ Redis extension NOT loaded!\n";
    echo "Check php.ini for: extension=php_redis.dll (Windows) or extension=redis.so (Linux)\n";
}

echo "\n=== Redis Connection ===\n";
try {
    $redis = new Redis();
    $connected = $redis->connect('127.0.0.1', 6379, 2);
    if ($connected) {
        echo "✅ Connected to Redis\n";
        $info = $redis->info('server');
        echo "Server info: " . ($info['redis_version'] ?? '?') . "\n";
    } else {
        echo "❌ Cannot connect to Redis\n";
        echo "Make sure redis-server is running!\n";
    }
} catch (Throwable $e) {
    echo "❌ Redis error: " . $e->getMessage() . "\n";
}

echo "\n=== File Paths ===\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "Current working dir: " . getcwd() . "\n\n";

$files = [
    // HTTP-эндпоинты
    'config.php' => __DIR__ . '/config.php',
    'battle.php' => __DIR__ . '/battle.php',
    'init.php' => __DIR__ . '/init.php',
    'poll.php' => __DIR__ . '/poll.php',
    'action.php' => __DIR__ . '/action.php',
    // Ядро движка
    'engine/rules.php' => __DIR__ . '/engine/rules.php',
    'engine/geometry.php' => __DIR__ . '/engine/geometry.php',
    'engine/events.php' => __DIR__ . '/engine/events.php',
    'engine/world.php' => __DIR__ . '/engine/world.php',
    'engine/turn.php' => __DIR__ . '/engine/turn.php',
    'engine/bootstrap.php' => __DIR__ . '/engine/bootstrap.php',
    // Системы
    'systems/effects.php' => __DIR__ . '/systems/effects.php',
    'systems/targeting.php' => __DIR__ . '/systems/targeting.php',
    'systems/passives.php' => __DIR__ . '/systems/passives.php',
    'systems/movement.php' => __DIR__ . '/systems/movement.php',
    'systems/combat.php' => __DIR__ . '/systems/combat.php',
    'systems/abilities.php' => __DIR__ . '/systems/abilities.php',
    'systems/objects.php' => __DIR__ . '/systems/objects.php',
    // Данные
    'battledata.json' => __DIR__ . '/../data/battledata.json',
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "✅ $name => $path\n";
    } else {
        echo "❌ $name NOT FOUND at: $path\n";
    }
}

echo "\n=== Syntax Check ===\n";
foreach (array_keys($files) as $rel) {
    if (!str_ends_with($rel, '.php')) continue;
    $path = __DIR__ . '/' . $rel;
    if (file_exists($path)) {
        $output = [];
        $return_var = 0;
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $return_var);
        $result = implode("\n", $output);
        if ($return_var === 0) {
            echo "✅ $rel - syntax OK\n";
        } else {
            echo "❌ $rel - SYNTAX ERROR:\n$result\n";
        }
    } else {
        echo "⚠️ $rel - file not found\n";
    }
}

echo "\n=== Try loading config.php ===\n";
try {
    require_once __DIR__ . '/config.php';
    echo "✅ config.php loaded OK\n";
    echo "Constants defined: REDIS_HOST=" . REDIS_HOST . "\n";
} catch (Throwable $e) {
    echo "❌ config.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Try loading battle.php (facade -> engine/bootstrap) ===\n";
try {
    require_once __DIR__ . '/battle.php';
    echo "✅ battle.php loaded OK\n";
    echo "Engine constants: GRID=" . GRID_WIDTH . "x" . GRID_HEIGHT
        . ", TURN_DURATION=" . TURN_DURATION . ", MOVE_SPEED=" . MOVE_SPEED . "\n";
} catch (Throwable $e) {
    echo "❌ battle.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Engine registries ===\n";
try {
    echo "✅ Abilities: " . implode(', ', array_keys(getAbilityDefinitions())) . "\n";
    echo "✅ Passives: " . implode(', ', array_keys(getPassiveDefinitions())) . "\n";
    echo "✅ Effects: " . implode(', ', array_keys(getEffectDefinitions())) . "\n";
    echo "✅ Autonomous objects: " . implode(', ', array_keys(getAutonomousObjectDefinitions())) . "\n";
} catch (Throwable $e) {
    echo "❌ Engine registry error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Data files parse ===\n";
$battle_data = null;
$room_data = null;
try {
    $data_file = __DIR__ . '/../data/battledata.json';
    $json_content = file_get_contents($data_file);
    $battle_data = json_decode($json_content, true);
    if ($battle_data) {
        echo "✅ battledata.json parsed OK\n";
        echo "ROOM: " . ($battle_data['ROOM'] ?? '?') . "\n";
        echo "UNITS: " . implode(', ', array_keys($battle_data['UNITS'] ?? [])) . "\n";
    } else {
        echo "❌ battledata.json parse error: " . json_last_error_msg() . "\n";
    }

    $room_file = __DIR__ . '/../data/' . ($battle_data['ROOM'] ?? 'B1.json');
    $room_json = @file_get_contents($room_file);
    $room_data = $room_json !== false ? json_decode($room_json, true) : null;
    if ($room_data) {
        echo "✅ " . basename($room_file) . " parsed OK\n";
    } else {
        echo "❌ Room file не найден/не распарсен: $room_file\n";
    }
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Full engine test (world_init via engine/world.php) ===\n";
try {
    if (!$battle_data || !$room_data) {
        echo "⚠️ Пропущено: данные не загружены выше\n";
    } else {
        // Минимальная сборка состояния (как в init.php, сокращённо)
        $players = [];
        foreach ($battle_data['UNITS'] as $unit_name => $unit_data) {
            $position = $unit_data['POSITION_XY'] ?? '0,0';
            $players[$unit_name] = [
                'ID' => $unit_name,
                'CUR_POSITION_XY' => $position,
                'START_POSITION_XY' => $position,
                'END_POSITION_XY' => $position,
                'TURN' => $unit_data['TURN'] ?? 'RIGHT',
                'STATUS' => 'IDLE',
                'LIFE' => $unit_data['LIFE'] ?? 3,
                'MAX_HITPOINTS' => $unit_data['HP'] ?? 10,
                'HITPOINTS' => $unit_data['HP'] ?? 10,
                'MAX_SHIELD' => $unit_data['SHIELD'] ?? 0,
                'SHIELD' => $unit_data['SHIELD'] ?? 0,
                'ATTACK' => $unit_data['ATTACK'] ?? 2,
                'DEFENCE' => $unit_data['DEFENCE'] ?? 1,
                'RESISTANCE' => $unit_data['RESISTANCE'] ?? 0,
                'RANGE' => $unit_data['RANGE'] ?? 3,
                'ABILITIES' => $unit_data['ABILITIES'] ?? [],
                'PASSIVES' => $unit_data['PASSIVES'] ?? [],
                'NEGATIVES' => [],
                'ALLY' => $unit_data['ALLY'] ?? 'NONE',
                'ACTIONS' => 2,
                'MAX_ACTIONS' => 2,
                'MOVE_START_TIME' => microtime(true),
                'MOVE_END_TIME' => microtime(true),
            ];
        }

        $state = [
            'room' => $room_data,
            'objects' => [],
            'traps' => [],
            'npcs' => [],
            'players' => $players,
            'projectiles' => [],
            'log' => [],
            'turn_time' => time(),
        ];

        world_init($state); // ⚠️ как раньше: перезаписывает текущий бой в Redis
        echo "✅ world_init() OK (flushDB + save + версия)\n";

        $loaded = world_load();
        echo "✅ world_load() OK\n";
        echo "Players in Redis: " . count($loaded['players']) . "\n";
        echo "Version: " . world_version() . "\n";

        // Быстрая проверка цикла хода без записи
        turn_tick($loaded);
        echo "✅ turn_tick() отработал без ошибок\n";
    }
} catch (Throwable $e) {
    echo "❌ Full engine test error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<h2>Если все ✅ - вернитесь на <a href='../game.html'>game.html</a></h2>";
