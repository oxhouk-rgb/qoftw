<?php
/**
 * engine/world.php — Единственный владелец хранилища мира (Redis) и формата состояния.
 *
 * Слой «адаптер хранилища»: движок (engine/systems) оперирует массивом $state,
 * а world.* функцииtranslateют его в/из Redis-ключи. HTTP-эндпоинты НЕ должны
 * трогать redis напрямую — только через этот модуль.
 *
 * ИНВАРИАНТ ВЕРСИИ: любая мутация состояния обязана доходить до world_save(),
 * который централизованно инкрементирует REDIS_VERSION (long-poll увидит
 * изменение). Никогда не инкрементируйте версию в другом месте.
 *
 * Формат состояния (владеет этот файл):
 *   players|objects|traps|npcs|room|projectiles|log  — JSON в отдельных ключах,
 *   turn_time — int, version — int (счётчик изменений).
 */

require_once __DIR__ . '/../config.php'; // Redis-коннект + ключи + jsonResponse
require_once __DIR__ . '/rules.php';

/**
 * Ключ Redis с виртуальными часами движения (float, microtime(true)).
 * Хранится ОТДЕЛЬНО от секций состояния: saveBattleState инкрементирует
 * REDIS_VERSION на каждом вызове, а WORLD_MOVE_NOW обновляется КАЖДЫЙ тик —
 * попади он в общий сериализуемый массив, версия росла бы впустую и long-poll
 * штормил (залипший таймер хода у клиента).
 */
if (!defined('REDIS_WORLD_MOVE_NOW')) {
    define('REDIS_WORLD_MOVE_NOW', REDIS_PREFIX . 'world_move_now');
}

/**
 * Загрузить состояние мира из Redis.
 * @return array state
 */
function world_load() {
    $state = getBattleState();
    $vmn = getRedis()->get(REDIS_WORLD_MOVE_NOW);
    if ($vmn !== false && $vmn !== null) {
        $state['WORLD_MOVE_NOW'] = (float)$vmn;
    }
    return $state;
}

/**
 * Сохранить состояние мира в Redis + инкрементировать версию (единая точка!).
 *
 * ВАЖНО («версия всегда изменилась» = залипший client-таймер): saveBattleState
 * безусловно делает incr(REDIS_VERSION). Если сохранить состояние, идентичное
 * уже лежащему в Redis (tick ничего не мутировал), версия вырастет «впустую».
 * При пустом/идельном мире это превращается в постоянный поток изменений,
 * который обгоняет обработку ответов клиентом. Поэтому сравнение сериализованного
 * состояния с сохранённым — дешёвая страховка от регрессий такого рода.
 *
 * @param array|null $state null — специальный вызов сброса кэша (см. world_init)
 * @return bool был ли реальным сохранение (изменение состояния)
 */
function world_save($state) {
    static $last_saved_hash = null;
    if ($state === null) { // сброс кэша при пересоздании мира
        $last_saved_hash = null;
        return false;
    }
    // Виртуальные часы — вне хеша и вне incr: отдельный SET без версии.
    $vmn = $state['WORLD_MOVE_NOW'] ?? null;
    unset($state['WORLD_MOVE_NOW']); // не входит в сериализуемые секции состояния

    $hash = md5(json_encode($state));
    $state_changed = ($hash !== $last_saved_hash);
    if ($state_changed) {
        $last_saved_hash = $hash;
        saveBattleState($state); // внутри уже incr(REDIS_VERSION) — см. config.php
    }
    if ($vmn !== null) {
        try { getRedis()->set(REDIS_WORLD_MOVE_NOW, (string)$vmn); } catch (Throwable $e) {}
    }
    return $state_changed;
}

/**
 * Инициализировать мир заново из данных комнаты/бойцов (используется init.php).
 */
function world_init($state) {
    // Сбрасываем кэш «последнего сохранённого» — после flushDB любое состояние
    // считается новым и должно быть реально записано с инкрементом версии.
    world_save(null);
    $redis = getRedis();
    $redis->flushDB();
    // Виртуальные часы мира стартуют с текущего момента (до save, чтобы
    // saveBattleState/flushDB их не затёрли).
    $redis->set(REDIS_WORLD_MOVE_NOW, (string)microtime(true));
    world_save($state);
}

/**
 * Добавить запись в журнал боя (журнал — читаемые события, не трассировка).
 * Возвращает обновлённый state (state['log'] хранит последние 50 записей).
 */
function world_log($state, $message) {
    return addLog($state, $message);
}

/**
 * Текущая версия мира (для long-poll цикла).
 */
function world_version() {
    return (int)getRedis()->get(REDIS_VERSION);
}
