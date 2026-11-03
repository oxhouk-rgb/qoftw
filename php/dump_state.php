<?php
/**
 * dump_state.php — диагностика: дампа ключей battle:* (ТОЛЬКО ЧТЕНИЕ).
 *
 * Страница diag.html и интеграционный тест (режим OVER-HTTP) используют её,
 * чтобы видеть фактическое состояние мира в Redis/двойнике без доступа к
 * самому хранилищу. Записи не производит никогда (mirrors идеологию debug.php:
 * диагностика ничего не мутирует).
 */

require_once __DIR__ . '/engine/bootstrap.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $redis = getRedis();
    $keys = $redis->keys(REDIS_PREFIX . '*');
    $out = [];
    foreach ($keys as $k) {
        $v = $redis->get($k);
        if ($v === false) { // не get-тип (например list/hash) — пропускаем: штатный мир их не пишет
            continue;
        }
        $decoded = json_decode($v, true);
        $out[$k] = ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? $v : $decoded;
    }
    jsonResponse(['success' => true, 'keys' => $out]);
} catch (Throwable $e) {
    sendJsonErrorFallback('dump_state: ' . $e->getMessage(), 500);
}
