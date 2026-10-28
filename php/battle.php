<?php
/**
 * battle.php — Тонкий фасад над движком (обратная совместимость).
 *
 * РЕФАКТОРИНГ: вся игровая логика переехала в engine/ и systems/.
 * Этот файл сохранён ТОЛЬКО чтобы внешние вызовы (processMove, processAttack,
 * applyAbility, getBattleState, saveBattleState, addLog, checkVictory...) не
 * сломались. Новые модули должны требовать engine/bootstrap.php напрямую.
 *
 * Сигнатуры функций-обёрток идентичны прежним из battle.php.
 */

require_once __DIR__ . '/config.php';       // Redis + JSON хелперы
require_once __DIR__ . '/engine/rules.php'; // игровые константы (legacy: battle.php определял их сам)
require_once __DIR__ . '/engine/bootstrap.php';

// ─── Совместимость: прежние имена функций → новые модули ───

function processMove(&$state, $player_id, $target_x, $target_y) {
    return movement_process_move($state, $player_id, $target_x, $target_y);
}

function processAttack(&$state, $attacker_id, $target_x, $target_y) {
    return combat_process_attack($state, $attacker_id, $target_x, $target_y);
}

function processTurnStart(&$state) {
    turn_start($state);
}

function updateMovements(&$state) {
    movement_update($state);
}

function updateDeathAnimations(&$state) {
    turn_update_death_animations($state);
}

function updateProjectiles(&$state) {
    turn_update_projectiles($state);
}

function resetAttackStatus(&$state) {
    turn_reset_attack_status($state);
}

function updateNpcStatuses(&$state) {
    objects_update_npc_statuses($state);
}

function checkVictory($state) {
    return engine_check_victory($state);
}

// Abilities/items/objects уже подключены bootstrap'ом; старые имена
// (getAbilityDefinitions, applyAbility, hasPassive, getPassiveValue,
//  applyNegativeEffect, processTraps, calculateThreatZone,
//  checkThreatZoneIntersection, getAutonomousObjectDefinitions)
// доступны через алиасы ниже.

if (!function_exists('processNegatives')) {
    function processNegatives(&$state, $player_id) {
        effects_tick($state, $player_id);
    }
}

if (!function_exists('applyNegativeEffect')) {
    function applyNegativeEffect(&$state, $target_type, $target_id, $effect_str) {
        effects_apply($state, $target_type, $target_id, $effect_str);
    }
}

if (!function_exists('checkThreatZoneIntersection')) {
    function checkThreatZoneIntersection(&$state, $player_id, $x, $y) {
        return objects_check_threat_zone($state, $player_id, $x, $y);
    }
}

if (!function_exists('hasLineOfSightNPC')) {
    function hasLineOfSightNPC($state, $x1, $y1, $x2, $y2) {
        return objects_has_line_of_sight($state, $x1, $y1, $x2, $y2);
    }
}

// ─── Прежние имена целей/урона/способностей (тонкие делегаты) ───

if (!function_exists('findTargetAtPosition')) {
    function findTargetAtPosition($state, $x, $y, $caster_id) {
        return find_target_at_position($state, $x, $y, $caster_id);
    }
}

if (!function_exists('getTargetsOnLine')) {
    function getTargetsOnLine($state, $x1, $y1, $x2, $y2, $caster_id) {
        return get_targets_on_line($state, $x1, $y1, $x2, $y2, $caster_id);
    }
}

if (!function_exists('getTargetsInArea')) {
    function getTargetsInArea($state, $center_x, $center_y, $radius, $caster_id) {
        return get_targets_in_area($state, $center_x, $center_y, $radius, $caster_id);
    }
}

if (!function_exists('isCellPassable')) {
    function isCellPassable($state, $x, $y) {
        return is_cell_passable($state, $x, $y);
    }
}

if (!function_exists('applyDamageToTarget')) {
    function applyDamageToTarget(&$state, $target_type, $target_id, $damage, $source = []) {
        return combat_apply_damage($state, $target_type, $target_id, $damage, $source ?: ['type' => 'unknown']);
    }
}

if (!function_exists('applySplashDamage')) {
    function applySplashDamage(&$state, $attacker_id, $x, $y) {
        passives_on_attack_resolved($state, [
            'attacker_id' => $attacker_id, 'x' => $x, 'y' => $y,
            'hit_cells' => [[$x, $y]],
        ]);
    }
}

// Внутренние ветки applyAbility — прежние имена сохранены как делегаты
if (!function_exists('applyInstantAbility')) {
    function applyInstantAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
        return ability_apply_instant($state, $caster_id, $ability, $target_x, $target_y);
    }
}

if (!function_exists('applyBeamAbility')) {
    function applyBeamAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
        return ability_apply_beam($state, $caster_id, $ability, $target_x, $target_y);
    }
}

if (!function_exists('applyLineAbility')) {
    function applyLineAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
        return ability_apply_beam($state, $caster_id, $ability, $target_x, $target_y); // line == beam по реализации
    }
}

if (!function_exists('applyAreaAbility')) {
    function applyAreaAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
        return ability_apply_area($state, $caster_id, $ability, $target_x, $target_y);
    }
}
