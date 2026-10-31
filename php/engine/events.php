<?php
/**
 * engine/events.php — Шина событий движка (единственный канал связи между системами).
 *
 * НАЗНАЧЕНИЕ
 * ----------
 * Системы (systems/*) НЕ вызывают функции друг друга напрямую и не знают о
 * существовании друг друга. Взаимодействие — только через хуки:
 *
 *   emit($state, 'damage.dealt', $payload)
 *      — «fact»-событие: что-то уже произошло, слушатели реагируют.
 *
 *   $payload = emitReduce($state, 'damage.query_mitigate', $payload)
 *      — «query»-событие (reducer): слушатели получают payload ПО ССЫЛКЕ
 *        (callable обязан объявить `function (&$payload)`) и могут изменять
 *        его; каждый следующий видит результат предыдущего; в конце
 *        возвращается итоговый payload.
 *
 * КАНОНИЧЕСКИЙ СПИСОК СОБЫТИЙ (новые события добавлять сюда же):
 * ----------------------------------------------------------------------------
 * turn.start                | {}                                        | fact    | passives(on_turn_start), effects(tick), objects(npc actions), combat(regen)
 * damage.query_mitigate     | {target_type,target_id,damage}            | reduce  | passives(ARMORED), эффекты брони
 * damage.dealt              | {source_type,source_id,target_type,target_id,damage,shield_absorbed} | fact | combat(interrupt move), passives(THORNS)
 * entity.died               | {type,id,killer_type,killer_id}           | fact    | death-chain, логи
 * attack.resolved           | {attacker_id,x,y,hit_cells:[...]}         | fact    | passives(SPLASH)
 * effect.applied            | {target_type,target_id,name,duration}     | fact    | effects(immediate cc activation)
 * movement.query_block      | {player_id,blocked,reason}                | reduce  | effects(STUNNED/PARALYZED)
 * movement.query_distance   | {player_id,distance}                      | reduce  | passives(SWIFT), effects(SLOWED)
 * movement.interrupted      | {player_id}                               | fact    | movement(остановка на текущей клетке)
 * trap.triggered            | {trap_id,player_id,x,y}                   | fact    | combat/objects
 * threat.entered            | {npc_id,player_id,x,y}                    | fact    | objects(npc attack)
 * tick.server               | {}                                        | fact    | movement/projectiles/animations update
 * victory.check             | {}                                        | fact    | core
 *
 * ПОРЯДОК РЕГИСТРАЦИИ не важен семантически; внутри одного события слушатели
 * вызываются по priority (меньше = раньше), при равных приоритетах — в порядке
 * регистрации (bootstrap регистрирует системы в фиксированном порядке, поэтому
 * вызовы детерминированы — важно для smoke-теста).
 *
 * Обработчики получают &$state и МОГУТ мутировать мир (например THORNS наносит
 * ответный урон прямо в обработчике). Инвариант версии (REDIS_VERSION)
 * обеспечивает централизованно world_save() — см. engine/world.php.
 */

if (!function_exists('hooks')) {
    /** Глобальный реестр хуков (персистентен в рамках одного HTTP-запроса/процесса) */
    function &hooks() {
        static $registry = [];
        return $registry;
    }
}

/**
 * Зарегистрировать слушателя события.
 * @param string   $event    имя события (см. канонический список)
 * @param callable $fn       fact:    fn(array &$state, array  $payload): void
 *                           reduce:  fn(array &$state, array &$payload): void
 * @param int      $priority меньше = раньше (0 по умолчанию)
 */
function hook($event, callable $fn, $priority = 0) {
    static $seq = 0;
    $registry = &hooks();
    if (!isset($registry[$event])) $registry[$event] = [];
    $registry[$event][] = ['fn' => $fn, 'priority' => $priority, 'seq' => ++$seq];
    // usort НЕ стабилен в PHP < 8.0 — индекс регистрации как tie-breaker
    usort($registry[$event], function ($a, $b) {
        return [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']];
    });
}

/** Сбросить все хуки (только для тестов) */
function hooksReset() {
    $registry = &hooks();
    $registry = [];
}

/** Все слушатели события, отсортированные (внутренний хелпер) */
function _hook_listeners($event) {
    $registry = &hooks();
    return $registry[$event] ?? [];
}

/**
 * Эмитировать fact-событие.
 * Вызов через call_user_func_ref: слушатели, объявившие параметры по ссылке
 * (&$state), мутируют оригинал мира (например effects_on_applied выставляет
 * STATUS='STUNNED' при наложении cc; turn_sync_status_after_effect пересобирает
 * статус). Слушатели с value-параметрами получают копию — безопасно для
 * read-only обработчиков.
 * (Раньше здесь был call_user_func без ссылок → все by-ref слушатели работали
 * над копией: мгновенная активация CC молча терялась, в PHP 8 это ещё и
 * Warning'и на каждый эмит.)
 */
function emit(&$state, $event, $payload = []) {
    foreach (_hook_listeners($event) as $listener) {
        call_user_func_ref($listener['fn'], $state, $payload);
    }
}

/**
 * Эмитировать query/reduser-событие: каждый слушатель объявляет второй
 * параметр по ссылке и модифицирует payload; возвращётся итог.
 * Пример подписки: hook('movement.query_block', function(&$state, &$p){...});
 */
function emitReduce(&$state, $event, array $payload) {
    foreach (_hook_listeners($event) as $listener) {
        call_user_func_ref($listener['fn'], $state, $payload);
    }
    return $payload;
}

/**
 * Вызов callable с передачей 2-го аргумента по ссылке без знания сигнатуры
 * слушателя: если слушатель объявил &$payload — он мутирует оригинал;
 * если объявил $payload — получает копию (безопасная деградация).
 */
function call_user_func_ref(callable $fn, &$state, &$payload) {
    try {
        if (is_string($fn)) {
            $ref = new ReflectionFunction($fn);
        } elseif (is_array($fn)) {
            $ref = new ReflectionMethod($fn[0], $fn[1]);
        } else {
            $ref = new ReflectionFunction($fn);
        }
        $params = $ref->getParameters();
        // Если второй параметр объявлен by-reference — передаём по ссылке
        if (isset($params[1]) && $params[1]->isPassedByReference()) {
            $fn($state, $payload);
        } else {
            $fn($state, $payload); // копия (copy-on-write), изменений не будет
        }
    } catch (ReflectionException $e) {
        // Fallback: обычный вызов
        $fn($state, $payload);
    }
}
