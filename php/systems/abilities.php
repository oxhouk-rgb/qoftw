<?php
/**
 * systems/abilities.php — Реестр способностей + исполнение (instant/beam/line/area).
 *
 * РЕЕСТР getAbilityDefinitions() остаётся единственным источником данных о
 * способностях (range/damage/type/effect/aoe/projectile/self_buff/ally_buff) —
 * формат сохранён, game.html читает его через init. Исполнение переведено на
 * единые сервисы: урон → combat_apply_damage, эффекты → effects_apply,
 * цели → targeting (теперь AoE-способности бьют и NPC/объекты тоже — раньше
 * area/beam обходили объекты молча; это осознанное выравнивание с attack).
 */

require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/geometry.php';
require_once __DIR__ . '/../engine/world.php';
require_once __DIR__ . '/targeting.php';
require_once __DIR__ . '/effects.php';
require_once __DIR__ . '/combat.php';

/**
 * Реестр всех способностей (формат прежний — см. шапку).
 */
function getAbilityDefinitions() {
    return [
        // Способности NAPALM UNIT
        'FIRE WAVE' => [
            'name' => 'FIRE WAVE', 'range' => 3, 'damage' => 4, 'type' => 'fire',
            'effect' => ['BLEED[2]'], 'aoe' => 0, 'projectile' => 'line',
            'description' => 'Огненная волна по прямой линии',
        ],
        'SHOCK GRANADE' => [
            'name' => 'SHOCK GRANADE', 'range' => 4, 'damage' => 3, 'type' => 'physical',
            'effect' => ['STUNNED[1]'], 'aoe' => 2, 'projectile' => 'area',
            'description' => 'Шоковая граната с областью поражения',
        ],
        'NAPALM GRANADE' => [
            'name' => 'NAPALM GRANADE', 'range' => 5, 'damage' => 5, 'type' => 'fire',
            'effect' => ['BLEED[3]'], 'aoe' => 1, 'projectile' => 'area',
            'description' => 'Напалмовая граната, поджигает область',
        ],
        // Способности FROST KNIGHT
        'ICE SHARD' => [
            'name' => 'ICE SHARD', 'range' => 3, 'damage' => 3, 'type' => 'ice',
            'effect' => ['FROZEN[1]'], 'aoe' => 0, 'projectile' => 'beam',
            'description' => 'Ледяной осколок, замораживает цель',
        ],
        'FROST NOVA' => [
            'name' => 'FROST NOVA', 'range' => 2, 'damage' => 2, 'type' => 'ice',
            'effect' => ['FROZEN[2]'], 'aoe' => 2, 'projectile' => 'area',
            'description' => 'Ледяная волна вокруг caster',
        ],
        'BLIZZARD' => [
            'name' => 'BLIZZARD', 'range' => 5, 'damage' => 4, 'type' => 'ice',
            'effect' => ['FROZEN[2]', 'SLOWED[2]'], 'aoe' => 3, 'projectile' => 'area',
            'description' => 'Метель, большая область поражения',
        ],
        // Способности SHADOW ROGUE
        'BACKSTAB' => [
            'name' => 'BACKSTAB', 'range' => 1, 'damage' => 6, 'type' => 'physical',
            'effect' => ['BLEED[2]'], 'aoe' => 0, 'projectile' => 'none',
            'description' => 'Удар в спину, высокий урон вблизи',
        ],
        'SMOKE BOMB' => [
            'name' => 'SMOKE BOMB', 'range' => 3, 'damage' => 0, 'type' => 'physical',
            'effect' => ['BLINDED[2]'], 'aoe' => 2, 'projectile' => 'area',
            'description' => 'Дымовая граната, ослепляет врагов',
        ],
        'POISON BLADE' => [
            'name' => 'POISON BLADE', 'range' => 2, 'damage' => 2, 'type' => 'poison',
            'effect' => ['POISONED[3]'], 'aoe' => 0, 'projectile' => 'beam',
            'description' => 'Отравленный клинок',
        ],
        // Способности IRON GUARD
        'SHIELD BASH' => [
            'name' => 'SHIELD BASH', 'range' => 1, 'damage' => 3, 'type' => 'physical',
            'effect' => ['STUNNED[1]'], 'aoe' => 0, 'projectile' => 'none',
            'description' => 'Удар щитом, оглушает цель',
        ],
        'FORTIFY' => [
            'name' => 'FORTIFY', 'range' => 0, 'damage' => 0, 'type' => 'physical',
            'effect' => [], 'aoe' => 0, 'projectile' => 'none',
            'self_buff' => ['SHIELD_RESTORE' => 3],
            'description' => 'Восстанавливает 3 щита',
        ],
        'WAR CRY' => [
            'name' => 'WAR CRY', 'range' => 3, 'damage' => 0, 'type' => 'physical',
            'effect' => [], 'aoe' => 3, 'projectile' => 'area',
            'ally_buff' => ['ATTACK_UP' => 2],
            'description' => 'Боевой клич, усиливает союзников',
        ],
    ];
}

/**
 * Применить способность (валидация дальности из строки игрока "NAME[range]",
 * маршрутизация по projectile-типу, снаряд для визуализации).
 */
function applyAbility(&$state, $caster_id, $ability_name, $target_x, $target_y) {
    $abilities = getAbilityDefinitions();

    if (!isset($abilities[$ability_name])) {
        return ['success' => false, 'message' => 'Способность не найдена'];
    }

    $ability = $abilities[$ability_name];
    $caster = $state['players'][$caster_id];

    // Дальность: базовая из реестра, переопределяется значением из строки игрока
    $caster_pos = parseXY($caster['CUR_POSITION_XY']);
    $distance = manhattan($caster_pos['x'], $caster_pos['y'], $target_x, $target_y);

    $ability_range = $ability['range'];
    foreach ($caster['ABILITIES'] as $pa) {
        if (strpos($pa, $ability_name) === 0) {
            preg_match('/\[(\d+)\]/', $pa, $matches);
            if (isset($matches[1])) $ability_range = (int)$matches[1];
            break;
        }
    }

    if ($distance > $ability_range) {
        return ['success' => false, 'message' => 'Цель слишком далеко'];
    }

    $result = ['success' => true, 'projectiles' => [], 'hits' => [], 'message' => ''];

    switch ($ability['projectile']) {
        case 'none':
            $result = ability_apply_instant($state, $caster_id, $ability, $target_x, $target_y);
            break;
        case 'beam':
        case 'line':
            $result = ability_apply_beam($state, $caster_id, $ability, $target_x, $target_y);
            break;
        case 'area':
            $result = ability_apply_area($state, $caster_id, $ability, $target_x, $target_y);
            break;
    }

    // Снаряд для визуализации (клиент отрисует cast→hit→done)
    if ($ability['projectile'] !== 'none') {
        $state['projectiles'][] = [
            'id' => uniqid('proj_'),
            'caster' => $caster_id,
            'ability' => $ability_name,
            'type' => $ability['projectile'],
            'from_x' => $caster_pos['x'],
            'from_y' => $caster_pos['y'],
            'to_x' => $target_x,
            'to_y' => $target_y,
            'aoe' => $ability['aoe'],
            'status' => 'cast',
            'created' => time(),
        ];
    }

    return $result;
}

/** Внутренний хелпер: урон+эффекты способности по списку целей */
function ability_hit_targets(&$state, $caster_id, $ability, $targets) {
    $caster = $state['players'][$caster_id];
    $hits = [];
    foreach ($targets as $target) {
        $damage = calculateDamage($ability['damage'], $caster['ATTACK'], $target['resistance'],
                                  combat_should_pierce_resistance($state, ['type' => 'player', 'id' => $caster_id]));
        $res = combat_apply_damage($state, $target['type'], $target['id'], $damage,
                                   ['type' => 'player', 'id' => $caster_id]);
        foreach ($ability['effect'] as $effect) {
            effects_apply($state, $target['type'], $target['id'], $effect);
        }
        $hits[] = ['target' => $target['id'], 'damage' => $res['dealt']];
    }
    return $hits;
}

/** Мгновенная способность: self_buff / ally_buff / ближний удар по клетке */
function ability_apply_instant(&$state, $caster_id, $ability, $target_x, $target_y) {
    $result = ['success' => true, 'projectiles' => [], 'hits' => []];

    // Самоприменение (FORTIFY)
    if (isset($ability['self_buff'])) {
        foreach ($ability['self_buff'] as $buff => $value) {
            if ($buff === 'SHIELD_RESTORE') {
                $caster = $state['players'][$caster_id];
                $state['players'][$caster_id]['SHIELD'] = min(
                    $caster['SHIELD'] + $value,
                    $caster['MAX_SHIELD']
                );
            }
        }
        $result['message'] = $caster_id . ' использует ' . $ability['name'];
        return $result;
    }

    // Бафф союзников (WAR CRY)
    if (isset($ability['ally_buff'])) {
        foreach ($state['players'] as $pid => $player) {
            if ($player['ALLY'] === $caster_id || $pid === $caster_id) {
                foreach ($ability['ally_buff'] as $buff => $value) {
                    if ($buff === 'ATTACK_UP') {
                        $state['players'][$pid]['ATTACK'] += $value;
                    }
                }
            }
        }
        $result['message'] = $caster_id . ' использует ' . $ability['name'];
        return $result;
    }

    // Ближний бой — цель на клетке
    $target = find_target_at_position($state, $target_x, $target_y, $caster_id);
    if ($target) {
        $result['hits'] = ability_hit_targets($state, $caster_id, $ability, [$target]);
        $result['message'] = $caster_id . ' использует ' . $ability['name'] . ' на ' . $target['id'];
    } else {
        $result['message'] = $caster_id . ' использует ' . $ability['name'] . ' (мимо)';
    }
    return $result;
}

/** Луч/линия — всё враждебное на прямой до целевой клетки */
function ability_apply_beam(&$state, $caster_id, $ability, $target_x, $target_y) {
    $caster = $state['players'][$caster_id];
    $caster_pos = parseXY($caster['CUR_POSITION_XY']);

    $targets = get_targets_on_line($state, $caster_pos['x'], $caster_pos['y'], $target_x, $target_y, $caster_id);

    return [
        'success' => true,
        'projectiles' => [],
        'hits' => ability_hit_targets($state, $caster_id, $ability, $targets),
        'message' => $caster_id . ' использует ' . $ability['name'],
    ];
}

/** Область — все враждебные сущности в манхэттен-радиусе aoe от точки */
function ability_apply_area(&$state, $caster_id, $ability, $target_x, $target_y) {
    $targets = get_targets_in_area($state, $target_x, $target_y, $ability['aoe'], $caster_id);

    return [
        'success' => true,
        'projectiles' => [],
        'hits' => ability_hit_targets($state, $caster_id, $ability, $targets),
        'message' => $caster_id . ' использует ' . $ability['name'],
    ];
}
