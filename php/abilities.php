<?php
/**
 * abilities.php - Обработка способностей
 * 
 * Каждая способность имеет:
 * - name: название
 * - range: дальность применения
 * - damage: базовый урон
 * - type: тип урона (physical/fire/ice/poison)
 * - effect: накладываемые эффекты
 * - aoe: область поражения (0 = одна цель, >0 = радиус)
 * - projectile: тип снаряда (none/beam/area/line)
 */

// Реестр всех способностей
function getAbilityDefinitions() {
    return [
        // Способности NAPALM UNIT
        'FIRE WAVE' => [
            'name' => 'FIRE WAVE',
            'range' => 3,
            'damage' => 4,
            'type' => 'fire',
            'effect' => ['BLEED[2]'],
            'aoe' => 0,
            'projectile' => 'line',
            'description' => 'Огненная волна по прямой линии'
        ],
        'SHOCK GRANADE' => [
            'name' => 'SHOCK GRANADE',
            'range' => 4,
            'damage' => 3,
            'type' => 'physical',
            'effect' => ['STUNNED[1]'],
            'aoe' => 2,
            'projectile' => 'area',
            'description' => 'Шоковая граната с областью поражения'
        ],
        'NAPALM GRANADE' => [
            'name' => 'NAPALM GRANADE',
            'range' => 5,
            'damage' => 5,
            'type' => 'fire',
            'effect' => ['BLEED[3]'],
            'aoe' => 1,
            'projectile' => 'area',
            'description' => 'Напалмовая граната, поджигает область'
        ],
        
        // Способности FROST KNIGHT
        'ICE SHARD' => [
            'name' => 'ICE SHARD',
            'range' => 3,
            'damage' => 3,
            'type' => 'ice',
            'effect' => ['FROZEN[1]'],
            'aoe' => 0,
            'projectile' => 'beam',
            'description' => 'Ледяной осколок, замораживает цель'
        ],
        'FROST NOVA' => [
            'name' => 'FROST NOVA',
            'range' => 2,
            'damage' => 2,
            'type' => 'ice',
            'effect' => ['FROZEN[2]'],
            'aoe' => 2,
            'projectile' => 'area',
            'description' => 'Ледяная волна вокруг caster'
        ],
        'BLIZZARD' => [
            'name' => 'BLIZZARD',
            'range' => 5,
            'damage' => 4,
            'type' => 'ice',
            'effect' => ['FROZEN[2]', 'SLOWED[2]'],
            'aoe' => 3,
            'projectile' => 'area',
            'description' => 'Метель, большая область поражения'
        ],
        
        // Способности SHADOW ROGUE
        'BACKSTAB' => [
            'name' => 'BACKSTAB',
            'range' => 1,
            'damage' => 6,
            'type' => 'physical',
            'effect' => ['BLEED[2]'],
            'aoe' => 0,
            'projectile' => 'none',
            'description' => 'Удар в спину, высокий урон вблизи'
        ],
        'SMOKE BOMB' => [
            'name' => 'SMOKE BOMB',
            'range' => 3,
            'damage' => 0,
            'type' => 'physical',
            'effect' => ['BLINDED[2]'],
            'aoe' => 2,
            'projectile' => 'area',
            'description' => 'Дымовая граната, ослепляет врагов'
        ],
        'POISON BLADE' => [
            'name' => 'POISON BLADE',
            'range' => 2,
            'damage' => 2,
            'type' => 'poison',
            'effect' => ['POISONED[3]'],
            'aoe' => 0,
            'projectile' => 'beam',
            'description' => 'Отравленный клинок'
        ],
        
        // Способности IRON GUARD
        'SHIELD BASH' => [
            'name' => 'SHIELD BASH',
            'range' => 1,
            'damage' => 3,
            'type' => 'physical',
            'effect' => ['STUNNED[1]'],
            'aoe' => 0,
            'projectile' => 'none',
            'description' => 'Удар щитом, оглушает цель'
        ],
        'FORTIFY' => [
            'name' => 'FORTIFY',
            'range' => 0,
            'damage' => 0,
            'type' => 'physical',
            'effect' => [],
            'aoe' => 0,
            'projectile' => 'none',
            'self_buff' => ['SHIELD_RESTORE' => 3],
            'description' => 'Восстанавливает 3 щита'
        ],
        'WAR CRY' => [
            'name' => 'WAR CRY',
            'range' => 3,
            'damage' => 0,
            'type' => 'physical',
            'effect' => [],
            'aoe' => 3,
            'projectile' => 'area',
            'ally_buff' => ['ATTACK_UP' => 2],
            'description' => 'Боевой клич, усиливает союзников'
        ]
    ];
}

/**
 * Применить способность
 * 
 * @param array $state - текущее состояние боя
 * @param string $caster_id - ID кастера
 * @param string $ability_name - название способности
 * @param int $target_x - X цели
 * @param int $target_y - Y цели
 * @return array - обновленное состояние + снаряды
 */
function applyAbility(&$state, $caster_id, $ability_name, $target_x, $target_y) {
    $abilities = getAbilityDefinitions();
    
    if (!isset($abilities[$ability_name])) {
        return ['success' => false, 'message' => 'Способность не найдена'];
    }
    
    $ability = $abilities[$ability_name];
    $caster = $state['players'][$caster_id];
    
    // Проверка дальности
    $caster_pos = parseXY($caster['CUR_POSITION_XY']);
    $distance = abs($caster_pos['x'] - $target_x) + abs($caster_pos['y'] - $target_y);
    
    // Для способности с range из данных игрока
    $player_abilities = $caster['ABILITIES'];
    $ability_range = $ability['range'];
    foreach ($player_abilities as $pa) {
        if (strpos($pa, $ability_name) === 0) {
            preg_match('/\[(\d+)\]/', $pa, $matches);
            if (isset($matches[1])) {
                $ability_range = (int)$matches[1];
            }
            break;
        }
    }
    
    if ($distance > $ability_range) {
        return ['success' => false, 'message' => 'Цель слишком далеко'];
    }
    
    $result = [
        'success' => true,
        'projectiles' => [],
        'hits' => [],
        'message' => ''
    ];
    
    // Обработка в зависимости от типа снаряда
    switch ($ability['projectile']) {
        case 'none':
            // Мгновенное применение (ближний бой или самоприменение)
            $result = applyInstantAbility($state, $caster_id, $ability, $target_x, $target_y);
            break;
            
        case 'beam':
            // Луч - поражает всё на прямой линии
            $result = applyBeamAbility($state, $caster_id, $ability, $target_x, $target_y);
            break;
            
        case 'line':
            // Линия - как луч но с эффектами
            $result = applyLineAbility($state, $caster_id, $ability, $target_x, $target_y);
            break;
            
        case 'area':
            // Область - поражает в радиусе от цели
            $result = applyAreaAbility($state, $caster_id, $ability, $target_x, $target_y);
            break;
    }
    
    // Создаем снаряд для визуализации
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
            'status' => 'cast', // cast -> hit -> done
            'created' => time()
        ];
    }
    
    return $result;
}

/**
 * Мгновенная способность (ближний бой / бафф)
 */
function applyInstantAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
    $result = ['success' => true, 'projectiles' => [], 'hits' => []];
    $caster = $state['players'][$caster_id];
    
    // Самоприменение (FORTIFY)
    if (isset($ability['self_buff'])) {
        foreach ($ability['self_buff'] as $buff => $value) {
            if ($buff === 'SHIELD_RESTORE') {
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
                        // Временно увеличиваем атаку (до конца боя для теста)
                        $state['players'][$pid]['ATTACK'] += $value;
                    }
                }
            }
        }
        $result['message'] = $caster_id . ' использует ' . $ability['name'];
        return $result;
    }
    
    // Ближний бой - ищем цель на клетке
    $target = findTargetAtPosition($state, $target_x, $target_y, $caster_id);
    if ($target) {
        $damage = calculateDamage($ability['damage'], $caster['ATTACK'], $target['RESISTANCE']);
        applyDamageToTarget($state, $target['id'], $damage, $target['type']);
        
        // Применяем эффекты
        foreach ($ability['effect'] as $effect) {
            applyNegativeEffect($state, $target['id'], $target['type'], $effect);
        }
        
        $result['hits'][] = ['target' => $target['id'], 'damage' => $damage];
        $result['message'] = $caster_id . ' использует ' . $ability['name'] . ' на ' . $target['id'];
    }
    
    return $result;
}

/**
 * Лучевая способность - поражает по прямой
 */
function applyBeamAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
    $result = ['success' => true, 'projectiles' => [], 'hits' => []];
    $caster = $state['players'][$caster_id];
    $caster_pos = parseXY($caster['CUR_POSITION_XY']);
    
    // Определяем направление
    $dx = $target_x - $caster_pos['x'];
    $dy = $target_y - $caster_pos['y'];
    
    // Находим все цели на линии
    $targets_on_line = getTargetsOnLine($state, $caster_pos['x'], $caster_pos['y'], $target_x, $target_y, $caster_id);
    
    foreach ($targets_on_line as $target) {
        $damage = calculateDamage($ability['damage'], $caster['ATTACK'], $target['RESISTANCE']);
        applyDamageToTarget($state, $target['id'], $damage, $target['type']);
        
        foreach ($ability['effect'] as $effect) {
            applyNegativeEffect($state, $target['id'], $target['type'], $effect);
        }
        
        $result['hits'][] = ['target' => $target['id'], 'damage' => $damage];
    }
    
    $result['message'] = $caster_id . ' использует ' . $ability['name'];
    return $result;
}

/**
 * Линейная способность (огненная волна и т.п.)
 */
function applyLineAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
    // Аналогично beam но с другими эффектами
    return applyBeamAbility($state, $caster_id, $ability, $target_x, $target_y);
}

/**
 * Областная способность
 */
function applyAreaAbility(&$state, $caster_id, $ability, $target_x, $target_y) {
    $result = ['success' => true, 'projectiles' => [], 'hits' => []];
    $caster = $state['players'][$caster_id];
    $aoe = $ability['aoe'];
    
    // Находим все цели в радиусе от точки назначения
    $targets_in_aoe = getTargetsInArea($state, $target_x, $target_y, $aoe, $caster_id);
    
    foreach ($targets_in_aoe as $target) {
        $damage = calculateDamage($ability['damage'], $caster['ATTACK'], $target['RESISTANCE']);
        applyDamageToTarget($state, $target['id'], $damage, $target['type']);
        
        foreach ($ability['effect'] as $effect) {
            applyNegativeEffect($state, $target['id'], $target['type'], $effect);
        }
        
        $result['hits'][] = ['target' => $target['id'], 'damage' => $damage];
    }
    
    $result['message'] = $caster_id . ' использует ' . $ability['name'];
    return $result;
}

/**
 * Расчет урона с учетом сопротивления
 */
function calculateDamage($base_damage, $attack_stat, $resistance) {
    // Минимум 1 урон от атаки
    $damage = max($base_damage, 1);
    
    // Сопротивление: каждое очко = 10%
    $resist_percent = $resistance * 10;
    if ($resist_percent > 90) $resist_percent = 90;
    
    $reduced = $damage * (1 - $resist_percent / 100);
    
    // Округление в большую сторону
    $final_damage = ceil($reduced);
    
    // Минимум 1 урон если атака >= 1
    return max($final_damage, 1);
}

/**
 * Применить урон к цели (игрок/NPC/объект)
 */
function applyDamageToTarget(&$state, $target_id, $damage, $target_type) {
    if ($target_type === 'player') {
        $player = &$state['players'][$target_id];
        
        // Сначала щит
        if ($player['SHIELD'] > 0) {
            $shield_absorb = min($player['SHIELD'], $damage);
            $player['SHIELD'] -= $shield_absorb;
            $damage -= $shield_absorb;
        }
        
        // Остаток в HP
        if ($damage > 0) {
            $player['HITPOINTS'] -= $damage;
            
            // Прерываем движение при получении урона
            if ($player['STATUS'] === 'MOVING') {
                // Останавливаем на текущей позиции (округлённой)
                $start = parseXY($player['START_POSITION_XY']);
                $end = parseXY($player['END_POSITION_XY']);
                $now = microtime(true);
                $total_time = $player['MOVE_END_TIME'] - $player['MOVE_START_TIME'];
                $elapsed = $now - $player['MOVE_START_TIME'];
                
                if ($total_time > 0) {
                    $progress = min(1, $elapsed / $total_time);
                    $cur_x = $start['x'] + ($end['x'] - $start['x']) * $progress;
                    $cur_y = $start['y'] + ($end['y'] - $start['y']) * $progress;
                    $player['CUR_POSITION_XY'] = formatXY(round($cur_x), round($cur_y));
                    $player['START_POSITION_XY'] = $player['CUR_POSITION_XY'];
                    $player['END_POSITION_XY'] = $player['CUR_POSITION_XY'];
                }
                
                $player['STATUS'] = 'IDLE';
                unset($player['MOVE_END_TIME']);
                unset($player['MOVE_START_TIME']);
                unset($player['MOVE_DISTANCE']);
            }
            
            if ($player['HITPOINTS'] <= 0) {
                $player['HITPOINTS'] = 0;
                $player['STATUS'] = 'DEATH';
            }
        }
    } elseif ($target_type === 'npc') {
        $npc = &$state['npcs'][$target_id];
        $npc['HITPOINTS'] -= $damage;
        if ($npc['HITPOINTS'] <= 0) {
            $npc['HITPOINTS'] = 0;
            $npc['STATUS'] = 'DESTROYED';
        }
    } elseif ($target_type === 'object') {
        $obj = &$state['objects'][$target_id];
        $obj['HITPOINTS'] -= $damage;
        if ($obj['HITPOINTS'] <= 0) {
            $obj['STATUS'] = 'DESTROYED';
        }
    }
}

/**
 * Применить негативный эффект к цели
 */
function applyNegativeEffect(&$state, $target_id, $target_type, $effect_str) {
    if ($target_type !== 'player') return;
    
    // Парсим эффект: "STUNNED[2]" -> name=STUNNED, duration=2
    preg_match('/^([A-Z_]+)\[(\d+)\]$/', $effect_str, $matches);
    if (count($matches) < 3) return;
    
    $effect_name = $matches[1];
    $duration = (int)$matches[2];
    
    $player = &$state['players'][$target_id];
    
    // Добавляем или продлеваем эффект
    $found = false;
    foreach ($player['NEGATIVES'] as &$neg) {
        preg_match('/^([A-Z_]+)\[(\d+)\]$/', $neg, $m);
        if ($m[1] === $effect_name) {
            // Продлеваем до максимального
            $neg = $effect_name . '[' . max((int)$m[2], $duration) . ']';
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $player['NEGATIVES'][] = $effect_str;
    }
}

/**
 * Найти цель на позиции
 */
function findTargetAtPosition($state, $x, $y, $caster_id) {
    $caster = $state['players'][$caster_id];
    $ally = $caster['ALLY'];
    
    // Ищем вражеских игроков
    foreach ($state['players'] as $pid => $player) {
        if ($pid === $caster_id) continue;
        if ($pid === $ally || $player['ALLY'] === $caster_id) continue;
        if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'REMOVED') continue;
        
        $pos = parseXY($player['CUR_POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) {
            return ['id' => $pid, 'type' => 'player', 'RESISTANCE' => $player['RESISTANCE']];
        }
    }
    
    // Ищем вражеских NPC
    foreach ($state['npcs'] as $nid => $npc) {
        if ($npc['STATUS'] === 'DESTROYED' || $npc['STATUS'] === 'DEATH') continue;
        
        // NPC с ALLY='NONE' враждебен всем
        if ($npc['ALLY'] !== 'NONE') {
            if ($npc['ALLY'] === $caster_id || $npc['ALLY'] === $ally) continue;
        }
        
        $pos = parseXY($npc['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) {
            return ['id' => $nid, 'type' => 'npc', 'RESISTANCE' => 0];
        }
    }
    
    // Ищем разрушаемые объекты
    foreach ($state['objects'] as $oid => $obj) {
        if ($obj['STATUS'] === 'DESTROYED') continue;
        
        $pos = parseXY($obj['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) {
            return ['id' => $oid, 'type' => 'object', 'RESISTANCE' => 0];
        }
    }
    
    return null;
}

/**
 * Получить цели на прямой линии
 */
function getTargetsOnLine($state, $x1, $y1, $x2, $y2, $caster_id) {
    $targets = [];
    $caster = $state['players'][$caster_id];
    $ally = $caster['ALLY'];
    
    // Определяем направление (только по прямой)
    $dx = ($x2 > $x1) ? 1 : (($x2 < $x1) ? -1 : 0);
    $dy = ($y2 > $y1) ? 1 : (($y2 < $y1) ? -1 : 0);
    
    // Двигаемся по линии от caster к цели
    $cx = $x1 + $dx;
    $cy = $y1 + $dy;
    
    while (isValidPosition($cx, $cy)) {
        // Проверяем всех игроков
        foreach ($state['players'] as $pid => $player) {
            if ($pid === $caster_id) continue;
            if ($pid === $ally || $player['ALLY'] === $caster_id) continue;
            if ($player['STATUS'] === 'DEATH') continue;
            
            $pos = parseXY($player['CUR_POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) {
                $targets[] = ['id' => $pid, 'type' => 'player', 'RESISTANCE' => $player['RESISTANCE']];
            }
        }
        
        // Проверяем NPC
        foreach ($state['npcs'] as $nid => $npc) {
            if ($npc['STATUS'] === 'DESTROYED') continue;
            if ($npc['ALLY'] === $caster_id || $npc['ALLY'] === $ally) continue;
            
            $pos = parseXY($npc['POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) {
                $targets[] = ['id' => $nid, 'type' => 'npc', 'RESISTANCE' => 0];
            }
        }
        
        // Проверяем препятствия (останавливаем луч)
        if (!isCellPassable($state, $cx, $cy)) {
            break;
        }
        
        $cx += $dx;
        $cy += $dy;
    }
    
    return $targets;
}

/**
 * Получить цели в области (радиус от точки)
 */
function getTargetsInArea($state, $center_x, $center_y, $radius, $caster_id) {
    $targets = [];
    $caster = $state['players'][$caster_id];
    $ally = $caster['ALLY'];
    
    // Ищем всех в радиусе
    foreach ($state['players'] as $pid => $player) {
        if ($pid === $caster_id) continue;
        if ($pid === $ally || $player['ALLY'] === $caster_id) continue;
        if ($player['STATUS'] === 'DEATH') continue;
        
        $pos = parseXY($player['CUR_POSITION_XY']);
        $dist = abs($pos['x'] - $center_x) + abs($pos['y'] - $center_y);
        if ($dist <= $radius) {
            $targets[] = ['id' => $pid, 'type' => 'player', 'RESISTANCE' => $player['RESISTANCE']];
        }
    }
    
    foreach ($state['npcs'] as $nid => $npc) {
        if ($npc['STATUS'] === 'DESTROYED') continue;
        if ($npc['ALLY'] === $caster_id || $npc['ALLY'] === $ally) continue;
        
        $pos = parseXY($npc['POSITION_XY']);
        $dist = abs($pos['x'] - $center_x) + abs($pos['y'] - $center_y);
        if ($dist <= $radius) {
            $targets[] = ['id' => $nid, 'type' => 'npc', 'RESISTANCE' => 0];
        }
    }
    
    return $targets;
}

/**
 * Проверка проходимости клетки (учитывает объекты и препятствия)
 */
function isCellPassable($state, $x, $y) {
    if (!isValidPosition($x, $y)) return false;
    
    // Проверяем карту
    if (isset($state['room']['grid'][$y][$x]) && $state['room']['grid'][$y][$x] === 0) {
        return false;
    }
    
    // Проверяем объекты (неразрушенные блокируют)
    foreach ($state['objects'] as $obj) {
        if ($obj['STATUS'] === 'DESTROYED') continue;
        $pos = parseXY($obj['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return false;
    }
    
    return true;
}
