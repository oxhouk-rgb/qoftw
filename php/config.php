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
// Флаг «мир собран из ИЗНАЧАЛЬНЫХ данных и записан в Redis» (пишет
// engine/world_reset.php — его вызывают tools/reset_world.php и init.php?reset=1,
// проверяет init.php). Хранится ОТДЕЛЬНО от battle:version, чтобы проверка
// готовности инициализации не сдвигала версию мира и не будила ложно long-poll
// клиентов.
if (!defined('REDIS_SEEDED')) define('REDIS_SEEDED', REDIS_PREFIX . 'seeded');
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

// ★ ЕДИНЫЙ СЧЁТЧИК ВЕРСИИ: REDIS_VERSION — единственный источник истины о
// версии мира. Его читают world_load()/getBattleState() (ожидаемая версия в
// action.php), его инкрементируют ОБА пути записи (legacy-тик poll.php/init и
// CAS-action), по нему работает long-poll. Второй счётчик («state_version»)
// был причиной регрессии «503 после первого действия»: legacy-путь никогда его
// не обновлял, и каждая следующая CAS-запись ложно считалась гонкой.
// REDIS_STATE_VERSION сохранён только как алиас (никогда не инкрементируется
// отдельно) — на случай внешних отладочных скриптов, читающих константу.
if (!defined('REDIS_STATE_VERSION')) define('REDIS_STATE_VERSION', REDIS_VERSION);

// Сохранить состояние боя (опционально — с CAS-проверкой конкурентной записи).
//
// ★ ИСПРАВЛЕНИЕ «потери пакета» на сервере (last-writer-wins): раньше здесь
// был безусловно-перезаписывающий pipeline: action.php загружал state, мутировал
// его и сохранял, а ПАРАЛЛЕЛЬНЫЙ процесс poll.php в это же время делал свой тик
// (движение, эффекты, ход) из snapshot, загруженного ДО применения действия.
// Кто сохранится позже — тот затрёт чужую мутацию целиком: действие игрока
// «применялось» (ack уже отправлен, клиент показал «Действие выполнено»), но
// через мгновение перезаписанный снапшот опроса стирал его из мира. Пришлём
// $expectedVersion (версию, прочитанную сразу ПОСЛЕ world_load в action.php) —
// и запись станет атомарной: WATCH/MULTI/EXEC отклонит save, если мир изменился
// параллельным процессом, а вызывающий код перечитает состояние и повторит
// попытку (см. engine/world.php::world_save_cas).
//
// @param int|null $expectedVersion null — legacy-режим (init/tick без CAS).
// @return bool true — записано; false — гонка (мир изменён, нужен rebase).
if (!function_exists('saveBattleState')) {
    function saveBattleState($state, $expectedVersion = null) {
        $encode = function ($state) {
            return [
                json_encode($state['players']),
                json_encode($state['objects']),
                json_encode($state['traps']),
                json_encode($state['npcs']),
                json_encode($state['room']),
                $state['turn_time'],
                json_encode($state['projectiles']),
                json_encode($state['log']),
                // Комнаты: пишем только реально используемые (см. getBattleState выше).
                json_encode(array_keys($state['rooms'] ?? [])),
                $state['rooms'] ?? [],
            ];
        };

        $redis = getRedis();

        if ($expectedVersion === null) {
            // Legacy-путь без конкуренции (init, одиночный tick): как раньше.
            $enc = $encode($state);
            $pipe = $redis->multi(Redis::PIPELINE);
            $pipe->set(REDIS_PLAYERS, $enc[0]);
            $pipe->set(REDIS_OBJECTS, $enc[1]);
            $pipe->set(REDIS_TRAPS, $enc[2]);
            $pipe->set(REDIS_NPCS, $enc[3]);
            $pipe->set(REDIS_ROOM, $enc[4]);
            $pipe->set(REDIS_TURN_TIME, $enc[5]);
            $pipe->set(REDIS_PROJECTILES, $enc[6]);
            $pipe->incr(REDIS_VERSION);
            $pipe->set(REDIS_LOG, $enc[7]);
            $pipe->set(REDIS_ROOM_KEY_PREFIX . 'list', $enc[8]);
            foreach ($enc[9] as $rid => $rdata) {
                $pipe->set(REDIS_ROOM_KEY_PREFIX . $rid, json_encode($rdata));
            }
            $pipe->exec();
            return true;
        }

        // CAS (compare-and-swap) через транзакцию WATCH/MULTI/EXEC:
        // EXEC атомарно отменится (вернёт false), если параллельный процесс
        // успел изменить мир между нашим WATCH и EXEC. Мониторим ВСЕ секции
        // состояния, а не только счётчик версии: тик poll.php пишет секции в
        // pipeline-транзакции, которая может пересечься с нашим окном read→save;
        // именно watch по данным ловит реальную гонку read-modify-write даже
        // когда номер версии уже совпадает (exec() отменится сам).
        // После неудачного exec() повторять бесполезно — состояние уже
        // рассинхронизировано; вызывающий код обязан перечитать мир (rebase)
        // и вызвать save заново (см. world_save_cas в engine/world.php).
        $watch_keys = [
            REDIS_PLAYERS, REDIS_OBJECTS, REDIS_TRAPS, REDIS_NPCS, REDIS_ROOM,
            REDIS_TURN_TIME, REDIS_PROJECTILES, REDIS_LOG, REDIS_VERSION,
            REDIS_ROOM_KEY_PREFIX . 'list',
        ];
        $rooms = $state['rooms'] ?? [];
        foreach (array_keys($rooms) as $rid) {
            $watch_keys[] = REDIS_ROOM_KEY_PREFIX . $rid;
        }

        $redis->watch($watch_keys);

        // Сверка ожидаемой версии с ЕДИНСТВЕННЫМ счётчиком REDIS_VERSION —
        // тем же, который getBattleState() кладёт в state['version'] при load
        // (и action.php, и rebase-цикл сверяются именно с ним). Раньше здесь
        // сверялся второй, независимый счётчик (REDIS_STATE_VERSION), который
        // legacy-путь (init/tick poll.php) никогда не инкрементировал: после
        // первого же тика worlds он «отставал» от version, и каждая следующая
        // CAS-запись ложно считалась гонкой → стабильный 503 («игра ломается
        // после первого действия»).
        $rv_raw = $redis->get(REDIS_VERSION);
        $rv = ($rv_raw === false || $rv_raw === null) ? 0 : (int)$rv_raw;
        if ($rv !== (int)$expectedVersion) {
            $redis->unwatch();
            return false; // мир изменился после нашего load — гонка, нужен rebase
        }

        $enc = $encode($state);
        $tx = $redis->multi(); // Redis::MODE_ATOMIC (WATCH-транзакция)
        $tx->set(REDIS_PLAYERS, $enc[0]);
        $tx->set(REDIS_OBJECTS, $enc[1]);
        $tx->set(REDIS_TRAPS, $enc[2]);
        $tx->set(REDIS_NPCS, $enc[3]);
        $tx->set(REDIS_ROOM, $enc[4]);
        $tx->set(REDIS_TURN_TIME, $enc[5]);
        $tx->set(REDIS_PROJECTILES, $enc[6]);
        // Единый monotonic-счётчик: рост версии неразрывен с записью данных
        // (в той же транзакции) — long-poll гарантированно увидит изменение.
        $tx->incr(REDIS_VERSION);
        $tx->set(REDIS_LOG, $enc[7]);
        $tx->set(REDIS_ROOM_KEY_PREFIX . 'list', $enc[8]);
        foreach ($enc[9] as $rid => $rdata) {
            $tx->set(REDIS_ROOM_KEY_PREFIX . $rid, json_encode($rdata));
        }
        // exec() === false — транзакция отменена из-за изменения watched-ключей
        // параллельным процессом (настоящая гонка): мир НЕ тронут.
        return $tx->exec() !== false;
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

// Чтение тела запроса. В веб-SAPI это нативный php://input. В CLI php://input
// недоступен (поток пустой), поэтому интеграционный тест (tests/
// integration_join.php) передаёт тело через файл, путь к которому лежит в
// переменной окружения BATTLE_TEST_INPUT_FILE. Вне теста переменная не
// установлена — поведение рантайма не меняется.
if (!function_exists('requestInput')) {
    function requestInput() {
        if (PHP_SAPI === 'cli') {
            $f = getenv('BATTLE_TEST_INPUT_FILE');
            if ($f !== false && $f !== '' && is_file($f)) {
                return (string)file_get_contents($f);
            }
            return '';
        }
        return file_get_contents('php://input');
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
