<?php
/**
 * poll.php — Long polling (тонкий слой).
 *
 * Цикл: turn_tick (движение/анимации) → turn_check_advance (ход раз в TURN_DURATION сек)
 * → сравнение версии. Если версия не изменилась и клиентский version ==
 * серверный — держим соединение до LONG_POLL_TIMEOUT; при изменении версии
 * отдаём мгновенно. Сохранение только через world_save().
 *
 * Контракт ответа (плоский, без обёртки 'data' — сверено с js/game.js):
 *   version, room, grid?, objects, traps, npcs, players, projectiles, log,
 *   turn_time, turn_duration, time_to_next_turn, victory, rules
 */

require_once __DIR__ . '/engine/bootstrap.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$client_version = isset($_GET['v']) ? (int)$_GET['v'] : 0;
$player_id = $_GET['player'] ?? null;

// Длинное соединение: снимаем лимит выполнения скрипта (как в старом poll.php),
// иначе настенд с дефолтным max_execution_time=30 убьёт long-poll цикл.
set_time_limit(LONG_POLL_TIMEOUT + 10);

    $timeout = LONG_POLL_TIMEOUT; // сек
    $start_time = time();

    try {
        while (true) {
            // ★ Устойчивость long-poll к сбоям хранилища: раньше любое исключение
            // внутри цикла (например временный READ_TIMEOUT на перегруженном Redis)
            // улетало в catch и ОБРЫВАЛО соединение без JSON-ответа. Клиент
            // воспринимал это как сетевую ошибку, а параллельно отправленное в этот
            // момент действие выглядело «потерянным». Теперь единичные сбои
            // проглатываются, цикл продолжает тикать; ответ гарантирован по таймауту.
            try {
                $state = world_load();

                // ★ turn_time = серверный дедлайн хода. Если init.php/предыдущий
                // процесс его не задал (0) — фиксируем сейчас и сохраняем ОДИН раз,
                // иначе build_poll_response будет отдавать TURN_DURATION вместо
                // обратного отсчёта, а turn_check_advance на первой же проверке
                // устроит шторм версий «ход каждый тик» (баг залипшего таймера).
                if (!isset($state['turn_time']) || (int)$state['turn_time'] <= 0) {
                    $state['turn_time'] = time();
                    world_save($state);
                }

                // Серверный тик движка (включая ход мира turn_check_advance — он
                // внутри turn_tick; повторный вызов отсюда устроил бы двойной
                // advance при редких опросах).
                // ★ ВАЖНО: тик выполняется для ЛЮБОГО poll-клиента, даже если он
                // не передал ?player= или передал неизвестный id. Мир — общий:
                // эффекты (STUNNED/BLEED), движение, смерть и таймер хода обязаны
                // тикать независимо от роли читателя (иначе оглушение «висит
                // вечно», пока игрок не откроет свой клиент заново).
                $changed_by_tick = turn_tick($state);
                if ($changed_by_tick) {
                    world_save($state);
                }

                $server_version = world_version();
            } catch (Exception $e) {
                usleep(200000); // 200ms пауза после сбоя — следующий тик
                if (time() - $start_time >= $timeout) break; // отдадим снимок по таймауту
                continue;
            }

            if ($server_version !== $client_version) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(build_poll_response($state, $server_version), JSON_UNESCAPED_UNICODE);
                closeRedis();
                exit;
            }

            if (time() - $start_time >= $timeout) {
                break; // таймаут — отдадим снимок ниже
            }

            usleep(100000); // 100ms
        }

        // Таймаут long-poll: отдаём текущий снимок с timeout=true (как в старом контракте),
        // чтобы клиент обновил таймер хода даже при отсутствии событий.
        header('Content-Type: application/json; charset=utf-8');
        $resp = build_poll_response($state, $server_version);
        $resp['timeout'] = true;
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        closeRedis();
        exit;
} catch (Throwable $e) {
    // Гарантированный JSON-ответ вместо оборванного соединения («потеря пакета»).
    // sendJsonErrorFallback не трогает Redis — безопасен и после closeRedis().
    sendJsonErrorFallback('Ошибка polling: ' . $e->getMessage(), 500);
}

/**
 * Сборка flat-ответа poll (контракт клиента js/game.js render(data)).
 */
function build_poll_response($state, $version) {
    $now = time();
    return [
        'version' => $version,
        'rules' => rules_client_payload(),
        'room' => $state['room'],
        'grid' => $state['room']['grid'],
        'objects' => $state['objects'],
        'traps' => $state['traps'],
        'npcs' => $state['npcs'],
        'players' => $state['players'],
        'projectiles' => $state['projectiles'] ?? [],
        'log' => array_slice($state['log'], -30),
        'turn_time' => $state['turn_time'],
        'turn_duration' => TURN_DURATION,
        'time_to_next_turn' => max(0, TURN_DURATION - ($now - (int)$state['turn_time'])),
        'victory' => engine_check_victory($state),
    ];
}
