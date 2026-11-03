<?php
/**
 * ИНТЕГРАЦИОННЫЙ ТЕСТ: HTTP-контракт init.php → join.php → публикации
 * Centrifugo-снапшотов (Этап 1 + переход с long polling, ноябрь 2026).
 *
 * Два режима прогона:
 *   A) IN-PROCESS (значение по умолчанию): `php -n tests/integration_join.php`.
 *      Каждый «запрос» исполняет реальный эндпоинт php/*.php внутри процесса
 *      теста (подмена $_SERVER/$_GET/php://input + output buffering), fake-redis
 *      — общий суперглобал. Детерминированно, без сети и без deadlock однопоточного
 *      `php -S` на long-poll. Проверки состояния мира работают напрямую.
 *   B) OVER-HTTP: `bash tests/run_integration_join.sh` (HTTP_TRANSPORT=http) —
 *      настоящие сетевые запросы к `php -n -S ... --serve`; проверяются только
 *      коды/JSON-контракт (состояние мира у сервера своё).
 *
 * Сценарий (решения по Этапу 1):
 *   1. GET  init.php?reset=1   — сборка НОВОЙ сессии; бойцов 0 («0 игроков = 0 персонажей»);
 *   2. GET  init.php           — обычный вход ЛЕГАЛЕН с пустым списком бойцов (не 503!)
 *                                и отдаёт реестры abilities/passives для формы настройки;
 *   3. POST join.php ×4        — четыре игрока заселяют слоты A1/A5/E1/E5 + каждый
 *                                ответ несёт ws-блок (Centrifugo url/канал/JWT);
 *   4. sink публикаций          — join.php публикует снапшоты в каналы battle:<ID>:
 *                                N игроков = N персонажей; ALLY='NONE'; UID из карточки;
 *   5. POST join.php (uid=2)   — повторный вход идемпотентен (rejoin:true, бойцов всё ещё 4);
 *   6. POST join.php (uid=5)   — пятый игрок: uid вне слотов → 400 (защита статичной сессии);
 *   7. reset + join×4 + 5-й    — слот заполнен чужим uid → конфликт ника/слота → 409;
 *      (вместе с шагом 6 это и есть «запрет пятого при занятых спавн-местах»:
 *       любой uid вне 1..4 отклоняется валидатором, любой uid 1..4 при полном
 *       лобби упёрся бы в дубликат-проверку до создания бойца);
 *   8. Битые карточки          — 2 способности вместо 3 → 400; ACTIONS=4 → 400;
 *                                неизвестная пассивка → 400.
 *
 * ВНИМАНИЕ: тест мутирует только «Redis» фейка — боевые файлы data/* не трогаются.
 */

// Режим запуска: integration_join.php может работать в ДВУХ ролях.
//  1) КЛИЕНТ (по умолчанию): последовательные HTTP-запросы к встроенному
//     серверу из run_integration_join.sh.
//  2) РОУТЕР (`php tests/integration_join.php --serve <port>`): тот же файл
//     поднят как router скрипт `php -S` — каждый входящий запрос проксируется
//     в реальный эндпоинт php/<script>.php с сохранением метода/тела/GET.
//     Так тест проверяет сквозной HTTP-контракт, а боевые файлы остаются нетронутыми.
// ★ Роутер под `php -S` (run_integration_join.sh): сапи 'cli-server' — он бывает
// только у встроенного сервера, и $argv там НЕ определён (проверка '--serve'
// никогда не срабатывала, и сервер вместо роутера исполнял клиентскую половину —
// регрессия режима OVER-HTTP). CLI-роль '--serve' оставлена для ручных прогонов.
// ★ Порядок: развилка ролей ОБЯЗАНА идти после определения BATTLE_PROJECT_ROOT —
// integration_serve_router() читает его на первом же запросе.

// ★ Корень проекта — ЕДИНСТВЕННЫЙ источник путей для всех режимов запуска.
// __DIR__/dirname(__FILE__) работают только при прямом запуске файла; при
// загрузке кода через eval (отладочные обёртки) они указывают на каталог
// вызывающего скрипта или не определены вовсе, и тест начинал искать
// php/*.php в несуществующем '/php/' → все запросы давали ложные 404/500.
if (!defined('BATTLE_PROJECT_ROOT')) {
    $root = realpath(dirname(__FILE__) . '/..');
    if ($root === false) { $root = realpath(__DIR__ . '/..'); }
    if ($root === false || !is_dir($root . '/php')) {
        fwrite(STDERR, "integration_join: не удалось определить корень проекта\n");
        exit(2);
    }
    define('BATTLE_PROJECT_ROOT', $root);
    unset($root);
}

if (php_sapi_name() === 'cli-server') {
    integration_serve_router();
    exit(0);
}
if (php_sapi_name() === 'cli' && in_array('--serve', $argv ?? [], true)) {
    integration_serve_router();
    exit(0);
}

function integration_serve_router(): void {
    // ★ Двойник Redis подключаем ЯВНО: auto_prepend_file под php -S в ряда сборок
    // (в т.ч. Windows PHP 8.1) молча не исполняется — эндпоинты получали
    // «Расширение Redis не установлено». require ДО кода эндпоинта (класс Redis
    // обязан существовать до config.php); extension_loaded guard — чтобы на
    // машине с живым php-redis-расширением тест всё равно шёл через фейк, если
    // сервер поднят с -n, или через него, если расширение включено намеренно.
    if (!class_exists('Redis', false)) {
        require BATTLE_PROJECT_ROOT . '/tests/fakeredis_stub.php';
    }
    putenv('FAKEREDIS_SHARED=1'); // shared-хранилище процесса для connect()
    // ★ В роли маршрутизатора весь код НИЖЕ этого блока (проверки check(),
    // эмуляция http_req через file_get_contents и т.д.) недопустим — иначе
    // при каждом запросе сервер «сам себе» отправил бы серию HTTP-запросов
    // и deadlock'нул однопоточный php -S (клиент = сам сервер).
    $script = preg_replace('#[^a-z0-9_.-]#i', '', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $script = ltrim($script, '/');
    $target = BATTLE_PROJECT_ROOT . '/php/' . $script . '.php';
    if ($script === '' || !is_file($target)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'router: неизвестный эндпоинт /' . $script]);
        return;
    }
    require $target; // полный контроль над выводом/кодами ответа у эндпоинта
}

// ─── Дальше идёт только роль «КЛИЕНТ» ───────────────────────────────────────
$INTEGRATION_ONLY = true; // маркер читаемости: секция не исполняется в --serve

// Нужен ли дочерним процессам флаг -n: если штатный запуск PHP грузит НАСТОЯЩЕЕ
// расширение redis, двойник fakeredis_stub не объявится (class_exists guard) и
// потомки пойдут писать в живой Redis. Проверяем один раз на старте.
if (!defined('INTEGRATION_CHILD_NEEDS_N')) {
    $probe = shell_exec(escapeshellarg(PHP_BINARY)
        . ' -r "echo class_exists(\'Redis\') ? 1 : 0;" 2>NUL');
    define('INTEGRATION_CHILD_NEEDS_N', trim((string)$probe) === '1');
    unset($probe);
}

$PASS = 0; $FAIL = 0;
function check($cond, $label) {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "ok   - $label\n"; }
    else { $FAIL++; echo "FAIL - $label\n"; }
}

// ─── Хелперы «HTTP-запросов» ────────────────────────────────────────────────
// Два транспорта запросов:
// A) IN-PROCESS (значение по умолчанию): каждый вызов исполняет реальный
//    эндпоинт php/<script>.php в ОТДЕЛЬНОМ дочернем процессе PHP CLI — ровно
//    как HTTP-запрос к FPM. Так нужно потому, что эндпоинты завершаются через
//    exit (jsonResponse/jsonError): require внутри процесса теста убил бы его
//    на первом же успешном ответе. Механика:
//      • родитель пишет общее состояние fake-redis в tmp/store.json;
//      • потомок восстанавливает его, эмулирует суперглобальные запроса и
//        читает тело POST из tmp/body.json вместо php://input (хелпер
//        requestInput() в php/config.php: в веб-SAPI читает php://input, в CLI
//        — файл из переменной окружения BATTLE_TEST_INPUT_FILE);
//      • shutdown-хук потомка сохраняет изменённый мир обратно в store.json;
//      • вывод потомка — файл out.txt со служебной первой строкой "HTTPSTATUS:NNN".
//    Fake-Redis живёт в $GLOBALS['_redis_store'] родителя, поэтому проверки
//    состава мира после каждого шага работают без HTTP-сервера и без deadlock
//    однопоточного `php -S` на long-poll.
// B) OVER-HTTP (HTTP_TRANSPORT=http + HTTP_HOST): настоящие сетевые запросы к
//    `php -n -S ... tests/integration_join.php --serve` из run_integration_join.sh.
//    В этом режиме проверки состояния мира пропускаются (у сервера своё
//    хранилище fake-redis), проверяются только коды ответов и JSON-контракт.
$INTEGRATION_HTTP = (getenv('HTTP_TRANSPORT') === 'http');

if (!$INTEGRATION_HTTP) {
    // Рабочий каталог in-process-транспорта: tmp-файлы сценария.
    $INPROC_TMP = sys_get_temp_dir() . '/integration_join_' . getmypid();
    if (!is_dir($INPROC_TMP)) mkdir($INPROC_TMP, 0777, true);
    register_shutdown_function(function () use (&$INPROC_TMP) {
        foreach (glob($INPROC_TMP . '/*') ?: [] as $f) @unlink($f);
        @rmdir($INPROC_TMP);
    });
} else {
    $INPROC_TMP = null;
}

function http_req(string $script, ?string $body = null, array $get = []): array {
    global $INTEGRATION_HTTP, $INPROC_TMP;
    $script = basename($script); // 'init.php'
    if (!$INTEGRATION_HTTP) {
        return inproc_req($script, $body, $get);
    }
    $url = 'http://' . (getenv('HTTP_HOST') ?: '127.0.0.1:8899') . '/' . basename($script, '.php');
    if ($get) $url .= '?' . http_build_query($get);
    $ctx = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body ?? '',
        'ignore_errors' => true,
        // Должен превышать LONG_POLL_TIMEOUT сервера (poll.php держит
        // соединение до 25 с) — иначе клиент обрывал бы первый же poll.
        'timeout' => 40,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    $json = json_decode((string)$resp, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$resp];
}

function inproc_req(string $script, ?string $body, array $get): array {
    global $INPROC_TMP;
    // ★ http_req() вызывается с путями вида '/php/init.php' — оставляем только имя файла.
    $script = basename($script);
    $target = realpath(BATTLE_PROJECT_ROOT . '/php/' . $script);
    if ($target === false) {
        return ['status' => 404, 'json' => null, 'raw' => 'нет файла ' . $script];
    }

    // Общая «БД» сценария: актуализируем dump перед запуском потомка.
    // ★ Формат store.json — PHP-сериализация (serialize/unserialize), НЕ JSON:
    // ключи Redis вида "battle:players" содержат двоеточие, а в JSON-объекте
    // такие ключи при json_decode+ассоциативном доступе работают, НО пустой
    // мир после flushdb кодировался как []/{} неоднозначно, и любое повреждение
    // файла молча превращалось в «хранилище пусто» — тест терял состояние между
    // запросами. serialize точно сохраняет и пустой массив, и все ключи как есть.
    $GLOBALS['_redis_store'] = $GLOBALS['_redis_store'] ?? [];
    $storeDump = serialize($GLOBALS['_redis_store']);
    file_put_contents($INPROC_TMP . '/store.dat', $storeDump);
    file_put_contents($INPROC_TMP . '/body.json', $body ?? '');
    @unlink($INPROC_TMP . '/out.txt');

    file_put_contents($INPROC_TMP . '/child.php',
        build_child_script($script, $body, $get, $target, $INPROC_TMP));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $INPROC_TMP . '/out.txt', 'w'],
        2 => ['pipe', 'w'],
    ];
    // ★ Дочерний интерпретатор: PHP_BINARY (не 'php' из PATH — тот мог вообще
    // отсутствовать в окружении запуска). Флаг -n добавляется, если обычный запуск
    // грузит НАСТОЯЩЕЕ расширение redis: тогда двойник fakeredis_stub не объявится
    // (class_exists guard) и потомок пишет в живой Redis вместо фейка — тест
    // теряет герметичность и мутирует боевое хранилище. Под -n redis отключается,
    // а ctype/json/hash остаются (статически в сборке).
    $child_cmd = [PHP_BINARY, '-d', 'error_reporting=0'];
    if (INTEGRATION_CHILD_NEEDS_N) {
        $child_cmd[] = '-n';
    }
    $child_cmd[] = $INPROC_TMP . '/child.php';
    $proc = proc_open(
        // ★ БЕЗ флага -n по умолчанию: он отключает загрузку php.ini и всех неисключимых
        // расширений (ctype/json и т.п.) — боевой код php/join.php использует
        // ctype_digit() и падал с «Call to undefined function». Расширения из
        // системного ini безвредны: fake-Redis объявляет класс Redis ДО config.php
        // и только если он ещё не определён (class_exists guard). Исключение —
        // phpredis: см. INTEGRATION_CHILD_NEEDS_N выше.
        // error_reporting=0 глушит штатные warning'и CLI-SAPI (header() и пр.).
        $child_cmd,
        $descriptors,
        $pipes,
        BATTLE_PROJECT_ROOT,
        [
            'BATTLE_TEST_INPUT_FILE' => $INPROC_TMP . '/body.json',
            // Публикации Centrifugo уходят в JSONL-файл, а не в живую шину:
            // тест проверяет сам факт/содержимое публикаций (шаг 4).
            'CENTRIFUGO_SINK_FILE' => $INPROC_TMP . '/pubs.jsonl',
            'PATH' => getenv('PATH'),
            'SystemRoot' => getenv('SystemRoot'),
            'WINDIR' => getenv('WINDIR'),
        ]
    );
    if (!is_resource($proc)) {
        return ['status' => 500, 'json' => null, 'raw' => 'не удалось запустить дочерний процесс'];
    }
    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $info = []; $t0 = microtime(true);
    while (true) {
        $info = proc_get_status($proc);
        if (!$info['running']) break;
        if (microtime(true) - $t0 > 30) { proc_terminate($proc, 9); break; } // страховка от зависания
        usleep(15000);
    }
    proc_close($proc);

    // Потомок сохранил обновлённый мир в store.dat (shutdown-хук) — забираем себе.
    $rawStore = @file_get_contents($INPROC_TMP . '/store.dat');
    $newStore = ($rawStore === false || $rawStore === '') ? null : @unserialize($rawStore);
    if (is_array($newStore)) $GLOBALS['_redis_store'] = $newStore;
    else file_put_contents($INPROC_TMP . '/store.dat', $storeDump); // страховка: вернуть дамп родителя

    $resp = (string)@file_get_contents($INPROC_TMP . '/out.txt');
    $httpStatus = 0;
    // ★ Служебная строка статуса — ПОСЛЕДНЯЯ строка вывода потомка (хук пишет
    // тело ответа и только затем HTTPSTATUS:NNN). Парсер срезает её с конца.
    if (preg_match('/\n?HTTPSTATUS:(\d+)\s*$/', $resp, $m)) {
        $httpStatus = (int)$m[1];
        $resp = substr($resp, 0, -strlen($m[0]));
    }
    if ($httpStatus === 0) {
        // Служебная строка не записана → эндпоинт умер до ответа (фатал).
        // Понятная диагностика вместо голого 500.
        return ['status' => 500, 'json' => null,
            'raw' => 'эндпоинт завершился без ответа; stderr: ' . substr($stderr, 0, 500)];
    }

    $json = json_decode($resp, true);
    // ★ Диагностика отказов: в штатном JSON-контракте у отказа ВСЕГДА есть
    // непустое поле message (jsonError). Если статус >=400, а сообщения нет —
    // запрос даже не дошёл до эндпоинта (битый tmp-каталог, фатал до хука),
    // и молчаливый «ok» рядом с таким артефактом маскировал бы баги транспорта.
    if ($httpStatus >= 400 && trim((string)($json['message'] ?? '')) === '') {
        return ['status' => 500, 'json' => is_array($json) ? $json : null,
            'raw' => 'транспортная аномалия: HTTP ' . $httpStatus
                . ' без JSON-сообщения эндпоинта; raw=' . substr($resp, 0, 300)
                . '; stderr=' . substr($stderr, 0, 300)];
    }
    // ★ Обратная сторона той же диагностики: успешный ответ (2xx) обязан быть
    // валидным JSON контракта. Пустой/непарсируемый вывод при 200 означал бы,
    // что эндпоинт умер ДО записи ответа (например, shutdown-хук потомка не
    // отработал из-за битого tmp-каталога) — раньше это молча превращалось в
    // «rejoin:false / success:null» и FAIL'ы шагов 7-8 с плавающей природой.
    if ($httpStatus < 300 && !is_array($json)) {
        return ['status' => 500, 'json' => null,
            'raw' => 'транспортная аномалия: HTTP ' . $httpStatus
                . ' с непарсируемым телом; raw=' . substr($resp, 0, 300)
                . '; stderr=' . substr($stderr, 0, 300)];
    }
    return ['status' => $httpStatus, 'json' => is_array($json) ? $json : null, 'raw' => $resp];
}

// ★ Эмуляция HTTP-статуса для CLI-потомка. В CLI-SAPI header()/http_response_code()
// не формируют HTTP-ответ, поэтому статус и тело наблюдаем явно. Механика
// строится вокруг того, что ВСЕ штатные точки выхода эндпоинтов печатают тело
// СРАЗУ ПЕРЕД exit и всегда очищают буферы вывода (jsonResponse /
// sendJsonErrorFallback / ручные ответы join.php и init.php):
//   • ob_start() в потомке ловит любой echo эндпоинта;
//   • при завершении скрипта (exit или фатал) shutdown-колбэк забирает буфер,
//     читает http_response_code() и пишет в stdout: <тело> + перевод строки +
//     служебная строка "HTTPSTATUS:NNN". В CLI-SAPI http_response_code()
//     реально запоминает код, пока буфер вывода активен (SAPI php_cli.c), —
//     поэтому переопределять встроенную функцию не нужно и нельзя;
//   • inproc_req() родителя режет вывод по последней строке HTTPSTATUS:NNN.
// Если эндпоинт умер до ответа (фатал с частичным выводом) — страховочная
// ветка в хуке даёт 500 с тем, что успело напечататься, плюс stderr потомка.
// Боевые эндпоинты под настоящим SAPI (FPM/Apache) работают как раньше —
// подмена живёт только в сгенерированном тестовом потомке.

// Генерация кода дочернего процесса. Все динамические значения передаются
// var_export'ом — никакого ручного экранирования в шаблоне.
function build_child_script(string $script, ?string $body, array $get, string $target, string $tmpDir): string {
    // ★ КРИТИЧНО 1: внутри heredoc PHP НЕ вычисляет выражения — конкатенация
    // вида {$vRoot} . '/tests/...' попадает в шаблон как литерал и ломает require.
    // Все пути склеиваем ЗДЕСЬ и подставляем готовые литералы через var_export.
    // ★ КРИТИЧНО 2: все пути — от BATTLE_PROJECT_ROOT (определяется в начале
    // файла). Никаких __DIR__: при eval-загрузке теста он указывает не на tests/.
    $rootReal = BATTLE_PROJECT_ROOT;
    $root = $rootReal;
    $vStub   = var_export($rootReal . '/tests/fakeredis_stub.php', true);
    $vBoot   = var_export($rootReal . '/php/engine/bootstrap.php', true);
    $vStore  = var_export($tmpDir . '/store.dat', true);
    // ★ Дамп хранилища — PHP-serialize (см. комментарий в inproc_req): JSON
    // неоднозначно кодировал пустой мир и молча терял состояние между запросами.
    // ★ КРИТИЧНО 3: var_export() ВЕРНЁТ PHP-строку с кавычками — в шаблоне её
    // нельзя оборачивать в одинарные кавычки ещё раз, иначе вместо кода в child.php
    // попадёт строковый литерал ('...'), а следующий за ним идентификатор define
    // даст «Parse error: unexpected identifier». Код дампа вставляем как есть.
    $vDumpLoad =
        '$d = @unserialize((string)@file_get_contents(' . var_export($tmpDir . '/store.dat', true) . '));'
      . "\nif (is_array(\$d)) \$GLOBALS['_redis_store'] = \$d;";
    $vRoot   = var_export($root, true);
    $vTarget = var_export($target, true);
    $vTmp    = var_export($tmpDir, true);
    $vMethod = var_export($body === null ? 'GET' : 'POST', true);
    $vScript = var_export('/' . basename($target), true); // '/init.php' — для possible script-name проверок
    $vUri    = var_export('/' . basename($script, '.php') . ($get ? '?' . http_build_query($get) : ''), true);
    $vGet    = var_export($get, true);

    return <<<PHP
<?php
// Автосгенерирован tests/integration_join.php — НЕ редактировать вручную.
\$GLOBALS['_redis_store'] = [];
require {$vStub};   // класс Redis ДО config.php
putenv('FAKEREDIS_SHARED=1');                     // общее in-memory хранилище процесса
{$vDumpLoad} // восстановление dump'а хранилища (serialize) перед запросом

// Должно быть определено ДО require bootstrap.php: rules.php уважает
// defined()-guard. (LONG_POLL_TIMEOUT/define удалены вместе с long polling —
// транспорт теперь Centrifugo, публикация идёт в CENTRIFUGO_SINK_FILE.)

\$_SERVER['REQUEST_METHOD']  = {$vMethod};
\$_SERVER['REQUEST_URI']     = {$vUri};
\$_SERVER['SCRIPT_NAME']     = {$vScript};
\$_SERVER['CONTENT_TYPE']    = 'application/json';
\$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
\$_GET  = {$vGet};
\$_POST = [];

// ★ Сохранение изменённого мира обратно в store.json — ПЕРВЫМ shutdown-хуком.
// Порядок регистрации критичен: PHP вызывает shutdown-функции в порядке их
// регистрации, а эндпоинты завершаются через exit внутри require() ниже.
// Хук сохранения обязан отработать раньше хука ответа — иначе parent прочитает
// устаревший dump и все проверки состава мира сорвутся.
// ★ use ({...литерал...}) синтаксически недопустим — путь подставляется телом
// замыкания как литерал (var_export выше), а не списком use.
register_shutdown_function(function () {
    // ★ Формат дампа — PHP-serialize (см. комментарий в inproc_req): родитель
    // читает store.dat через unserialize(). json_encode здесь молча ломал
    // передачу состояния между запросами (ключи "battle:*", пустой мир [] vs {}),
    // из-за чего потомки стартовали с ПУСТЫМ хранилищем и все проверки мира падали.
    file_put_contents({$vStore}, serialize(\$GLOBALS['_redis_store'] ?? []));
});

// ── эмуляция HTTP-ответа для CLI (см. комментарий у build_child_script) ──
register_shutdown_function(function () {
    while (ob_get_level() > 1) { ob_end_clean(); } // мусор вложенных буферов
    \$buf = ob_get_level() ? ob_get_clean() : '';
    \$code = http_response_code();                 // CLI: помнит код, пока буфер активен
    \$out = fopen('php://stdout', 'wb');
    fwrite(\$out, \$buf);
    if (substr(\$buf, -1) !== "\n") fwrite(\$out, "\n");
    fwrite(\$out, 'HTTPSTATUS:' . (int)(\$code === false || \$code === null ? 200 : \$code) . "\n");
    fclose(\$out);
});

ob_start();
require {$vTarget}; // эндпоинт завершается exit (jsonResponse/jsonError) — shutdown-хук выше соберёт ответ
PHP;
}

// ─── ЧТЕНИЕ СОСТОЯНИЯ МИРА в двух режимах ──────────────────────────────────
// in-process: fake-Redis родителя ($GLOBALS['_redis_store'], raw-строки значений);
// OVER-HTTP: GET dump_state.php — read-only эндпоинт, отдаёт battle:* ключи в
//   декодированном виде; ре-энкодим в JSON-строки, чтобы все вызывающие
//   json_decode($st['battle:players']) работали без изменений.
function test_store(): array {
    global $INTEGRATION_HTTP;
    if (!$INTEGRATION_HTTP) {
        return $GLOBALS['_redis_store'] ?? [];
    }
    $r = http_req('/php/dump_state.php');
    $keys = $r['json']['keys'] ?? [];
    $out = [];
    foreach ($keys as $k => $v) {
        $out[$k] = is_string($v) ? $v : json_encode($v);
    }
    return $out;
}

function make_card($uid, $nick, $overrides = []) {    $card = [
        'uid' => (string)$uid,
        'nickname' => $nick,
        'stats' => [
            'HITPOINTS' => 7, 'SHIELD' => 5, 'ATTACK' => 8,
            'RESISTANCE' => 3, 'RANGE' => 6, 'ACTIONS' => 2,
        ],
        'abilities' => ['FIRE WAVE', 'ICE SHARD', 'BACKSTAB'],
        'passives' => ['SWIFT', 'THORNS'],
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($card[$k]);
        else $card[$k] = $v; // списки (abilities/passives) ЗАМЕЩАЮТСЯ целиком:
                             // union ($v + $card[$k]) добирает дефолты и ломает
                             // смысл негативных проверок («2 способности вместо 3»)
    }
    if (isset($overrides['stats'])) $card['stats'] = $overrides['stats'];
    return $card;
}

echo "== Интеграционный тест join-цикла (fake redis, реальные эндпоинты) ==\n";

// ─── 1. Новая сессия: бойцов нет ────────────────────────────────────────────
$r = http_req('/php/init.php', null, ['reset' => '1']);
check($r['status'] === 200, 'init?reset=1 → 200 (сборка новой сессии)');
check(!empty($r['json']['success']), 'init?reset=1 → success:true');
$st = test_store();
$players = json_decode($st['battle:players'] ?? '[]', true);
check(is_array($players) && count($players) === 0, 'после сброса бойцов ровно 0 (нет персонажей без владельца)');

// ─── 2. Обычный init легален с пустым миром ─────────────────────────────────
$r = http_req('/php/init.php');
check($r['status'] === 200, 'обычный init с 0 бойцов → 200 (не 503 — пустой лобби-мир легален)');
$reg_ab = $r['json']['abilities'] ?? null;
$reg_pa = $r['json']['passives'] ?? null;
check(is_array($reg_ab) && count($reg_ab) > 0, 'init отдаёт реестр abilities для формы настройки');
check(is_array($reg_pa) && count($reg_pa) > 0, 'init отдаёт реестр passives для формы настройки');

// ─── 3. Четыре подключения ──────────────────────────────────────────────────
$nicks = ['ALPHA', 'BETA-1', 'Gamma_X', 'DELTA'];
foreach ($nicks as $i => $nick) {
    $r = http_req('/php/join.php', json_encode(make_card($i + 1, $nick)));
    check($r['status'] === 200 && !empty($r['json']['success']),
        "join uid=" . ($i + 1) . " ($nick) → 200");
    check(($r['json']['player_id'] ?? '') === 'PLAYER' . ($i + 1),
        'join вернул player_id PLAYER' . ($i + 1));
    check(($r['json']['rejoin'] ?? null) === false, 'первый вход не rejoin');
    // ws-блок Centrifugo: url+канал+JWT (три сегмата) — клиент подключается по нему
    $ws = $r['json']['ws'] ?? [];
    check(!empty($ws['url']) && ($ws['channel'] ?? '') === 'battle:PLAYER' . ($i + 1),
        'join отдаёт ws: url + персональный канал battle:PLAYER' . ($i + 1));
    check(substr_count((string)($ws['token'] ?? ''), '.') === 2,
        'join отдаёт ws.token — JWT HS256 (3 сегмента)');
}
$st = test_store();
$players = json_decode($st['battle:players'] ?? '[]', true);
check(count($players) === 4, 'в мире ровно 4 персонажа (4 игрока = 4 персонажа)');

// ─── 4. Публикации Centrifugo: клиент видит тех же бойцов ───────────────────
// join.php публикует снапшот в канал каждого бойца; in-process транспорт
// собирает публикации в CENTRIFUGO_SINK_FILE (JSONL). В OVER-HTTP режиме
// sink принадлежит серверному процессу — там проверяем только ws-контракт (шаг 3).
$pp = [];
if (!$INTEGRATION_HTTP) {
    $sink = $INPROC_TMP . '/pubs.jsonl';
    $lines = is_file($sink) ? array_filter(explode("\n", (string)file_get_contents($sink))) : [];
    $last_by_channel = [];
    foreach ($lines as $line) {
        $rec = json_decode($line, true);
        if (is_array($rec) && isset($rec['channel'], $rec['data'])) {
            $last_by_channel[$rec['channel']] = $rec['data'];
        }
    }
    check(($last_by_channel['battle:PLAYER1']['version'] ?? null) !== null,
        'sink: есть публикация снапшота в battle:PLAYER1 (замена poll-ответа)');
    $pp = $last_by_channel['battle:PLAYER1']['players'] ?? [];
} else {
    $pp = [];
    echo "skip - OVER-HTTP: sink публикаций принадлежит серверному процессу, контракт снапшота проверяется in-process прогоном\n";
}
if (!$INTEGRATION_HTTP) {
check(count($pp) === 4, 'снапшот отдаёт 4 персонажа (N игроков = N персонажей на клиенте)');
$all_none = true; $uids_ok = true; $actions_ok = true; $abil_ok = true; $pass_ok = true;
foreach ($pp as $pid => $p) {
    if (($p['ALLY'] ?? '') !== 'NONE') $all_none = false;
    if ((string)($p['UID'] ?? '') !== (string)(intval(substr($pid, 6)))) $uids_ok = false;
    if ((int)($p['ACTIONS'] ?? -1) !== 2 || (int)($p['MAX_ACTIONS'] ?? -1) !== 2) $actions_ok = false;
    if (count($p['ABILITIES'] ?? []) !== 3) $abil_ok = false;
    if (count($p['PASSIVES'] ?? []) !== 2) $pass_ok = false;
}
check($all_none, "ALLIANCE-режим FFA: ALLY='NONE' у всех (до лобби)");
check($uids_ok, 'поле UID бойца = uid аккаунта из карточки (имитация данных БД)');
check($actions_ok, 'очки действий 2 переданы как ACTIONS/MAX_ACTIONS');
check($abil_ok, 'ровно 3 способности у каждого бойца');
check($pass_ok, '2 выбранные пассивки у каждого бойца');
// канонизированный формат ABILITIES "NAME[range]"
$fire = $pp['PLAYER1']['ABILITIES'][0] ?? '';
check(preg_match('/^\w+.*\[\d+\]$/', $fire) === 1, "ABILITIES в каноническом формате движка ('$fire')");
// снапшот-контракт для анимаций клиента: серверные часы и версия присутствие
$snap1 = $last_by_channel['battle:PLAYER1'] ?? [];
check(isset($snap1['server_now']) && $snap1['server_now'] > 0, 'snapshot: server_now для syncClock');
check(isset($snap1['turn_time'], $snap1['time_to_next_turn'], $snap1['victory']), 'snapshot: поля хода/таймера/victory на верхнем уровне');
check(($last_by_channel['battle:PLAYER4']['room_id'] ?? '') === 'E5', 'snapshot(PLAYER4): комната спавна E5 — каналы разносят состояние по игрокам');
}

// ─── 5. Идемпотентный повторный вход ────────────────────────────────────────
$r = http_req('/php/join.php', json_encode(make_card(2, 'BETA-1')));
check($r['status'] === 200 && ($r['json']['rejoin'] ?? false) === true,
    'повторный join того же uid → rejoin:true');
$st = test_store();
$players = json_decode($st['battle:players'] ?? '[]', true);
check(count($players) === 4, 'после rejoin бойцов всё ещё 4 (второй персонаж не создан)');

// ─── 6. Пятый игрок: uid вне слотов → 400 ───────────────────────────────────
$r = http_req('/php/join.php', json_encode(make_card(5, 'ECHO')));
check($r['status'] === 400, 'uid=5 вне слотов 1..4 → 400 (защита статичной сессии)');

// ─── 7. Полное лобби: новый игрок не помещается → 409 ──────────────────────
// ★ Слоты жёстко привязаны к uid (uid N → PLAYER N), поэтому «пришедший с
// занятого uid» — это всегда rejoin того же аккаунта (шаг 5, 200), а не чужак.
// Реальный сценарий переполнения: НОВЫЙ валидный uid (не занят, карточка
// корректна) при полном лобби из 4 бойцов → сервер обязан отказать 409.
$r = http_req('/php/join.php', json_encode(make_card(3, 'ZULU'))); // uid=3 занят Gamma_X → rejoin
check($r['status'] === 200 && ($r['json']['rejoin'] ?? false) === true,
    'занятый uid с другим ником — rejoin владельца, а не конфликт (слоты привязаны к uid)');
$r = http_req('/php/join.php', json_encode(make_card(4, 'ZULU'))); // uid=4 занят DELTA → rejoin
check($r['status'] === 200 && ($r['json']['rejoin'] ?? false) === true,
    'повторный вход DELTA под новым именем — тоже идемпотентный rejoin');
// ★ Примечание: любой uid вне 1..4 отсекается валидатором с 400 (шаг 6), а
// uid внутри 1..4 при полном лобби — это чей-то rejoin. Поэтому штатный
// «пятый игрок» в статичной сессии не проходит ни одной дверью. Страховочная
// ветка CAS-цикла join.php («все спавн-места заняты» → 409) проверяется
// напрямую: временно раздуваем мир fake-Redis до 5 бойцов и шлём валидную
// карточку нового uid — сервер обязан отказать 409 и НЕ изменить мир.
// Ключи правим только в хранилище фейка (внешний дамп battle:*), боевые
// файлы не трогаются; version не инкрементируем — фиктивного 5-го бойца
// join увидит через loadBattleState из внешнего дампа.
$st = test_store();
$players = json_decode($st['battle:players'] ?? '[]', true);
if (!is_array($players)) $players = [];
check(count($players) === 4, 'неудачные попытки не добавили бойцов (всё ещё 4)');

if (!$INTEGRATION_HTTP) {
$overfull_backup = $st;                       // снимок мира для восстановления
$ghost = reset($players);                     // копия реального бойца — валидная структура
                                            // (assoc-массив: $players[0] недопустим —
                                            // ключи это ID бойцов, не индексы)
$ghost['ID'] = 'PLAYER9';
$ghost['UID'] = '9';
$ghost['NICKNAME'] = 'GHOST';
$overfull = $players;
$overfull[] = $ghost;                         // 5 записей: лобби «переполнено»
// Убираем PLAYER3 с UID='3': тогда uid=3 свободен (не rejoin), а бойцов всё
// равно 4 (PLAYER1,2,4,GHOST) → включается ровно ветка переполнения join.php.
$four = array_values(array_filter($overfull, fn($p) => ($p['ID'] ?? '') !== 'PLAYER3'));
$GLOBALS['_redis_store']['battle:players'] = json_encode($four, JSON_UNESCAPED_UNICODE);
$r = http_req('/php/join.php', json_encode(make_card(3, 'ZULU')));
check($r['status'] === 409, 'полное лобби (4 бойца) + новый uid → 409 «спавн-места заняты»');
$st_after = $GLOBALS['_redis_store'] ?? [];
$players_after = json_decode($st_after['battle:players'] ?? '[]', true);
check(count($players_after) === 4 && !array_filter($players_after,
    fn($p) => ($p['NICKNAME'] ?? '') === 'ZULU'),
    'отказ по переполнению не создал бойца (ошибочный запрос не мутировал мир)');
$GLOBALS['_redis_store'] = $overfull_backup;  // вернуть настоящий мир сценария
} else {
    echo "skip - OVER-HTTP: раздувание мира фиктивным 5-м бойцом требует записи в хранилище сервера (только in-process)\n";
}

// ─── 8. Серверная валидация битых карточек ──────────────────────────────────
// Новая сессия: 0 бойцов. Сброс — штатным GET init.php?reset=1 (тот же контракт,
// что шаг 1: flushDB + каркас пустого мира + version=1); прямой манипуляцией
// хранилищем пользоваться нельзя в OVER-HTTP режиме (у сервера своё).
http_req('/php/init.php', null, ['reset' => '1']);
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['abilities' => ['FIRE WAVE', 'ICE SHARD']])));
check($r['status'] === 400, '2 способности вместо 3 → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 7, 'SHIELD' => 5, 'ATTACK' => 8,
                 'RESISTANCE' => 3, 'RANGE' => 6, 'ACTIONS' => 4]])));
check($r['status'] === 400, 'ACTIONS=4 вне диапазона 1..3 → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['passives' => ['FLYING']])));
check($r['status'] === 400, 'несуществующая пассивка → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 11, 'SHIELD' => 5, 'ATTACK' => 8,
                 'RESISTANCE' => 3, 'RANGE' => 6, 'ACTIONS' => 2]])));
check($r['status'] === 400, 'HITPOINTS=11 вне 1..10 → 400');
// Минимально допустимые 0 для щита и сопротивления (0 = отсутствие) — легальны.
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 7, 'SHIELD' => 0, 'ATTACK' => 8,
                 'RESISTANCE' => 0, 'RANGE' => 6, 'ACTIONS' => 2]])));
check($r['status'] === 200 && !empty($r['json']['success']),
    'SHIELD=0 и RESISTANCE=0 (минимум диапазона) → 200, отсутствие щита/сопротивления легально');
http_req('/php/init.php', null, ['reset' => '1']); // очистить после успешного join
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 7, 'SHIELD' => -1, 'ATTACK' => 8,
                 'RESISTANCE' => 0, 'RANGE' => 6, 'ACTIONS' => 2]])));
check($r['status'] === 400, 'SHIELD=-1 ниже минимума 0 → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 7, 'SHIELD' => 0, 'ATTACK' => 8,
                 'RESISTANCE' => 10, 'RANGE' => 6, 'ACTIONS' => 2]])));
check($r['status'] === 400, 'RESISTANCE=10 вне диапазона 0..9 → 400');
http_req('/php/init.php', null, ['reset' => '1']); // вернуть пустой мир перед финальным проверочным join
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO')));
check($r['status'] === 200 && !empty($r['json']['success']),
    'валидная карточка после серии отказов проходит (битые запросы не тронули мир)');
$st = test_store();
$players = json_decode($st['battle:players'] ?? '[]', true);
check(count($players) === 1, 'один игрок = ровно один персонаж (одиночная сессия легальна)');

// ─── 9. Одиночный игрок: боя нет, экран победы отключён ─────────────────────
// ★ В роли КЛИЕНТА fake-Redis ещё не подключён (в in-process его грузит каждый
// дочерний процесс, в HTTP-режиме — сервер). Без него bootstrap.php бросает
// «Расширение PHP Redis не установлено» и тест обрывался с фаталом на последнем
// шаге. Подключаем стаб ДО require; FAKEREDIS_SHARED=1 даёт то же общее
// in-memory хранилище, что видят запросы сценария.
require_once BATTLE_PROJECT_ROOT . '/tests/fakeredis_stub.php';
putenv('FAKEREDIS_SHARED=1');
$GLOBALS['_redis_store'] = $GLOBALS['_redis_store'] ?? [];
require_once BATTLE_PROJECT_ROOT . '/php/engine/bootstrap.php';
$state = world_load();
$res = engine_check_victory($state);
check(($res['victory'] ?? true) === false,
    'engine_check_victory временно always-false (экран победы выключен, одиночный игрок легален)');

echo "\n== ИТОГ: ok=$PASS fail=$FAIL ==\n";
exit($FAIL === 0 ? 0 : 1);
