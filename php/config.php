<?php
/**
 * config.php - Конфигурация подключения к Redis и общие константы
 */

// Отключаем вывод ошибок в HTML (они ломают JSON)
error_reporting(0);
ini_set('display_errors', '0');

// Redis настройки
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_DB', 0);

// Константы игры
define('GRID_WIDTH', 16);
define('GRID_HEIGHT', 12);
define('MAX_MOVE_DISTANCE', 5);
define('LONG_POLL_TIMEOUT', 25); // секунд таймаут long polling
define('MOVE_SPEED', 3); // клеток в секунду
define('TURN_DURATION', 5); // секунд между обновлениями очков действий

// Ключи Redis
define('REDIS_PREFIX', 'battle:');
define('REDIS_PLAYERS', REDIS_PREFIX . 'players');
define('REDIS_OBJECTS', REDIS_PREFIX . 'objects');
define('REDIS_TRAPS', REDIS_PREFIX . 'traps');
define('REDIS_NPCS', REDIS_PREFIX . 'npcs');
define('REDIS_ROOM', REDIS_PREFIX . 'room');
define('REDIS_TURN_TIME', REDIS_PREFIX . 'turn_time');
define('REDIS_PROJECTILES', REDIS_PREFIX . 'projectiles');
define('REDIS_VERSION', REDIS_PREFIX . 'version');
define('REDIS_LOG', REDIS_PREFIX . 'log');

// Подключение к Redis
function getRedis() {
    static $redis = null;
    if ($redis === null) {
        // Проверяем наличие расширения Redis
        if (!class_exists('Redis')) {
            throw new Exception('Расширение PHP Redis не установлено. Установите php_redis.dll (Windows) или phpredis (Linux)');
        }
        
        try {
            $redis = new Redis();
            $connected = @$redis->connect(REDIS_HOST, REDIS_PORT, 2); // 2 сек таймаут
            if (!$connected) {
                throw new Exception('Не удалось подключиться к Redis на ' . REDIS_HOST . ':' . REDIS_PORT . '. Убедитесь что redis-server запущен!');
            }
            $redis->select(REDIS_DB);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, 2); // Таймаут чтения 2 секунды
        } catch (Throwable $e) {
            throw new Exception('Redis ошибка: ' . $e->getMessage());
        }
    }
    return $redis;
}

// Закрытие Redis соединения
function closeRedis() {
    static $redis = null;
    if ($redis !== null) {
        try {
            $redis->close();
        } catch (Throwable $e) {
            // Игнорируем ошибки закрытия
        }
        $redis = null;
    }
}

// Получить все данные состояния боя
function getBattleState() {
    $redis = getRedis();
    $pipe = $redis->multi(Redis::PIPELINE);
    $pipe->get(REDIS_PLAYERS);
    $pipe->get(REDIS_OBJECTS);
    $pipe->get(REDIS_TRAPS);
    $pipe->get(REDIS_NPCS);
    $pipe->get(REDIS_ROOM);
    $pipe->get(REDIS_TURN_TIME);
    $pipe->get(REDIS_PROJECTILES);
    $pipe->get(REDIS_VERSION);
    $pipe->get(REDIS_LOG);
    $results = $pipe->exec();
    
    return [
        'players' => json_decode($results[0], true) ?: [],
        'objects' => json_decode($results[1], true) ?: [],
        'traps' => json_decode($results[2], true) ?: [],
        'npcs' => json_decode($results[3], true) ?: [],
        'room' => json_decode($results[4], true) ?: [],
        'turn_time' => (int)$results[5],
        'projectiles' => json_decode($results[6], true) ?: [],
        'version' => (int)$results[7],
        'log' => json_decode($results[8], true) ?: []
    ];
}

// Сохранить состояние боя
function saveBattleState($state) {
    $redis = getRedis();
    $pipe = $redis->multi(Redis::PIPELINE);
    $pipe->set(REDIS_PLAYERS, json_encode($state['players']));
    $pipe->set(REDIS_OBJECTS, json_encode($state['objects']));
    $pipe->set(REDIS_TRAPS, json_encode($state['traps']));
    $pipe->set(REDIS_NPCS, json_encode($state['npcs']));
    $pipe->set(REDIS_ROOM, json_encode($state['room']));
    $pipe->set(REDIS_TURN_TIME, $state['turn_time']);
    $pipe->set(REDIS_PROJECTILES, json_encode($state['projectiles']));
    $pipe->incr(REDIS_VERSION);
    $pipe->set(REDIS_LOG, json_encode($state['log']));
    $pipe->exec();
}

// Добавить запись в лог
function addLog($state, $message) {
    $state['log'][] = [
        'time' => time(),
        'message' => $message
    ];
    // Храним только последние 50 записей
    if (count($state['log']) > 50) {
        $state['log'] = array_slice($state['log'], -50);
    }
    return $state;
}

// Парсинг координат "X,Y" в массив
function parseXY($xy) {
    $parts = explode(',', $xy);
    return ['x' => (int)$parts[0], 'y' => (int)$parts[1]];
}

// Форматирование координат в строку (поддерживает дробные значения)
function formatXY($x, $y) {
    // Если значения целые - форматируем как целые
    if (floor($x) == $x && floor($y) == $y) {
        return ((int)$x) . ',' . ((int)$y);
    }
    // Иначе с точностью до 2 знаков
    return round($x, 2) . ',' . round($y, 2);
}

// Проверка валидности координат
function isValidPosition($x, $y) {
    return $x >= 0 && $x < GRID_WIDTH && $y >= 0 && $y < GRID_HEIGHT;
}

// CORS заголовки
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');
}

// Безопасный вывод JSON (с обработкой ошибок)
function jsonResponse($data) {
    // Очищаем буфер вывода (на случай если PHP что-то вывел до нас)
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Обработка ошибки с выводом JSON
function jsonError($message, $code = 500) {
    http_response_code($code);
    jsonResponse(['success' => false, 'message' => $message]);
}
