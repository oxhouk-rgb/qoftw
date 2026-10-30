<?php
/**
 * init.php — Инициализация боя (тонкий слой HTTP над движком).
 *
 * ★ ХРАНИЛИЩЕ: данные боя живут ТОЛЬКО в Redis (ключи battle:*).
 * Файл data/battledata.json упразднён. Изначальное состояние мира — НЕ в Redis,
 * а в статике кода/данных (php/engine/world_builder.php):
 *   - характеристики типов объектов/ловушек/NPC → systems/objects.php::getObjectTemplates();
 *   - их расстановка по клеткам → секции objects/traps/npcs карт data/<ID>.json;
 *   - бойцы → data/initial_snapshot.json (временная подставка под аккаунт игрока).
 * PHP при обработке HTTP-запросов файлы состояния не читает и не пишет — только Redis.
 *
 * Роль init.php:
 *   1. Обычный вход (идемпотентен): проверить готовность мира в Redis, при
 *      необходимости догрузить карты комнат, пересчитать THREAT_ZONE NPC и
 *      сохранить только реально внесённые правки;
 *   2. ЯВНЫЙ перезапуск сессии (?reset=1 или POST {"reset":true}): собрать мир
 *      из изначального состояния поверх текущего через engine/world_reset.php
 *      (тот же код, что CLI tools/reset_world.php) — разрушенные объекты снова
 *      целы, мина INTACT, игроки живы и на стартовых клетках;
 *   3. Отдать клиенту реестры правил (rules/abilities/passives/effects).
 *
 * Сброс молча не выполняется: только по явному триггеру, и только если сборка
 * прошла без ошибок данных (битые данные → ни один ключ Redis не изменён).
 */

require_once __DIR__ . '/engine/bootstrap.php';
require_once __DIR__ . '/engine/world_reset.php'; // сборка+запись ИЗНАЧАЛЬНОГО мира

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Проверяем наличие клиента Redis (расширение phpredis ИЛИ тестовый двойник
    // класса Redis — см. tests/fakeredis_stub.php; в рантайме двойника нет,
    // поэтому поведение боевого кода не меняется).
    if (!extension_loaded('redis') && !class_exists('Redis')) {
        jsonError('Расширение Redis не установлено. Выполните: pecl install redis');
    }

    // ── 0. Явный перезапуск игровой сессии ──────────────────────────────────
    // Триггер обязан быть явным: обычный вход игрока НЕ должен обнулять живой бой.
    // Тело читаем напрямую (GET-инициализация тела не имеет — file_get_contents
    // вернёт '' и json_decode даст null).
    $raw_input = file_get_contents('php://input');
    $body = ($raw_input === '' || $raw_input === false) ? null : json_decode($raw_input, true);
    $reset_requested = isset($_GET['reset']) || (is_array($body) && !empty($body['reset']));

    if ($reset_requested) {
        $res = world_reset_session();
        if (!$res['ok']) {
            // Fail-fast: ошибки данных → мир НЕ тронут, понятная диагностика.
            jsonError('Мир НЕ пересобран: ' . ($res['message'] ?? 'ошибка сборки')
                . ' | ' . implode('; ', $res['errors']), 500);
        }
        closeRedis();
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $res['message'],
            'world_reset' => $res['summary'],
            'room' => $res['state']['room']['room_id'] ?? '',
            'rules' => rules_client_payload(),
            'abilities' => getAbilityDefinitions(),
            'autonomous_objects' => getAutonomousObjectDefinitions(),
            'passives' => getPassiveDefinitions(),
            'effects' => getEffectDefinitions(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 1. Готовность мира в Redis ────────────────────────────────────────
    // version>0 ставит либо reset-скрипт (=1), либо первая запись saveBattleState
    // (incr). Пустой Redis = мир никто не собирал из изначальных данных.
    $version = 0;
    try {
        $v_raw = getRedis()->get(REDIS_VERSION);
        $version = ($v_raw === false || $v_raw === null) ? 0 : (int)$v_raw;
    } catch (Throwable $e) {
        jsonError('Redis недоступен (' . REDIS_HOST . ':' . REDIS_PORT . '): ' . $e->getMessage());
    }

    $seeded_flag = null;
    try {
        $seeded_flag = getRedis()->get(REDIS_SEEDED);
    } catch (Throwable $e) {
        $seeded_flag = null;
    }

    if ($version <= 0) {
        jsonError('Мир в Redis не инициализирован (battle:version отсутствует). '
            . 'Соберите изначальное состояние: php tools/reset_world.php '
            . '(или запросите этот эндпоинт с ?reset=1)', 503);
    }

    $state = world_load();
    // ★ ПУСТОЙ список бойцов — ЛЕГАЛЬНОЕ состояние (Этап 1: персонажи появляются
    // только через join.php, «N игроков = N персонажей», 0 игроков = 0 персонажей).
    // Раньше здесь был фатал 503 («мир собран неполно») — с пустым стартовым
    // снимком он блокировал бы инициализацию новой сессии до первого подключения.
    // Секция players обязана существовать как массив (структурная целостность),
    // но может быть пуста по смыслу.
    if (!isset($state['players']) || !is_array($state['players'])) {
        jsonError('В Redis нет секции players (мир собран неполно). '
            . 'Пересоберите мир: php tools/reset_world.php', 503);
    }

    // Предупреждение (не фатал): флаг сборки отсутствует — мир мог быть записан
    // вручную или ключ затёрли частично; игра продолжится, но состав данных
    // стоит проверить.
    $seed_warning = ($seeded_flag === false || $seeded_flag === null)
        ? 'Флаг battle:seeded не найден — мир мог быть собран вне engine/world_reset.php.'
        : null;

    // ── 2. Проверка состава бойцов тем же кодом, что собирает мир ──────────
    // (валидация обязательных полей не должна жить во втором месте: здесь она
    // идёт через world_builder_player_errors() — те же правила, что у сброса.)
    foreach ($state['players'] as $pid => $pdat) {
        $pe = world_builder_player_errors((string)$pid, $pdat);
        if ($pe) {
            jsonError('Мир в Redis содержит некорректного бойца: ' . implode('; ', $pe)
                . '. Пересоберите мир: php tools/reset_world.php', 503);
        }
    }

    // ── 3. Зоны угрозы NPC (как раньше считал init.php после сборки state) ──
    // Комнатные карты к этому моменту уже лежат в Redis (reset пишет room:list +
    // room:<ID>; отсутствующие догружает ленивый room_get() из systems/rooms.php).
    $changed = false;
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if ($npc['STATUS'] !== 'DESTROYED' && $npc['STATUS'] !== 'DEATH') {
            $nroom = $npc['ROOM_ID'] ?? null;
            if ($nroom !== null && $nroom !== '' && !isset($state['rooms'][$nroom])) {
                $card = room_get($state, $nroom);
                if ($card !== null) { $state['rooms'][$nroom] = $card; $changed = true; }
            }
            $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
            $state = world_log($state, "NPC {$npc_id}: зона угрозы вычислена (" . count($npc['THREAT_ZONE']) . " клеток)");
            $changed = true;
        }
    }
    unset($npc);

    // turn_time = дедлайн первого хода, если сборка его не задала (страховка от
    // «хода каждый тик», см. poll.php)
    if ((int)($state['turn_time'] ?? 0) <= 0) {
        $state['turn_time'] = time();
        $changed = true;
    }

    // Сохраняем только реально внесённые правки: безусловный save сдвинул бы
    // версию и ложно разбудил всех long-poll клиентов.
    if ($changed) {
        world_save($state);
    }

    $room_id = $state['room']['room_id'] ?? '';

    closeRedis();

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_filter([
        'success' => true,
        'message' => 'Бой загружен из Redis (мир собран engine/world_reset.php)',
        'warning' => $seed_warning,
        'room' => $room_id,
        'rules' => rules_client_payload(),
        'abilities' => getAbilityDefinitions(),
        'autonomous_objects' => getAutonomousObjectDefinitions(),
        'passives' => getPassiveDefinitions(),
        'effects' => getEffectDefinitions(),
    ], function ($v) { return $v !== null; }), JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    sendJsonErrorFallback('Критическая ошибка инициализации: ' . $e->getMessage()
        . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}
