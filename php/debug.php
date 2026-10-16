<?php
/**
 * debug.php - Показывает все ошибки PHP напрямую (без JSON)
 * Откройте эту страницу чтобы увидеть что именно ломается
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
        echo "Server info: " . $redis->info('server')['redis_version'] . "\n";
    } else {
        echo "❌ Cannot connect to Redis\n";
        echo "Make sure redis-server is running!\n";
    }
} catch (Exception $e) {
    echo "❌ Redis error: " . $e->getMessage() . "\n";
}

echo "\n=== File Paths ===\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "Current working dir: " . getcwd() . "\n\n";

$files = [
    'config.php' => __DIR__ . '/config.php',
    'battle.php' => __DIR__ . '/battle.php',
    'abilities.php' => __DIR__ . '/abilities.php',
    'items.php' => __DIR__ . '/items.php',
    'battledata.json' => __DIR__ . '/../data/battledata.json',
    'B1.json' => __DIR__ . '/../data/B1.json',
];

foreach ($files as $name => $path) {
    if (file_exists($path)) {
        echo "✅ $name => $path\n";
    } else {
        echo "❌ $name NOT FOUND at: $path\n";
    }
}

echo "\n=== Syntax Check ===\n";
foreach (['config.php', 'battle.php', 'abilities.php', 'items.php', 'init.php', 'poll.php', 'action.php'] as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $output = [];
        $return_var = 0;
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $return_var);
        $result = implode("\n", $output);
        if ($return_var === 0) {
            echo "✅ $file - syntax OK\n";
        } else {
            echo "❌ $file - SYNTAX ERROR:\n$result\n";
        }
    } else {
        echo "⚠️ $file - file not found\n";
    }
}

echo "\n=== Try loading config.php ===\n";
try {
    require_once __DIR__ . '/config.php';
    echo "✅ config.php loaded OK\n";
    echo "Constants defined: REDIS_HOST=" . REDIS_HOST . ", TURN_DURATION=" . TURN_DURATION . "\n";
} catch (Throwable $e) {
    echo "❌ config.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Try loading battle.php ===\n";
try {
    require_once __DIR__ . '/battle.php';
    echo "✅ battle.php loaded OK\n";
} catch (Throwable $e) {
    echo "❌ battle.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Try loading abilities.php ===\n";
try {
    require_once __DIR__ . '/abilities.php';
    echo "✅ abilities.php loaded OK\n";
} catch (Throwable $e) {
    echo "❌ abilities.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Try loading items.php ===\n";
try {
    require_once __DIR__ . '/items.php';
    echo "✅ items.php loaded OK\n";
} catch (Throwable $e) {
    echo "❌ items.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Try loading objects.php ===\n";
try {
    require_once __DIR__ . '/objects.php';
    echo "✅ objects.php loaded OK\n";
} catch (Throwable $e) {
    echo "❌ objects.php error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Try init.php logic ===\n";
try {
    $data_file = __DIR__ . '/../data/battledata.json';
    $json_content = file_get_contents($data_file);
    $battle_data = json_decode($json_content, true);
    if ($battle_data) {
        echo "✅ battledata.json parsed OK\n";
        echo "Players: " . implode(', ', array_keys($battle_data['players'])) . "\n";
    } else {
        echo "❌ battledata.json parse error: " . json_last_error_msg() . "\n";
    }
    
    $room_file = __DIR__ . '/../data/B1.json';
    $room_json = file_get_contents($room_file);
    $room_data = json_decode($room_json, true);
    if ($room_data) {
        echo "✅ B1.json parsed OK\n";
        echo "Room: " . $room_data['room_id'] . "\n";
    } else {
        echo "❌ B1.json parse error: " . json_last_error_msg() . "\n";
    }
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Full init.php test ===\n";
try {
    // Имитируем init.php
    $redis = getRedis();
    $redis->flushDB();
    echo "✅ Redis flushed\n";
    
    $state = [
        'players' => $battle_data['players'],
        'objects' => $battle_data['objects'] ?? [],
        'traps' => $battle_data['traps'] ?? [],
        'npcs' => $battle_data['npcs'] ?? [],
        'room' => $room_data,
        'turn_time' => time(),
        'projectiles' => [],
        'version' => 1,
        'log' => [['time' => time(), 'message' => 'test']]
    ];
    
    saveBattleState($state);
    echo "✅ State saved to Redis\n";
    
    $loaded = getBattleState();
    echo "✅ State loaded from Redis\n";
    echo "Players in Redis: " . count($loaded['players']) . "\n";
    
} catch (Throwable $e) {
    echo "❌ Full init error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<h2>Если все ✅ - вернитесь на <a href='game.html'>game.html</a></h2>";
