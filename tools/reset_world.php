#!/usr/bin/env php
<?php
/**
 * tools/reset_world.php — Сброс/создание мира в Redis из ИЗНАЧАЛЬНЫХ данных.
 *
 * Пришёл на смену tools/seed_redis.py (упразднён вместе с data/battledata.json).
 * Причина не в языке, а в источнике правил: Python-скрипт хранил копию
 * характеристик объектов/ловушек/NPC и свою сборку состояния — два места,
 * которые неизбежно расходились с PHP-движком (а значит, «начало боя» зависело
 * от того, каким скриптом его засеяли). Теперь мир собирает
 * php/engine/world_builder.php — тот же код, что использует движок:
 *   - характеристики типов → systems/objects.php::getObjectTemplates();
 *   - расстановка → секции objects/traps/npcs карт data/<ID>.json;
 *   - бойцы → data/initial_snapshot.json (временная подставка под аккаунт).
 *
 * Использование:
 *   php tools/reset_world.php                 # собрать и записать мир (flushDB)
 *   php tools/reset_world.php --dry-run       # только проверка сборки + сводка
 *   php tools/reset_world.php --pretty        # сводка в JSON (для машин)
 *   php tools/reset_world.php --file=path     # другой снимок бойцов
 *   --host H --port P --db N                  # адрес Redis (или REDIS_HOST/PORT/DB)
 *
 * Код возврата: 0 — успех; 1 — битые данные; 2 — нет расширения redis;
 *                3 — Redis недоступен.
 *
 * ⚠️ Команда flushDB затирает ТЕКУЩИЙ живой бой — это и есть «перезапуск сессии»:
 * после неё разрушенные объекты снова целы, мина INTACT, игроки живы и стоят на
 * стартовых клетках.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "tools/reset_world.php — только CLI (он делает flushDB живого мира).\n";
    exit(1);
}

require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/../php/engine/bootstrap.php';
require_once __DIR__ . '/../php/engine/world_builder.php';

$args = array_slice($argv, 1);
$opts = ['dry_run' => false, 'pretty' => false, 'file' => null];
foreach ($args as $a) {
    if ($a === '--dry-run') $opts['dry_run'] = true;
    elseif ($a === '--pretty') $opts['pretty'] = true;
    elseif (sscanf($a, '--file=%s', $v) === 1) $opts['file'] = $v;
    elseif (sscanf($a, '--host=%s', $v) === 1) define('CLI_REDIS_HOST', $v);
    elseif (sscanf($a, '--port=%d', $v) === 1) define('CLI_REDIS_PORT', (int)$v);
    elseif (sscanf($a, '--db=%d', $v) === 1) define('CLI_REDIS_DB', (int)$v);
    else {
        fwrite(STDERR, "Неизвестный аргумент: {$a}\n");
        exit(1);
    }
}

function out($text) { fwrite(STDOUT, $text . "\n"); }
function err($text) { fwrite(STDERR, $text . "\n"); }

// ── 1. Снимок ИЗНАЧАЛЬНЫХ бойцов (путь определяет world_builder — не здесь) ──
list($players_list, $read_err) = world_builder_read_snapshot($opts['file']);
if ($read_err !== null) {
    err("❌ {$read_err}");
    exit(1);
}
$seed = ['players' => $players_list];

// ── 2. Сборка мира (только чтение дисковых карт) ─────────────────────────────
$res = world_build_initial($seed, ['rooms' => []]);
$errors = $res['errors'];
$state = $res['state'];

$summary = [
    'players' => count($state['players']),
    'objects' => count($state['objects']),
    'traps' => count($state['traps']),
    'npcs' => count($state['npcs']),
    'rooms' => array_keys($state['rooms']),
    'legacy_room' => $state['room']['room_id'] ?? '',
    'turn_time' => $state['turn_time'],
    'errors' => $errors,
];

if ($errors) {
    foreach ($errors as $e) err("❌ {$e}");
    err("\nМир НЕ записан: исправьте данные (код/карты комнат/seed) и повторите.");
    exit(1);
}

if ($opts['dry_run']) {
    if ($opts['pretty']) {
        out(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } else {
        out("✅ (dry-run) Мир собирается без ошибок: " . json_encode($summary, JSON_UNESCAPED_UNICODE));
    }
    exit(0);
}

// ── 3. Запись в Redis ────────────────────────────────────────────────────────
if (!extension_loaded('redis')) {
    err("❌ Расширение PHP Redis не установлено (pecl install redis).");
    exit(2);
}
try {
    // Reset-скрипт живёт вне HTTP-слоя, поэтому адрес можно переопределить флагами.
    $r = new Redis();
    $host = defined('CLI_REDIS_HOST') ? CLI_REDIS_HOST : (getenv('REDIS_HOST') ?: REDIS_HOST);
    $port = defined('CLI_REDIS_PORT') ? CLI_REDIS_PORT : (int)(getenv('REDIS_PORT') ?: REDIS_PORT);
    $db   = defined('CLI_REDIS_DB') ? CLI_REDIS_DB : (int)(getenv('REDIS_DB') ?: REDIS_DB);
    if (!$r->connect($host, $port, 2)) throw new Exception("не удалось подключиться к {$host}:{$port}");
    $r->select($db);

    $now = microtime(true);
    $pipe = $r->multi(Redis::MULTI);
    $pipe->flushDB(); // полный сброс: старого боя не должно остаться ни в одном ключе
    $enc = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); };
    $pipe->set(REDIS_PLAYERS, $enc($state['players']));
    $pipe->set(REDIS_OBJECTS, $enc($state['objects']));
    $pipe->set(REDIS_TRAPS, $enc($state['traps']));
    $pipe->set(REDIS_NPCS, $enc($state['npcs']));
    $pipe->set(REDIS_ROOM, $enc($state['room'] ?? []));
    // turn_time — int (round-trip регрессия: JSON-литерал «1234» обнулял дедлайн
    // хода, и poll продвигал ход каждый тик).
    $pipe->set(REDIS_TURN_TIME, (string)(int)$state['turn_time']);
    $pipe->set(REDIS_PROJECTILES, $enc($state['projectiles']));
    $pipe->set(REDIS_LOG, $enc($state['log']));
    $pipe->set(REDIS_ROOM_KEY_PREFIX . 'list', $enc(array_keys($state['rooms'])));
    foreach ($state['rooms'] as $rid => $card) {
        $pipe->set(REDIS_ROOM_KEY_PREFIX . $rid, $enc($card));
    }
    $pipe->set(REDIS_WORLD_MOVE_NOW, (string)$now);
    $pipe->set(REDIS_SEEDED, (string)(int)$now);
    $pipe->set(REDIS_VERSION, '1'); // счётчик изменений: после сида = 1
    $pipe->exec();
    $r->close();
} catch (Throwable $e) {
    err("❌ Redis недоступен или запись не удалась: " . $e->getMessage());
    err("   Проверьте redis-server (--host/--port/--db) и повторите.");
    exit(3);
}

if ($opts['pretty']) {
    out(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
} else {
    out("✅ Мир сброшен в Redis ({$host}:{$port}/db{$db}) и готов к игре:");
    out("   players={$summary['players']} objects={$summary['objects']}"
        . " traps={$summary['traps']} npcs={$summary['npcs']}"
        . " rooms=" . implode(',', $summary['rooms'])
        . " legacy={$summary['legacy_room']}");
    out("   Открывайте game.html — php/init.php найдёт мир и отдаст правила.");
}
exit(0);
