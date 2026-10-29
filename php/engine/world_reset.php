<?php
/**
 * engine/world_reset.php — Единственная точка «перезапуска игровой сессии».
 *
 * ★ ЗАЧЕМ ЭТОТ ФАЙЛ (прямой ответ на баг «у мира нет изначального состояния»)
 * После переезда состояния боя в Redis мир стал ЖИВУЧИМ: init.php только
 * проверял, что battle:* наполнены, и пересчитывал THREAT_ZONE. Ни один HTTP
 * вызов не возвращал мир к СТАРТОВЫМ данным, поэтому после перезахода игроков
 * боец продолжал лежать мёртвым, бочки — разрушенными, мина — TRIGGERED.
 * Здесь собран единственный легальный путь сброса: перечитать ИЗНАЧАЛЬНОЕ
 * состояние (код-реестры + карты комнат + снимок бойцов → world_builder) и
 * записать его в Redis поверх текущего.
 *
 * ДВА КАНАЛА ВЫЗОВА, ОДИН КОД СБОРКИ (важно: правила сборки не должны жить
 * в двух местах — иначе «начало боя» зависело бы от того, каким способом его
 * затеяли):
 *   1) CLI:   php tools/reset_world.php [--dry-run]      — вне запросного цикла;
 *   2) HTTP:  php/init.php?reset=1                       — явный перезапуск сессии.
 * Оба вызывают world_reset_build() (сборка) и world_reset_write() (запись).
 *
 * ★ ПОЧЕМУ HTTP-СБРОС РАЗРЕШЁН ИОН, А В ПРОШЛОМ ЕГО УБИРАЛИ
 * Опасен не сам сброс, а его МОЛЧАНИЕ: старый init.php делал flushDB при каждом
 * входе игрока, из-за чего живой бой обнулялся без ведома игроков. Теперь:
 *   - сброс возможен ТОЛЬКО по явному триггеру (?reset=1 / reset:true) — обычный
 *     вход идемпотентен и мир не трогает;
 *   - сборка выполняется ДО любой записи: битые данные → ни один ключ Redis не
 *     изменён (возврат ошибок вместо полупустого мира);
 *   - в ответ кладётся факт сброса ('world_reset' => [...]), чтобы клиент и
 *     диагностика видели, что мир Пересобран, а не просто загружен.
 */

require_once __DIR__ . '/bootstrap.php';       // движок: rules/geometry/world/turn/systems
require_once __DIR__ . '/world_builder.php';   // ИЗНАЧАЛЬНОЕ состояние мира

/**
 * Собрать изначальное состояние мира из статики (код + карты комнат + снимок).
 * НИЧЕГО не пишет — чистая операция чтения, безопасна для dry-run.
 *
 * @param string|null $snapshot_path другой снимок бойцов (для тестов/CLI --file)
 * @return array ['state'=>array, 'errors'=>[...]] — при непустом errors state
 *         неполон, вызывающий обязан ОТКАЗАТЬСЯ писать его в хранилище.
 */
function world_reset_build($snapshot_path = null) {
    list($players_list, $read_err) = world_builder_read_snapshot($snapshot_path);
    if ($read_err !== null) {
        return ['state' => [], 'errors' => [$read_err]];
    }
    return world_build_initial(['players' => $players_list], ['rooms' => []]);
}

/**
 * Записать собранный мир в Redis как НОВУЮ сессию.
 *
 * Порядок операций важен:
 *   - FLUSH удаляет ВСЕ ключи текущего боя (включая battle:action_acks — журнал
 *     подтверждённых действий: старые ack не должны «съедать» действия новых
 *     игроков, и battle:room:<ID> — устаревшие боевые копии карт);
 *   - версии обязана стать ровно 1: long-poll клиентов сравнивает ?v=N с
 *     battle:version, и любое другое число породило бы ложные «мир изменился»;
 *   - turn_time — int (round-trip-регрессия: JSON-литерал «1234» обнулял дедлайн
 *     хода, poll продвигал ход каждый тик);
 *   - seeded — флаг готовности, который проверяет init.php.
 *
 * Мутирует переданный $state (проставляет turn_time/WORLD_MOVE_NOW), поэтому
 * вызывающий может использовать тот же массив для ответа клиенту.
 *
 * @param array $state результат world_reset_build()/world_build_initial()
 * @return array ['ok'=>bool,'message'=>string,'summary'=>[...]]
 */
function world_reset_write(&$state) {
    $now = time();
    if ((int)($state['turn_time'] ?? 0) <= 0) $state['turn_time'] = $now;
    $state['WORLD_MOVE_NOW'] = microtime(true);

    try {
        $redis = getRedis();
    } catch (Throwable $e) {
        return ['ok' => false,
                'message' => 'Redis недоступен (' . REDIS_HOST . ':' . REDIS_PORT . '): ' . $e->getMessage(),
                'summary' => []];
    }

    $enc = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); };
    $pipe = $redis->multi(Redis::MULTI);
    $pipe->flushDB(); // старого боя не должно остаться ни в одном ключе
    $pipe->set(REDIS_PLAYERS, $enc($state['players']));
    $pipe->set(REDIS_OBJECTS, $enc($state['objects']));
    $pipe->set(REDIS_TRAPS, $enc($state['traps']));
    $pipe->set(REDIS_NPCS, $enc($state['npcs']));
    $pipe->set(REDIS_ROOM, $enc($state['room'] ?? []));
    $pipe->set(REDIS_TURN_TIME, (string)(int)$state['turn_time']);
    $pipe->set(REDIS_PROJECTILES, $enc($state['projectiles'] ?? []));
    $pipe->set(REDIS_LOG, $enc($state['log'] ?? []));
    $pipe->set(REDIS_ROOM_KEY_PREFIX . 'list', $enc(array_keys($state['rooms'] ?? [])));
    foreach (($state['rooms'] ?? []) as $rid => $card) {
        $pipe->set(REDIS_ROOM_KEY_PREFIX . $rid, $enc($card));
    }
    $pipe->set(REDIS_WORLD_MOVE_NOW, (string)$state['WORLD_MOVE_NOW']);
    $pipe->set(REDIS_SEEDED, (string)$now);
    $pipe->set(REDIS_VERSION, '1');
    $pipe->exec();

    // Кэш «последнего сохранённого» в world_save обнуляется только через
    // world_init(null); без этого следующий save сравнил бы новое состояние со
    // снапшотом ПРЕЖНЕЙ сессии и мог бы решить, что «менять нечего».
    world_save(null);

    return ['ok' => true, 'message' => 'Мир пересобран из изначального состояния',
            'summary' => [
                'players' => count($state['players'] ?? []),
                'objects' => count($state['objects'] ?? []),
                'traps' => count($state['traps'] ?? []),
                'npcs' => count($state['npcs'] ?? []),
                'rooms' => array_keys($state['rooms'] ?? []),
                'version' => 1,
                'turn_time' => (int)$state['turn_time'],
            ]];
}

/**
 * ★ Полный перезапуск сессии: сборка + проверка + запись.
 * Используется init.php (?reset=1); CLI-скрипт делает те же шаги самостоятельно,
 * чтобы иметь свой формат вывода и коды возврата.
 *
 * @return array ['ok'=>bool,'state'=>array,'errors'=>[...],'summary'=>[...],'message'=>string]
 */
function world_reset_session($snapshot_path = null) {
    $built = world_reset_build($snapshot_path);
    if (!empty($built['errors'])) {
        // Fail-fast: битые данные НЕ пишутся — живой мир остаётся как есть.
        return ['ok' => false, 'state' => $built['state'], 'errors' => $built['errors'],
                'summary' => [], 'message' => 'Мир НЕ пересобран: ошибки в исходных данных'];
    }
    $res = world_reset_write($built['state']);
    return ['ok' => $res['ok'], 'state' => $built['state'], 'errors' => [],
            'summary' => $res['summary'], 'message' => $res['message']];
}
