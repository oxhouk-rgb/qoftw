<?php
/**
 * tools/ticker.php — Демон серверного тика мира (драйвер вместо poll.php).
 *
 * Исторически тик движка (движение, эффекты, ход мира) жил внутри цикла
 * php/poll.php: мир двигался только пока клиенты держат long-poll соединения.
 * После перехода на Centrifugo (ноябрь 2026) доставка снапшотов — push, и
 * тик обязан иметь СОБСТВЕННЫЙ драйвер: этот процесс.
 *
 * Каждый виток: world_tick_once() (load → turn_tick → CAS-save) → при смене
 * версии публикация снапшотов всем бойцам в Centrifugo; плюс heartbeat-публикация
 * не реже раза в секунду (поздний подписчик получает состояние ≤1 с, а UI
 * обновляет таймер хода). Метка живости battle:ticker_beat — для диагностики
 * в init.php.
 *
 * Запуск (демоном/в окне):   php tools/ticker.php
 * Параметры:                 --interval=100   виток, мс (ход мира 5 с,
 *                                              анимации 3 кл/с — 100 мс достаточно)
 *                            --heartbeat=1000 heartbeat-пауза, мс
 *                            --iterations=N   N витков и выход (для тестов; 0 = бессрочно)
 *
 * ★ Один экземпляр! Параллельные копии допустимы кодом (CAS не даёт затиранию),
 * но плодят дубли-публикации; файловое состояние отсутствует — только Redis.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "ticker.php — только CLI (демон тика), не эндпоинт.\n");
    exit(1);
}

require_once __DIR__ . '/../php/engine/bootstrap.php';

$opts = getopt('', ['interval::', 'heartbeat::', 'iterations::']);
$interval_ms = max(10, (int)($opts['interval'] ?? 100));
$heartbeat_ms = max($interval_ms, (int)($opts['heartbeat'] ?? 1000));
$iterations = isset($opts['iterations']) ? (int)$opts['iterations'] : 0;

echo "[ticker] старт: interval={$interval_ms}ms heartbeat={$heartbeat_ms}ms"
    . ($iterations ? " iterations={$iterations}" : " (бессрочно)") . "\n";

$last_state = null;
$last_published_version = -1;
$last_heartbeat_at = 0.0;
$done = 0;

while (true) {
    try {
        // ★ Устойчивость к сбоям хранилища (наследие poll.php): единичные
        // исключения Redis проглатываем, цикл продолжает; ответственный читатель
        // — Centrifugo, соединение клиента тут не рвётся.
        [$state, $version, $changed] = world_tick_once();
        if ($state !== null) $last_state = $state;
        else $version = world_version(); // CAS-гонка: виток повторится на свежем load

        ticker_beat_touch();

        $now = microtime(true);
        $need_publish = ($last_state !== null)
            && ($version !== $last_published_version
                || ($now - $last_heartbeat_at) * 1000 >= $heartbeat_ms);
        if ($need_publish) {
            battle_publish_world($last_state, $version);
            $last_published_version = $version;
            $last_heartbeat_at = $now;
        }
    } catch (Throwable $e) {
        usleep(200000); // 200ms пауза после сбоя — следующий виток
        if (getenv('TICKER_VERBOSE_ERRORS')) {
            fwrite(STDERR, "[ticker] сбой витка: " . $e->getMessage() . "\n");
        }
    }

    $done++;
    if ($iterations > 0 && $done >= $iterations) break;
    usleep($interval_ms * 1000);
}

echo "[ticker] остановлен после {$done} витков\n";
