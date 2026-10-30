<?php
/**
 * ИНТЕГРАЦИОННЫЙ ТЕСТ: HTTP-контракт init.php → join.php → poll.php (Этап 1).
 *
 * Запускается ВСТРОЕННЫМ PHP-сервером (`php -n -S` с auto_prepend_file=
 * tests/fakeredis_stub.php): каждый запрос — отдельная обработка реального
 * эндпоинта, а «Redis» живёт в суперглобале одного процесса сервера. Проверяется
 * ровно то, что нельзя проверить юнит-харнессом: сквозной цикл подключения
 * игроков через HTTP, коды ответов и состав мира после каждого шага.
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

$PASS = 0; $FAIL = 0;
function check($cond, $label) {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "ok   - $label\n"; }
    else { $FAIL++; echo "FAIL - $label\n"; }
}

// ─── Хелперы «HTTP-запросов» ────────────────────────────────────────────────
// php://input подменить напрямую нельзя — эмулируем тело POST через stream-контекст.
function http_req(string $script, ?string $body = null, array $get = []): array {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . '/' . basename($script);
    if ($get) $url .= '?' . http_build_query($get);
    $ctx = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    $json = json_decode((string)$resp, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$resp];
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
require_once __DIR__ . '/../php/engine/bootstrap.php';
$state = world_load();
$res = engine_check_victory($state);
check(($res['victory'] ?? true) === false,
    'engine_check_victory временно always-false (экран победы выключен, одиночный игрок легален)');

echo "\n== ИТОГ: ok=$PASS fail=$FAIL ==\n";
exit($FAIL === 0 ? 0 : 1);
