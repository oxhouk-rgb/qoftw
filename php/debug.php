<?php
/**
 * debug.php — Диагностика окружения и движка (сырой вывод, без JSON).
 *
 * Откройте эту страницу чтобы увидеть что именно ломается: PHP/Redis,
 * наличие файлов, синтаксис модулей, загрузка bootstrap-а, статические данные
 * мира (карты комнат + снимок бойцов) и round-trip живого мира через Redis.
 *
 * ВАЖНО: этот файл намеренно НЕ использует output buffering и НЕ пишет мир:
 * проверяются только чтение статики и world_load()/turn_tick() без сохранения.
 * Полный сброс мира — вне HTTP: `php tools/reset_world.php`.
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
    // Ядро движка: сборка ИЗНАЧАЛЬНОГО состояния мира
    'engine/world_builder.php' => __DIR__ . '/engine/world_builder.php',
    'systems/rooms.php' => __DIR__ . '/systems/rooms.php',
    // Инструмент вне HTTP-рантайма (сброс мира в Redis)
    '../tools/reset_world.php' => __DIR__ . '/../tools/reset_world.php',
    // Данные: СТАТИКА. Файл data/battledata.json упразднён — рантайм-состояние
    // боя живёт в Redis, здесь только карты комнат и снимок стартовых бойцов.
    '../data/initial_snapshot.json' => __DIR__ . '/../data/initial_snapshot.json',
    '../data/B1.json' => __DIR__ . '/../data/B1.json',
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

echo "\n=== Static data (ИЗНАЧАЛЬНОЕ состояние мира: код + карты комнат + снимок бойцов) ===\n";
try {
    // world_builder НЕ входит в рантайм-загрузку движка (сборка мира — операция
    // вне игрового тика), поэтому диагностика подключает его сама.
    require_once __DIR__ . '/engine/world_builder.php';
    list($snap_players, $snap_err) = world_builder_read_snapshot();
    if ($snap_err !== null) {
        echo "❌ initial_snapshot.json: " . $snap_err . "\n";
    } else {
        echo "✅ initial_snapshot.json: бойцов=" . count($snap_players)
            . " (" . implode(', ', array_column($snap_players, 'NICKNAME')) . ")\n";
    }
    foreach (world_builder_known_rooms() as $rid) {
        $card = room_read_static_card($rid);
        if ($card === null) {
            echo "❌ комната {$rid}: нет валидной карты data/{$rid}.json\n";
            continue;
        }
        $lay = objects_layout_from_room($card, $rid);
        echo "✅ " . str_pad($rid, 3) . ": сетка " . ($card['width'] ?? 0) . "x" . ($card['height'] ?? 0)
            . ", выходов=" . count($card['exits'] ?? [])
            . ", спавн-клеток=" . count($card['spawn_cells'] ?? [])
            . " | объекты=" . count($lay['objects'])
            . " ловушки=" . count($lay['traps'])
            . " npc=" . count($lay['npcs']) . "\n";
    }
} catch (Throwable $e) {
    echo "❌ Ошибка чтения статики: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== Живой мир в Redis (round-trip через штатный загрузчик) ===\n";
try {
    $v_raw = getRedis()->get(REDIS_VERSION);
    $version = ($v_raw === false || $v_raw === null) ? 0 : (int)$v_raw;
    $seeded = getRedis()->get(REDIS_SEEDED);
    echo "battle:version = " . ($version > 0 ? $version : "❌ отсутствует") . "\n";
    echo "battle:seeded  = " . ($seeded === false ? "⚠️ отсутствует" : (string)(int)$seeded) . "\n";
    if ($version <= 0) {
        echo "⚠️ Мир не инициализирован — запустите: php tools/reset_world.php\n";
    } else {
        $loaded = world_load();
        echo "✅ world_load(): players=" . count($loaded['players'] ?? [])
            . " objects=" . count($loaded['objects'] ?? [])
            . " traps=" . count($loaded['traps'] ?? [])
            . " npcs=" . count($loaded['npcs'] ?? [])
            . " rooms=" . count($loaded['rooms'] ?? []) . "\n";
        $tt = (int)($loaded['turn_time'] ?? 0);
        echo "turn_time = " . $tt . ($tt > 0 ? "" : "  ❌ дедлайн хода потерян при round-trip") . "\n";
        echo "version после load = " . world_version() . " (load не должен её двигать)\n";
        turn_tick($loaded);
        echo "✅ turn_tick() отработал без ошибок (без записи в мир)\n";
    }
} catch (Throwable $e) {
    echo "❌ Тест движка на Redis-мире: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<h2>Если все ✅ - вернитесь на <a href='../game.html'>game.html</a></h2>";
