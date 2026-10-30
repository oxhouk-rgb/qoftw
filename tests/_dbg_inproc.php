<?php
// Диагностика: воспроизводит inproc_req через proc_open и печатает stderr потомка.
$GLOBALS['_redis_store'] = [];
require __DIR__ . '/fakeredis_stub.php';
$tmp = __DIR__ . '/_dbg_tmp4'; @mkdir($tmp);
file_put_contents("$tmp/store.json", json_encode((object)[]));
file_put_contents("$tmp/body.json", '');
$target = realpath(__DIR__ . '/../php/init.php');

$src = file_get_contents(__DIR__ . '/integration_join.php');
$i = strpos($src, 'function build_child_script'); $j = strpos($src, '{', $i); $d = 0;
for ($k=$j; $k<strlen($src); $k++){ if($src[$k]==='{')$d++; elseif($src[$k]==='}'){$d--; if($d===0){$code=substr($src,$i,$k-$i+1);break;}}}
eval($code);
file_put_contents("$tmp/child.php", build_child_script('init.php', null, ['reset'=>'1'], $target, $tmp));

$descriptors = [0=>['pipe','r'], 1=>['file',"$tmp/out.txt",'w'], 2=>['pipe','w']];
$proc = proc_open(['php','-n','-d','error_reporting=0', "$tmp/child.php"], $descriptors, $pipes, dirname(__DIR__),
    ['BATTLE_TEST_INPUT_FILE'=>"$tmp/body.json", 'PATH'=>getenv('PATH')]);
fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
while (true) { $info = proc_get_status($proc); if (!$info['running']) break; usleep(15000); }
echo "EXIT CODE: {$info['exitcode']}\n";
echo "STDERR:\n$stderr\n";
echo "STDOUT FILE:\n" . substr(file_get_contents("$tmp/out.txt"), 0, 300) . "\n";
