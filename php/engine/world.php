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
 * Версия состояния сразу ПОСЛЕ загрузки — то, что должен передать action-эндпоинт
 * в world_save_cas() как ожидаемую («я мутировал мир версии N»).
 */
function world_load_versioned() {
    $state = world_load();
    return [$state, (int)($state['version'] ?? 0)];
}

/**
 * Атомарное сохранение с контролем конкурентности (CAS / optimistic locking).
 *
 * ★ ИСПРАВЛЕНИЕ серверной половины бага «потери пакета» (last-writer-wins):
 * poll.php тикает в СВОЁМ PHP-процессе параллельно action.php; раньше оба
 * писали весь мир безусловно, и тик опроса мог перезаписать только что
 * применённое действие старым снапшотом — клиент видел «Действие выполнено»,
 * а в мире ничего не менялось. Здесь: saveBattleState($state, $expectedVersion)
 * отказывает в записи, если мир изменился после загрузки; вызывающий код
 * перечитывает состояние (rebase), ПОВТОРНО вызывает мутатор на свежем
 * снапшоте и сохраняет заново (см. цикл применения действия в action.php).
 *
 * @param array $state            состояние (мутация уже выполнена снаружи)
 * @param int   $expected_version версия, прочитанная при загрузке
 * @return array [bool written, array fresh_state] — fresh_state актуален при
 *               written=false (им именно нужно продолжить работу).
 */
function world_save_cas($state, $expected_version) {
    $work = $state;
    $vmn = $work['WORLD_MOVE_NOW'] ?? null;
    unset($work['WORLD_MOVE_NOW']); // не входит в сериализуемые секции

    if (saveBattleState($work, (int)$expected_version)) {
        if ($vmn !== null) {
            try { getRedis()->set(REDIS_WORLD_MOVE_NOW, (string)$vmn); } catch (Throwable $e) {}
        }
        return [true, $state];
    }

    // ГОНКА: параллельный процесс (тик опроса/чужое действие) изменил мир
    // между нашим load и save. НЕ затираем его запись — отдаём свежезагруженное
    // состояние, на котором вызывающий код повторит мутацию и сохранение.
    return [false, world_load()];
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
 * Текущая версия мира (для long-poll цикла). Устойчив к закрытому соединению:
 * при недоступном Redis возвращает последний известный номер вместо фатала.
 */
function world_version() {
    static $last = 0;
    try {
        $v = (int)getRedis()->get(REDIS_VERSION);
        if ($v > 0) $last = $v;
    } catch (Throwable $e) {
        // Redis недоступен — отдаём последний известный (не роняем ответ)
    }
    return $last;
}

// ─── ПОДТВЕРЖДЕНИЯ ДЕЙСТВИЙ (защита от «потери пакета») ────────────────────
// Контракт: action.php принимает JSON с необязательным полем `action_id`
// (уникальный id, который клиент генерирует на каждое отправляемое действие).
//   - Если действие с таким id уже применён и подтверждён (есть в ack-журнале),
//     сервер НЕ применяет его повторно, а отвечает закэшированный успех —
//     это защита от двойного применения при ретраях («нет очков действий»,
//     двойной урон, двойной ход).
//   - Если сервер упал ДО записи ack, но ПОСЛЕ world_save (действие уже в мире),
//     повтор от клиента будет отвергнут штатной валидацией (MOVING / ACTIONS<=0)
//     — состояние консистентно, дубля нет.
//   - Если запрос действительно потерян (сервер его не получил) — клиент ретраит
//     с тем же action_id, и действие проходит ровно один раз.
// ACK_TTL — окно идемпотентности в секундах (покрывает 3 ретрая × ~11 с).

if (!defined('ACTION_ACK_TTL')) {
    define('ACTION_ACK_TTL', 60);
}

/**
 * Проверить, применено ли действие с таким action_id ранее.
 * @return array|null ['success'=>bool,'message'=>string,'version'=>int]|null — null если id неизвестен
 */
function world_action_ack_get($player_id, $action_id) {
    if ($action_id === null || $action_id === '') return null;
    try {
        $raw = getRedis()->hGet(REDIS_ACTION_ACKS, $player_id . '|' . $action_id);
    } catch (Throwable $e) {
        return null; // хранилище недоступно — считаем, что повтора нет (валидация глагола защитит от дублей по состоянию)
    }
    if (!$raw) return null;
    $ack = json_decode($raw, true);
    if (!is_array($ack)) return null;
    if (time() - (int)($ack['ts'] ?? 0) > ACTION_ACK_TTL) return null; // устарело
    return $ack;
}

/**
 * Записать подтверждение применённого действия (после успешного world_save).
 */
function world_action_ack_set($player_id, $action_id, $payload) {
    if ($action_id === null || $action_id === '') return;
    $payload['ts'] = time();
    try {
        $redis = getRedis();
        $key = $player_id . '|' . $action_id;
        $redis->hSet(REDIS_ACTION_ACKS, $key, json_encode($payload));
        $redis->expire(REDIS_ACTION_ACKS, ACTION_ACK_TTL);
        // Компакция: журнал не должен расти бесконечно внутри окна TTL
        if ($redis->hLen(REDIS_ACTION_ACKS) > 2000) {
            $redis->del(REDIS_ACTION_ACKS);
            $redis->hSet(REDIS_ACTION_ACKS, $key, json_encode($payload));
            $redis->expire(REDIS_ACTION_ACKS, ACTION_ACK_TTL);
        }
    } catch (Throwable $e) {
        // Не удаётся подтвердить — клиент ретраит; повтор может удвоить действие.
        // Штатная валидация глагола (MOVING/ACTIONS/STUNNED) отклонит дубль,
        // поэтому потеря ack безопаснее потери самого действия.
    }
}
