<?php
/**
 * test_lost_update.php — регрессия на «потерю пакета» (серверная половина).
 *
 * Проверяет CAS-механизм world_save_cas/saveBattleState против РЕАЛЬНОГО Redis:
 *   1. чистая запись проходит и инкрементирует версию;
 *   2. запись с устаревшей ожидаемой версий ОТКЛОНЯЕТСЯ (не затирает мир);
 *   3. после rebase (перечитать→замутить→сохранить) обе мутации сосуществуют —
 *      старый lost-update (poll-тик перетирает действие игрока) невозможен;
 *   4. ack-журнал идемпотентности round-trip.
 *
 * Запуск: php tests/test_lost_update.php (redis-server должен быть доступен).
 */

require_once __DIR__ . '/../php/engine/bootstrap.php';

$fail = 0;
function check($name, $cond) {
    global $fail;
    echo ($cond ? "OK   " : "FAIL ") . $name . "\n";
    if (!$cond) $fail++;
}

// Чистый старт
$redis = getRedis();
$redis->flushDB();
world_save(null); // сброс статик-кэша hash

$state = [
    'players' => ['p1' => ['NAME' => 'Tester', 'HP' => 10, 'ACTIONS' => 2, 'STATUS' => 'IDLE', 'CUR_POSITION_XY' => '1,1', 'TURN' => 0, 'ABILITIES' => [], 'ROOM_ID' => 'R1']],
    'objects' => [], 'traps' => [], 'npcs' => [], 'room' => ['room_id' => 'R1'],
    'turn_time' => time(), 'projectiles' => [], 'version' => 0, 'log' => [], 'rooms' => [],
];
world_init($state);
$v0 = world_version();

// 1) Чистый CAS-проход
[$s, $ver] = world_load_versioned();
check('load_versioned читает текущую версию', $ver === $v0);
$s['players']['p1']['ACTIONS']--; // «действие игрока»
[$ok, $fresh] = world_save_cas($s, $ver);
check('CAS: запись с актуальной версией проходит', $ok === true);
check('CAS: версия выросла', world_version() > $v0);
$reloaded = world_load();
check('CAS: мутация видна в мире', $reloaded['players']['p1']['ACTIONS'] === 1);

// 2) Устаревшая ожидаемая версия -> отказ (защита от lost-update)
$stale_state = $reloaded;                 // снапшот ДО чужой записи
$stale_version = $ver;                    // версия, которую снапшот считает актуальной
$stale_state['players']['p1']['HP'] = 999; // «тик poll.php» хочет записать старое+своё
[$ok2, $fresh2] = world_save_cas($stale_state, $stale_version);
check('CAS: запись с устаревшей версией ОТКЛОНЕНА', $ok2 === false);
$after_race = world_load();
check('CAS:rejected write НЕ испортила мир (ACTIONS цел)', $after_race['players']['p1']['ACTIONS'] === 1);
check('CAS: rejected write НЕ применила чужие данные (HP=999 отсутствует)', $after_race['players']['p1']['HP'] !== 999);
check('CAS: при отказе возвращён свежий state', isset($fresh2['players']['p1']));

// 3) Rebase: повтор мутации поверх свежего состояния проходит и объединяет изменения
$rebased = $fresh2;
$rebased['players']['p1']['HP'] = 999; // та же мутация тика, но на свежем снапшоте
[$ok3, ] = world_save_cas($rebased, (int)$rebased['version']);
check('CAS: rebase-запись проходит', $ok3 === true);
$final = world_load();
check('Rebase: обе мутации сосуществуют (ACTIONS=1 и HP=999)',
    $final['players']['p1']['ACTIONS'] === 1 && $final['players']['p1']['HP'] === 999);

// 4) Идемпотентность ack
world_action_ack_set('p1', 'act_1', ['success' => true, 'message' => 'тест', 'version' => world_version()]);
$ack = world_action_ack_get('p1', 'act_1');
check('ACK: round-trip сохранён', is_array($ack) && $ack['success'] === true);
check('ACK: неизвестный id -> null', world_action_ack_get('p1', 'nope') === null);

exit($fail === 0 ? 0 : 1);
