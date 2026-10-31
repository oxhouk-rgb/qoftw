<?php
$src = file_get_contents(__DIR__ . '/integration_join.php');
eval('?>' . substr($src, 0, strpos($src, 'echo "== Интеграционный тест')));
$INPROC_TMP = sys_get_temp_dir() . '/dbgz_' . getmypid();
mkdir($INPROC_TMP, 0777, true);
$GLOBALS['_redis_store'] = [];
http_req('init.php', null, ['reset' => '1']);
foreach (['ALPHA','BETA-1','Gamma_X','DELTA'] as $i => $nick) {
    http_req('join.php', json_encode(make_card($i+1, $nick)));
}
file_put_contents("$INPROC_TMP/keep.txt", "tmp=$INPROC_TMP\n");
// Запрос с БИТОЙ карточкой — и сохранить child.php для редебага
$card = make_card(1, 'SOLOX', ['abilities' => ['FIRE WAVE','ICE SHARD']]);
$body = json_encode($card);
$target = realpath(BATTLE_PROJECT_ROOT . '/php/join.php');
file_put_contents($INPROC_TMP . '/store.dat', serialize($GLOBALS['_redis_store']));
file_put_contents($INPROC_TMP . '/body.json', $body);
$childCode = build_child_script('join.php', $body, [], $target, $INPROC_TMP);
file_put_contents('/tmp/dbg_join_child.php', $childCode);
register_shutdown_function(function () use ($INPROC_TMP) {
    foreach (glob($INPROC_TMP . '/*') ?: [] as $f) @unlink($f);
    // rmdir skipped for debug
});
$r = inproc_req('join.php', $body, []);
echo "status={$r['status']} raw=" . substr($r['raw'],0,300) . "\n";
