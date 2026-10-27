<?php
/**
 * config.php - Конфигурация подключения к Redis и общие константы
 */

// Отключаем вывод ошибок в HTML (они ломают JSON)
error_reporting(0);
ini_set('display_errors', '0');

// Redis настройки
if (!defined('REDIS_HOST')) define('REDIS_HOST', '127.0.0.1');
if (!defined('REDIS_PORT')) define('REDIS_PORT', 6379);
if (!defined('REDIS_DB')) define('REDIS_DB', 0);

// Константы игры — ЗДЕСЬ НЕ ОПРЕДЕЛЯЮТСЯ.
// Единый источник игровых правил (GRID_*, MOVE_SPEED, TURN_DURATION,
// MAX_MOVE_DISTANCE, LONG_POLL_TIMEOUT и т.д.) — php/engine/rules.php.
// config.php отвечает только за Redis-коннект, ключи и JSON-хелперы.
// (Раньше здесь был дубль констант, из-за которого при двойном подключении
// возникал фатальный "Constant already defined" → HTTP 500.)

// Ключи Redis
if (!defined('REDIS_PREFIX')) define('REDIS_PREFIX', 'battle:');
if (!defined('REDIS_PLAYERS')) define('REDIS_PLAYERS', REDIS_PREFIX . 'players');
if (!defined('REDIS_OBJECTS')) define('REDIS_OBJECTS', REDIS_PREFIX . 'objects');
if (!defined('REDIS_TRAPS')) define('REDIS_TRAPS', REDIS_PREFIX . 'traps');
if (!defined('REDIS_NPCS')) define('REDIS_NPCS', REDIS_PREFIX . 'npcs');
if (!defined('REDIS_ROOM')) define('REDIS_ROOM', REDIS_PREFIX . 'room');
// Мультикомнатный мир: комнаты грузятся лениво из data/<ID>.json (см.
// systems/rooms.php), поэтому в Redis физически лежат только реально
// использованные комнаты — по ключу room:<ID>. REDIS_ROOM сохранён для
// legacy-режима единой карты (state['room']).
if (!defined('REDIS_ROOM_KEY_PREFIX')) define('REDIS_ROOM_KEY_PREFIX', REDIS_PREFIX . 'room:');
if (!defined('REDIS_TURN_TIME')) define('REDIS_TURN_TIME', REDIS_PREFIX . 'turn_time');
if (!defined('REDIS_PROJECTILES')) define('REDIS_PROJECTILES', REDIS_PREFIX . 'projectiles');
if (!defined('REDIS_VERSION')) define('REDIS_VERSION', REDIS_PREFIX . 'version');
if (!defined('REDIS_LOG')) define('REDIS_LOG', REDIS_PREFIX . 'log');
// Журнал подтверждённых действий (защита от «потери пакета» при ретраях — см. engine/world.php)
if (!defined('REDIS_ACTION_ACKS')) define('REDIS_ACTION_ACKS', REDIS_PREFIX . 'action_acks');

// Подключение к Redis
// РАЗДЕЛЕНИЕ ХЕНДЛАРА И ФЛАГА СОЕДИНЕНИЯ — исправление бага «потери пакета»:
// раньше closeRedis() обнулял единственный статик-хендлар, а config.php
// повторно не подключается (require_once) — любое обращение к getRedis() ПОСЛЕ
// closeRedis() возвращало null и роняло процесс на фатал
// "Call to a member function ... on null" уже в момент вывода ответа: клиент
// получал оборванный/500-й HTTP-ответ и считал это потерей пакета (а действие,
// успевшее сохраниться в мир, при повторе упиралось в потраченные очки).
// Теперь хендлар живёт отдельно, closeRedis() лишь помечает соединение
// закрытым, а следующее обращение прозрачно открывает новое.
if (!function_exists('redisConnect')) {
    function redisConnect() {
        // Проверяем наличие расширения Redis
        if (!class_exists('Redis')) {
            throw new Exception('Расширение PHP Redis не установлено. Установите php_redis.dll (Windows) или phpredis (Linux)');
        }
        try {
            $r = new Redis();
            $connected = @$r->connect(REDIS_HOST, REDIS_PORT, 2); // 2 сек таймаут
            if (!$connected) {
                throw new Exception('Не удалось подключиться к Redis на ' . REDIS_HOST . ':' . REDIS_PORT . '. Убедитесь что redis-server запущен!');
            }
            $r->select(REDIS_DB);
            $r->setOption(Redis::OPT_READ_TIMEOUT, 2); // Таймаут чтения 2 секунды
        } catch (Throwable $e) {
            throw new Exception('Redis ошибка: ' . $e->getMessage());
        }
        return $r;
    }
}

// Флаг «соединение открыто» живёт в $GLOBALS — единственное место, общее для
// всех функций (static-переменные разных функций в PHP отдельны и неизбежно
// рассинхронизировались бы).
if (!function_exists('isRedisConnected')) {
    function isRedisConnected() {
        return !empty($GLOBALS['redis_connected']);
    }
}

if (!function_exists('getRedisHandle')) {
    function getRedisHandle() {
        static $redis = null;
        if ($redis === null || !isRedisConnected()) {
            $redis = redisConnect(); // бросает Exception при недоступном Redis
            $GLOBALS['redis_connected'] = true;
        }
        return $redis;
    }
}

// Публичное поведение getRedis(): живое соединение гарантируется ленивым
// переподключением; при недоступном Redis бросается исключение (никогда null).
if (!function_exists('getRedis')) {
    function getRedis() {
        return getRedisHandle();
    }
}

// Закрытие Redis соединения (идемпотентно; следующее обращение к getRedis()
// прозрачно откроет новое соединение).
if (!function_exists('closeRedis')) {
    function closeRedis() {
        if (!isRedisConnected()) {
            return; // уже закрыто
        }
        try {
            getRedisHandle()->close();
        } catch (Throwable $e) {
            // Игнорируем ошибки закрытия
        }
        $GLOBALS['redis_connected'] = false;
    }
}

// Получить все данные состояния боя
if (!function_exists('getBattleState')) {
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
        // Мультикомнатный мир: список «распакованных» комнат + их JSON-карты.
        // Комнаты грузятся лениво из data/*.json; в Redis лежат только реально
        // использованные — масштаб 25 помещений не раздувает состояние.
        $pipe->get(REDIS_ROOM_KEY_PREFIX . 'list');
        $results = $pipe->exec();
    
        $state = [
            'players' => json_decode($results[0], true) ?: [],
            'objects' => json_decode($results[1], true) ?: [],
            'traps' => json_decode($results[2], true) ?: [],
            'npcs' => json_decode($results[3], true) ?: [],
            'room' => json_decode($results[4], true) ?: [],
            'turn_time' => (int)$results[5],
            'projectiles' => json_decode($results[6], true) ?: [],
            'version' => (int)$results[7],
            'log' => json_decode($results[8], true) ?: [],
            'rooms' => [],
        ];

        $room_list = json_decode((string)($results[9] ?? ''), true) ?: [];
        if (!empty($room_list)) {
            $pipe2 = getRedis()->multi(Redis::PIPELINE);
            foreach ($room_list as $rid) {
                $pipe2->get(REDIS_ROOM_KEY_PREFIX . $rid);
            }
            $room_jsons = $pipe2->exec();
            foreach ($room_list as $i => $rid) {
                $decoded = json_decode((string)($room_jsons[$i] ?? ''), true);
                if (is_array($decoded)) $state['rooms'][$rid] = $decoded;
            }
        }

        return $state;
    }
}

// Сохранить состояние боя
if (!function_exists('saveBattleState')) {
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
        // Комнаты: пишем только реально используемые (см. getBattleState выше).
        $rooms = $state['rooms'] ?? [];
        $pipe->set(REDIS_ROOM_KEY_PREFIX . 'list', json_encode(array_keys($rooms)));
        foreach ($rooms as $rid => $rdata) {
            $pipe->set(REDIS_ROOM_KEY_PREFIX . $rid, json_encode($rdata));
        }
        $pipe->exec();
    }
}

// Добавить запись в лог
if (!function_exists('addLog')) {
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
}

// Парсинг координат "X,Y" в массив.
// Единый владелец этих функций — engine/geometry.php; здесь они только
// подхватываются, если config.php используется standalone (debug-скрипты),
// чтобы при совместной загрузке не было фатального "Cannot redeclare".
if (!function_exists('parseXY')) {
    function parseXY($xy) {
        $parts = explode(',', $xy);
        return ['x' => (int)$parts[0], 'y' => (int)$parts[1]];
    }
}

// Форматирование координат в строку (поддерживает дробные значения)
if (!function_exists('formatXY')) {
    function formatXY($x, $y) {
        // Если значения целые - форматируем как целые
        if (floor($x) == $x && floor($y) == $y) {
            return ((int)$x) . ',' . ((int)$y);
        }
        // Иначе с точностью до 2 знаков
        return round($x, 2) . ',' . round($y, 2);
    }
}

// Проверка валидности координат
if (!function_exists('isValidPosition')) {
    function isValidPosition($x, $y) {
        return $x >= 0 && $x < GRID_WIDTH && $y >= 0 && $y < GRID_HEIGHT;
    }
}

// CORS заголовки
if (!function_exists('setCorsHeaders')) {
    function setCorsHeaders() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');
    }
}

// Безопасный вывод JSON (с обработкой ошибок)
if (!function_exists('jsonResponse')) {
    function jsonResponse($data) {
        // Очищаем буфер вывода (на случай если PHP что-то вывел до нас)
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Обработка ошибки с выводом JSON
if (!function_exists('jsonError')) {
    function jsonError($message, $code = 500) {
        http_response_code($code);
        jsonResponse(['success' => false, 'message' => $message]);
    }
}

// Аварийный JSON-ответ без обращения к Redis (для catch-блоков эндпоинтов,
// достижимых ПОСЛЕ closeRedis(): обычный jsonError мог бросить новое
// исключение из echo и оборвать HTTP-ответ — «потеря пакета» на клиенте).
if (!function_exists('clearOutputBuffers')) {
    function clearOutputBuffers() {
        while (ob_get_level()) {
            ob_end_clean();
        }
    }
}

if (!function_exists('sendJsonErrorFallback')) {
    function sendJsonErrorFallback($message, $code = 500) {
        clearOutputBuffers();
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
