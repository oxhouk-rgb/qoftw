<?php
/**
 * engine/publish.php — Транспортная прослойка Centrifugo (сервер → клиент).
 *
 * Замена удалённому long polling (переход ноябрь 2026, решение автора — см.
 * ARCH.md §1): мир тикает демон tools/ticker.php, каждый изменённый снапшот
 * публикуется в персональный канал игрока battle:<PLAYER_ID>; клиент
 * (js/realtime.js) подписывается на свой канал и получает снапшоты push-ем.
 *
 * Публикация — через HTTP API Centrifugo (POST /api, заголовок x-api-key):
 * не требует от PHP ни curl (stream context), ни присутствия в Redis-движке
 * Centrifugo. ★ Публикация fail-soft: недоступный Centrifugo НЕ должен
 * ронять тик/действие/подключение — мир живёт в Redis, доставка восстановится
 * переподключением клиента (следующая публикация/heartbeat донесёт состояние).
 *
 * Тестовый режим: переменная окружения CENTRIFUGO_SINK_FILE — вместо сети
 * записи публикуются строками JSONL в файл (tests/integration_join.php).
 *
 * Контракт снапшота в data — тот же flat-контракт build_snapshot_response()
 * (js/render.js render(data) не менялся).
 */

require_once __DIR__ . '/snapshot.php';

// ─── Константы транспорта (guard-определения, как в rules.php) ──────────────
// Значения по умолчанию соответствуют локальному dev-прогону Centrifugo
// (config: client.token.hmac_secret_key / http_api.key). Переопределяются
// переменными окружения CENTRIFUGO_* — для стендов вне localhost.
$_cf_define = function (string $name, string $env, $default): void {
    if (!defined($name)) {
        $v = getenv($env);
        define($name, ($v === false || $v === '') ? $default : $v);
    }
};
$_cf_define('CENTRIFUGO_API_URL', 'CENTRIFUGO_API_URL', 'http://127.0.0.1:8000/api');
$_cf_define('CENTRIFUGO_WS_URL', 'CENTRIFUGO_WS_URL', 'ws://127.0.0.1:8000/connection/websocket');
$_cf_define('CENTRIFUGO_API_KEY', 'CENTRIFUGO_API_KEY', 'my_dev_api_key');
$_cf_define('CENTRIFUGO_TOKEN_SECRET', 'CENTRIFUGO_TOKEN_SECRET', 'my_dev_secret_key');
$_cf_define('CENTRIFUGO_TOKEN_TTL', 'CENTRIFUGO_TOKEN_TTL', 86400); // сек жизни user-токена
unset($_cf_define);

if (!function_exists('battle_channel')) {
    /** Персональный канал игрока: namespace battle (граница ':') + id бойца. */
    function battle_channel($player_id) {
        return 'battle:' . $player_id;
    }
}

if (!function_exists('centrifugo_b64url')) {
    function centrifugo_b64url($s) {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}

if (!function_exists('centrifugo_user_token')) {
    /**
     * User-токен Centrifugo (HS256 JWT) для бойца: sub = player_id,
     * channels = [его персональный канал] — подписка на чужие каналы
     * этим токеном невозможна (namespace battle: allow_user_limited_channels).
     */
    function centrifugo_user_token($player_id) {
        $header = centrifugo_b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = centrifugo_b64url(json_encode([
            'sub' => (string)$player_id,
            'exp' => time() + (int)CENTRIFUGO_TOKEN_TTL,
            'channels' => [battle_channel($player_id)],
        ]));
        $sig = centrifugo_b64url(hash_hmac('sha256', $header . '.' . $payload,
            CENTRIFUGO_TOKEN_SECRET, true));
        return $header . '.' . $payload . '.' . $sig;
    }
}

if (!function_exists('centrifugo_ws_payload')) {
    /**
     * WS-параметры для клиента (поле ws в ответах join.php): url + токен +
     * канал. Клиент подключается ОДИН раз на канал — персональный, состав
     * комнат его не касается (снапшот уже отфильтрован сервером).
     */
    function centrifugo_ws_payload($player_id) {
        return [
            'url' => CENTRIFUGO_WS_URL,
            'channel' => battle_channel($player_id),
            'token' => centrifugo_user_token($player_id),
            'expires_in' => (int)CENTRIFUGO_TOKEN_TTL,
        ];
    }
}

if (!function_exists('centrifugo_publish')) {
    /**
     * Опубликовать данные в канал. Fail-soft: всегда возвращает bool, никогда
     * не бросает — вызывающие пути (тик/действие/join) не должны умирать из-за
     * недоступной шины доставки.
     * @return bool true — подтверждено (или записано в тестовый sink)
     */
    function centrifugo_publish($channel, $data) {
        $body = json_encode([
            'method' => 'publish',
            'params' => ['channel' => $channel, 'data' => $data],
        ]);

        $sink = getenv('CENTRIFUGO_SINK_FILE');
        if ($sink !== false && $sink !== '') {
            return file_put_contents($sink, json_encode([
                'channel' => $channel, 'data' => $data,
            ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nx-api-key: " . CENTRIFUGO_API_KEY . "\r\n",
            'content' => $body,
            'timeout' => 0.7,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents(CENTRIFUGO_API_URL, false, $ctx);
        if ($resp === false) return false;
        $json = json_decode($resp, true);
        return is_array($json) && isset($json['result']);
    }
}

if (!function_exists('battle_publish_world')) {
    /**
     * Опубликовать текущий мир всем бойцам: снапшот собирается отдельно на
     * каждого (комнатная фильтрация + MOVE_RANGE — прежняя логика poll-ответа),
     * доставка — в персональный канал. Возвращает число успешных публикаций.
     */
    function battle_publish_world($state, $version) {
        $sent = 0;
        foreach ($state['players'] as $pid => $p) {
            if (empty($p)) continue;
            $snapshot = build_snapshot_response($state, $version, $pid);
            if (centrifugo_publish(battle_channel($pid), $snapshot)) $sent++;
        }
        return $sent;
    }
}

if (!function_exists('ticker_beat_touch')) {
    /**
     * Метка живости тикового демона (battle:ticker_beat, мбсек). Пишется КАЖДЫЙ
     * виток без инкремента версии; init.php проверяет её и предупреждает, если
     * демон молчит (иначе мир «завис» без единого объяснения — тик теперь не в
     * HTTP-запросах клиентов).
     */
    function ticker_beat_touch() {
        getRedis()->set(REDIS_TICKER_BEAT, (string)microtime(true));
    }
}

if (!function_exists('ticker_beat_age')) {
    /** Возраст метки тика (сек); null — метки нет вообще (демон не запускался). */
    function ticker_beat_age() {
        $raw = getRedis()->get(REDIS_TICKER_BEAT);
        if ($raw === false || $raw === null || $raw === '') return null;
        return max(0, microtime(true) - (float)$raw);
    }
}
