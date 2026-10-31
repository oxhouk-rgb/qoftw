<?php
$GLOBALS['_redis_store'] = [];
require '/workspace/tests/fakeredis_stub.php';
putenv('FAKEREDIS_SHARED=1');
define('LONG_POLL_TIMEOUT', 0);
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/init.php?reset=1';
$_SERVER['SCRIPT_NAME']     = '/init.php';
$_SERVER['CONTENT_TYPE']    = 'application/json';
$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
$_GET  = ['reset' => '1'];
$_POST = [];
ob_start();
try {
    require '/workspace/php/init.php';
    $out = ob_get_clean();
    echo 'HTTPSTATUS:' . (http_response_code() ?: 200) . "\n" . $out;
} catch (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    echo 'HTTPSTATUS:500' . "\n" . json_encode(['fatal' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
