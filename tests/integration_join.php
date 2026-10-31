<?php
/**
 * ИНТЕГРАЦИОННЫЙ ТЕСТ: HTTP-контракт init.php → join.php → poll.php (Этап 1).
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
 *   3. POST join.php ×4        — четыре игрока заселяют слоты A1/A5/E1/E5;
 *   4. poll.php                — N игроков = N персонажей у клиента; ALLY='NONE'; UID из карточки;
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
if (php_sapi_name() === 'cli' && in_array('--serve', $argv ?? [], true)) {
    integration_serve_router();
    exit(0);
}

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

function integration_serve_router(): void {
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
    file_put_contents($INPROC_TMP . '/store.dat', serialize($GLOBALS['_redis_store']));
    file_put_contents($INPROC_TMP . '/body.json', $body ?? '');
    @unlink($INPROC_TMP . '/out.txt');

    file_put_contents($INPROC_TMP . '/child.php',
        build_child_script($script, $body, $get, $target, $INPROC_TMP));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $INPROC_TMP . '/out.txt', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(
        // ★ БЕЗ флага -n: он отключает загрузку php.ini и всех неисключимых
        // расширений (ctype/json и т.п.) — боевой код php/join.php использует
        // ctype_digit() и падал с «Call to undefined function». Расширения из
        // системного ini безвредны: fake-Redis объявляет класс Redis ДО config.php
        // и только если он ещё не определён (class_exists guard).
        // error_reporting=0 глушит штатные warning'и CLI-SAPI (header() и пр.).
        ['php', '-d', 'error_reporting=0', $INPROC_TMP . '/child.php'],
        $descriptors, $pipes,
        BATTLE_PROJECT_ROOT,
        ['BATTLE_TEST_INPUT_FILE' => $INPROC_TMP . '/body.json', 'PATH' => getenv('PATH')]
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
// defined()-guard, а poll.php с LONG_POLL_TIMEOUT=0 отвечает сразу
// (в живом сервере long-poll держал бы соединение 25 с).
define('LONG_POLL_TIMEOUT', 0);

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

function make_card($uid, $nick, $overrides = []) {
    $card = [
        'uid' => (string)$uid,
        'nickname' => $nick,
        'stats' => [
            'HITPOINTS' => 7, 'SHIELD' => 5, 'ATTACK' => 8,
            'DEFENCE' => 4, 'RESISTANCE' => 3, 'RANGE' => 6, 'ACTIONS' => 2,
        ],
        'abilities' => ['FIRE WAVE', 'ICE SHARD', 'BACKSTAB'],
        'passives' => ['SWIFT', 'THORNS'],
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($card[$k]);
        elseif (is_array($v) && isset($card[$k]) && is_array($card[$k])) $card[$k] = $v + $card[$k];
        else $card[$k] = $v;
    }
    if (isset($overrides['stats'])) $card['stats'] = $overrides['stats'];
    return $card;
}

echo "== Интеграционный тест join-цикла (fake redis, реальные эндпоинты) ==\n";

// ─── 1. Новая сессия: бойцов нет ────────────────────────────────────────────
$r = http_req('/php/init.php', null, ['reset' => '1']);
check($r['status'] === 200, 'init?reset=1 → 200 (сборка новой сессии)');
check(!empty($r['json']['success']), 'init?reset=1 → success:true');
$st = $GLOBALS['_redis_store'] ?? [];
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
}
$st = $GLOBALS['_redis_store'] ?? [];
$players = json_decode($st['battle:players'] ?? '[]', true);
check(count($players) === 4, 'в мире ровно 4 персонажа (4 игрока = 4 персонажа)');

// ─── 4. poll.php: клиент видит тех же бойцов ────────────────────────────────
$r = http_req('/php/poll.php', null, ['wait' => '0']);
check($r['status'] === 200, 'poll → 200');
$pp = $r['json']['players'] ?? [];
check(count($pp) === 4, 'poll отдаёт 4 персонажа (N игроков = N персонажей на клиенте)');
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

// ─── 5. Идемпотентный повторный вход ────────────────────────────────────────
$r = http_req('/php/join.php', json_encode(make_card(2, 'BETA-1')));
check($r['status'] === 200 && ($r['json']['rejoin'] ?? false) === true,
    'повторный join того же uid → rejoin:true');
$st = $GLOBALS['_redis_store'] ?? [];
$players = json_decode($st['battle:players'] ?? '[]', true);
check(count($players) === 4, 'после rejoin бойцов всё ещё 4 (второй персонаж не создан)');

// ─── 6. Пятый игрок: uid вне слотов → 400 ───────────────────────────────────
$r = http_req('/php/join.php', json_encode(make_card(5, 'ECHO')));
check($r['status'] === 400, 'uid=5 вне слотов 1..4 → 400 (защита статичной сессии)');

// ─── 7. Полное лобби + попытка занять чужой слот другим uid → 409 ───────────
$r = http_req('/php/join.php', json_encode(make_card(1, 'ZULU'))); // uid=1 занят ALPHA
check($r['status'] === 409, 'слот занят другим ником → 409 (никнейм занят)');
$st = $GLOBALS['_redis_store'] ?? [];
$players = json_decode($st['battle:players'] ?? '[]', true);
check(count($players) === 4, 'неудачные попытки не добавили бойцов (всё ещё 4)');

// ─── 8. Серверная валидация битых карточек ──────────────────────────────────
http_req('/php/init.php', json_encode(['reset' => true])); // новая сессия: 0 бойцов
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['abilities' => ['FIRE WAVE', 'ICE SHARD']])));
check($r['status'] === 400, '2 способности вместо 3 → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 7, 'SHIELD' => 5, 'ATTACK' => 8, 'DEFENCE' => 4,
                 'RESISTANCE' => 3, 'RANGE' => 6, 'ACTIONS' => 4]])));
check($r['status'] === 400, 'ACTIONS=4 вне диапазона 1..3 → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['passives' => ['FLYING']])));
check($r['status'] === 400, 'несуществующая пассивка → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO',
    ['stats' => ['HITPOINTS' => 11, 'SHIELD' => 5, 'ATTACK' => 8, 'DEFENCE' => 4,
                 'RESISTANCE' => 3, 'RANGE' => 6, 'ACTIONS' => 2]])));
check($r['status'] === 400, 'HITPOINTS=11 вне 1..10 → 400');
$r = http_req('/php/join.php', json_encode(make_card(1, 'SOLO')));
check($r['status'] === 200 && !empty($r['json']['success']),
    'валидная карточка после серии отказов проходит (битые запросы не тронули мир)');
$st = $GLOBALS['_redis_store'] ?? [];
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
$prevLongPoll = defined('LONG_POLL_TIMEOUT') ? null : true;
if ($prevLongPoll !== null) define('LONG_POLL_TIMEOUT', 0); // страховка на случай прямого require эндпоинтов
require_once BATTLE_PROJECT_ROOT . '/php/engine/bootstrap.php';
$state = world_load();
$res = engine_check_victory($state);
check(($res['victory'] ?? true) === false,
    'engine_check_victory временно always-false (экран победы выключен, одиночный игрок легален)');

echo "\n== ИТОГ: ok=$PASS fail=$FAIL ==\n";
exit($FAIL === 0 ? 0 : 1);
