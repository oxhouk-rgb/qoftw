<?php
/**
 * engine/bootstrap.php — Точка сборки движка.
 *
 * Единственный place, где системы подключаются и регистрируют своих слушателей.
 * Эндпоинты (init/poll/action) делают require_once bootstrap.php и дальше
 * работают только с API движка (engine/world, systems/*).
 *
 * ПОРЯДОК РЕГИСТРАЦИИ СЛУШАТЕЛЕЙ (важно для детерминизма):
 *   combat   → damage.dealt (interrupt move, priority 0)
 *   passives → damage.query_mitigate(ARMORED), damage.dealt(THORNS, p10),
 *               movement.query_distance(SWIFT/SLOWED), turn.start(REGEN),
 *               attack.resolved(SPLASH)
 *   effects  → effect.applied (immediate cc)
 *   movement → movement.query_block (cc-блокировки)
 *   objects  → turn.start (NPC actions + threat, priority 20)
 *   turn     → turn.start (effects tick, priority 5)
 */

require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/geometry.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/world.php';

require_once __DIR__ . '/../systems/effects.php';
require_once __DIR__ . '/../systems/targeting.php';
require_once __DIR__ . '/../systems/passives.php';
require_once __DIR__ . '/../systems/movement.php';
require_once __DIR__ . '/../systems/combat.php';
require_once __DIR__ . '/../systems/abilities.php';
require_once __DIR__ . '/../systems/objects.php';
require_once __DIR__ . '/../systems/rooms.php';
require_once __DIR__ . '/turn.php';

if (!defined('ENGINE_BOOTSTRAPPED')) {
    define('ENGINE_BOOTSTRAPPED', true);

    combat_register_hooks();
    passives_register_hooks();
    effects_register_hooks();
    movement_register_hooks();
    objects_register_hooks();
    turn_register_hooks();
}

/**
 * Проверка победы: все игроки одной стороны мертвы/удалены (кроме последнего).
 *
 * ★ ВРЕМЕННО НЕАКТИВНА (Этап 1 перехода на подключение игроков с аккаунта):
 * раньше мир всегда стартовал с 4 заготовленными бойцами, и «остался один
 * живой» было финалом боя. Теперь персонаж спавнится ТОЛЬКО для подключившегося
 * игрока: при одном участнике условие count(alive) <= 1 стало бы истинным в
 * первую же секунду — бой обрывался оверлеем победы до первого хода.
 * Пока нет лобби подбора и полноценных сессий, экран победы намеренно
 * выключен: victory всегда false, подключиться может и один игрок.
 * Возвращаемый контракт прежний (poll.php отдаёт это поле клиенту как есть).
 * TODO(лобби): включить проверку обратно и требовать count(participants) >= 2.
 */
function engine_check_victory($state) {
    return ['victory' => false];

    // ↓ Прежняя логика battle.checkVictory (сохранена для будущего включения)
    $alive = [];
    foreach ($state['players'] as $pid => $player) {
        if (!in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) {
            $alive[] = $pid;
        }
    }
    if (count($alive) <= 1) {
        return ['victory' => true, 'winner' => $alive[0] ?? null];
    }
    return ['victory' => false];
}
