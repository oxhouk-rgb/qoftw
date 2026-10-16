<?php
/**
 * battle.php - Основной обработчик боевой сессии
 * 
 * Этот файл обрабатывает:
 * - Перемещение игроков
 * - Атаки
 * - Применение способностей
 * - Обработку хода (обновление очков, эффектов)
 * - Проверку победителя
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/abilities.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/objects.php';

/**
 * Обработать перемещение игрока
 */
function processMove(&$state, $player_id, $target_x, $target_y) {
    $player = $state['players'][$player_id];
    
    // Проверки
    if ($player['STATUS'] === 'DEATH') {
        return ['success' => false, 'message' => 'Игрок мертв'];
    }
    if ($player['STATUS'] === 'MOVING') {
        return ['success' => false, 'message' => 'Игрок уже перемещается'];
    }
    if ($player['STATUS'] === 'STUNNED') {
        return ['success' => false, 'message' => 'Игрок оглушен'];
    }
    if ($player['ACTIONS'] <= 0) {
        return ['success' => false, 'message' => 'Нет очков действий'];
    }
    
    // Проверка что есть негативные эффекты блокирующие движение
    foreach ($player['NEGATIVES'] as $neg) {
        if (strpos($neg, 'FROZEN[') === 0 || strpos($neg, 'STUNNED[') === 0) {
            return ['success' => false, 'message' => 'Невозможно двигаться: ' . $neg];
        }
    }
    
    $cur_pos = parseXY($player['CUR_POSITION_XY']);
    
    // Проверка дистанции (макс 5 клеток)
    $max_move = MAX_MOVE_DISTANCE;
    if (hasPassive($player, 'SWIFT')) {
        $max_move += getPassiveValue($player, 'SWIFT');
    }
    // SLOWED уменьшает дистанцию
    foreach ($player['NEGATIVES'] as $neg) {
        if (strpos($neg, 'SLOWED[') === 0) {
            $max_move = max(1, $max_move - 2);
        }
    }
    
    $distance = abs($cur_pos['x'] - $target_x) + abs($cur_pos['y'] - $target_y);
    if ($distance > $max_move) {
        return ['success' => false, 'message' => 'Слишком далеко (макс ' . $max_move . ')'];
    }
    
    // Должно быть по прямой (только по X или только по Y)
    if ($cur_pos['x'] !== $target_x && $cur_pos['y'] !== $target_y) {
        return ['success' => false, 'message' => 'Перемещение только по прямой'];
    }
    
    // Проверяем путь - нет ли препятствий
    $path = calculatePath($cur_pos['x'], $cur_pos['y'], $target_x, $target_y);
    
    foreach ($path as $step) {
        if (!isCellPassable($state, $step['x'], $step['y'])) {
            return ['success' => false, 'message' => 'Путь заблокирован'];
        }
        // Проверяем нет ли другого игрока на пути
        if (isPlayerAtPosition($state, $step['x'], $step['y'], $player_id)) {
            return ['success' => false, 'message' => 'Клетка занята другим игроком'];
        }
    }
    
    // Проверяем конечную позицию
    if (isPlayerAtPosition($state, $target_x, $target_y, $player_id)) {
        return ['success' => false, 'message' => 'Конечная клетка занята'];
    }
    
    // Проверяем ловушки и зоны угрозы на всём пути
    $path = calculatePath($cur_pos['x'], $cur_pos['y'], $target_x, $target_y);
    $trap_found = false;
    $trap_distance = 0;
    $stopped_by_npc = false;
    
    foreach ($path as $index => $cell) {
        // Проверяем ловушки
        $trap_result = processTraps($state, $player_id, $cell['x'], $cell['y']);
        if ($trap_result['triggered']) {
            // Нашли ловушку - останавливаемся на этой клетке
            $trap_found = true;
            $trap_distance = $index + 1; // +1 потому что индекс с 0
            $state = addLog($state, $player_id . ' наступил на ловушку ' . $trap_result['trap'] . ' на клетке (' . $cell['x'] . ',' . $cell['y'] . ')');
            
            // Изменяем конечную позицию на клетку с ловушкой
            $target_x = $cell['x'];
            $target_y = $cell['y'];
            $distance = $trap_distance;
            break;
        }
        
        // Проверяем зоны угрозы NPC
        $npc_results = checkThreatZoneIntersection($state, $player_id, $cell['x'], $cell['y']);
        
        // Если игрок получил урон от NPC и умер - останавливаемся
        if ($state['players'][$player_id]['STATUS'] === 'DEATH') {
            $stopped_by_npc = true;
            $trap_distance = $index + 1;
            $target_x = $cell['x'];
            $target_y = $cell['y'];
            $distance = $trap_distance;
            break;
        }
    }
    
    // Успешное перемещение - ПЛАВНОЕ
    $state['players'][$player_id]['END_POSITION_XY'] = formatXY($target_x, $target_y);
    $state['players'][$player_id]['STATUS'] = 'MOVING';
    $state['players'][$player_id]['ACTIONS']--;
    
    // Направление спрайта (TURN): только лево/право.
    // Горизонтальное движение переписывает TURN, вертикальное — сохраняет последнее.
    if ($target_x > $cur_pos['x']) $state['players'][$player_id]['TURN'] = 'RIGHT';
    elseif ($target_x < $cur_pos['x']) $state['players'][$player_id]['TURN'] = 'LEFT';
    
    // Рассчитываем время перемещения (3 клетки в секунду)
    $move_time = $distance / MOVE_SPEED;
    $state['players'][$player_id]['MOVE_START_TIME'] = microtime(true);
    $state['players'][$player_id]['MOVE_END_TIME'] = microtime(true) + $move_time;
    $state['players'][$player_id]['MOVE_DISTANCE'] = $distance;
    
    $message = $player_id . ' начинает перемещение на (' . $target_x . ',' . $target_y . ')';
    if ($trap_found) {
        $message .= ' (остановлен ловушкой)';
    } elseif ($stopped_by_npc) {
        $message .= ' (остановлен NPC)';
    }
    
    return [
        'success' => true,
        'message' => $message,
        'distance' => $distance,
        'move_time' => $move_time
    ];
}

/**
 * Рассчитать путь от A до B (по прямой)
 */
function calculatePath($x1, $y1, $x2, $y2) {
    $path = [];
    $dx = ($x2 > $x1) ? 1 : (($x2 < $x1) ? -1 : 0);
    $dy = ($y2 > $y1) ? 1 : (($y2 < $y1) ? -1 : 0);
    
    $cx = $x1 + $dx;
    $cy = $y1 + $dy;
    
    while (($cx !== $x2 || $cy !== $y2) && isValidPosition($cx, $cy)) {
        $path[] = ['x' => $cx, 'y' => $cy];
        $cx += $dx;
        $cy += $dy;
    }
    
    // Добавляем конечную точку
    if ($x2 !== $x1 || $y2 !== $y1) {
        $path[] = ['x' => $x2, 'y' => $y2];
    }
    
    return $path;
}

/**
 * Проверка - есть ли игрок на позиции
 */
function isPlayerAtPosition($state, $x, $y, $exclude_id = null) {
    foreach ($state['players'] as $pid => $player) {
        if ($pid === $exclude_id) continue;
        if ($player['STATUS'] === 'DEATH') continue;
        
        $pos = parseXY($player['CUR_POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return true;
        
        // Также проверяем конечную позицию (если перемещается)
        if ($player['STATUS'] === 'MOVING') {
            $end_pos = parseXY($player['END_POSITION_XY']);
            if ($end_pos['x'] === $x && $end_pos['y'] === $y) return true;
        }
    }
    return false;
}

/**
 * Обработать базовую атаку
 */
function processAttack(&$state, $attacker_id, $target_x, $target_y) {
    $attacker = $state['players'][$attacker_id];
    
    if ($attacker['STATUS'] === 'DEATH') {
        return ['success' => false, 'message' => 'Игрок мертв'];
    }
    if ($attacker['STATUS'] === 'MOVING') {
        return ['success' => false, 'message' => 'Нельзя атаковать во время движения'];
    }
    if ($attacker['STATUS'] === 'STUNNED') {
        return ['success' => false, 'message' => 'Игрок оглушен'];
    }
    if ($attacker['ACTIONS'] <= 0) {
        return ['success' => false, 'message' => 'Нет очков действий'];
    }
    
    $attacker_pos = parseXY($attacker['CUR_POSITION_XY']);
    $distance = abs($attacker_pos['x'] - $target_x) + abs($attacker_pos['y'] - $target_y);
    
    if ($distance > $attacker['RANGE']) {
        return ['success' => false, 'message' => 'Цель вне зоны атаки'];
    }
    if ($distance === 0) {
        return ['success' => false, 'message' => 'Нельзя атаковать свою клетку'];
    }
    
    // Проверяем есть ли PUNCTURE
    $has_puncture = hasPassive($attacker, 'PUNCTURE');
    
    // Проверяем все клетки на пути от атакующего до цели
    $dx = ($target_x > $attacker_pos['x']) ? 1 : (($target_x < $attacker_pos['x']) ? -1 : 0);
    $dy = ($target_y > $attacker_pos['y']) ? 1 : (($target_y < $attacker_pos['y']) ? -1 : 0);
    
    $cx = $attacker_pos['x'] + $dx;
    $cy = $attacker_pos['y'] + $dy;
    
    $targets_hit = []; // Список всех целей, по которым попали
    $attack_message = '';
    $stopped = false; // Остановились ли на первом враге (без PUNCTURE)
    
    // Проверяем все клетки на пути
    while (($cx !== $target_x || $cy !== $target_y) && isValidPosition($cx, $cy) && !$stopped) {
        // Проверяем есть ли враг на этой клетке
        $target = findTargetAtPosition($state, $cx, $cy, $attacker_id);
        
        if ($target) {
            // Нашли врага на пути
            $targets_hit[] = ['target' => $target, 'x' => $cx, 'y' => $cy];
            $state = addLog($state, "ATTACK: найден {$target['type']} {$target['id']} на клетке ({$cx},{$cy})");
            
            // Если нет PUNCTURE - останавливаемся на первом враге
            if (!$has_puncture) {
                $stopped = true;
            }
        }
        
        $cx += $dx;
        $cy += $dy;
    }
    
    // Проверяем конечную клетку
    if (!$stopped) {
        $target = findTargetAtPosition($state, $target_x, $target_y, $attacker_id);
        if ($target) {
            $targets_hit[] = ['target' => $target, 'x' => $target_x, 'y' => $target_y];
            $state = addLog($state, "ATTACK: найден {$target['type']} {$target['id']} на конечной клетке ({$target_x},{$target_y})");
        } else {
            $state = addLog($state, "ATTACK: на конечной клетке ({$target_x},{$target_y}) цели не найдено");
        }
    }
    
    // Наносим урон всем найденным целям
    foreach ($targets_hit as $hit) {
        $target = $hit['target'];
        $hit_x = $hit['x'];
        $hit_y = $hit['y'];
        
        // Рассчитываем урон
        $damage = calculateDamage($attacker['ATTACK'], $attacker['ATTACK'], $target['RESISTANCE']);
        
        // Применяем пассивную защиту
        if ($target['type'] === 'player') {
            $target_player = $state['players'][$target['id']];
            $damage = applyPassiveDefense($target_player, $damage);
        }
        
        // Применяем урон С УЧЁТОМ ЩИТА
        if ($target['type'] === 'player') {
            // Сначала щит поглощает урон
            if ($state['players'][$target['id']]['SHIELD'] > 0) {
                $shield_absorb = min($state['players'][$target['id']]['SHIELD'], $damage);
                $state['players'][$target['id']]['SHIELD'] -= $shield_absorb;
                $damage -= $shield_absorb;
                $msg = $attacker_id . ' атакует ' . $target['id'] . ': щит поглотил ' . $shield_absorb;
                if ($damage > 0) {
                    $msg .= ', осталось ' . $damage . ' урона в HP';
                }
                $attack_message .= ($attack_message ? '; ' : '') . $msg;
            }
            
            // Остаток идёт в HP
            if ($damage > 0) {
                $state['players'][$target['id']]['HITPOINTS'] -= $damage;
                if ($state['players'][$target['id']]['HITPOINTS'] <= 0) {
                    $state['players'][$target['id']]['HITPOINTS'] = 0;
                    $state['players'][$target['id']]['STATUS'] = 'DEATH';
                }
                if (empty($attack_message) || strpos($attack_message, $target['id']) === false) {
                    $attack_message .= ($attack_message ? '; ' : '') . $attacker_id . ' атакует ' . $target['id'] . ' на ' . $damage . ' урона';
                }
            }
        } else {
            // Для NPC и объектов - просто применяем урон
            applyDamageToTarget($state, $target['id'], $damage, $target['type']);
            $attack_message .= ($attack_message ? '; ' : '') . $attacker_id . ' атакует ' . $target['id'] . ' на ' . $damage . ' урона';
        }
        
        // Пассивка THORNS
        if ($target['type'] === 'player' && hasPassive($state['players'][$target['id']], 'THORNS')) {
            $thorns_damage = getPassiveValue($state['players'][$target['id']], 'THORNS');
            $state['players'][$attacker_id]['HITPOINTS'] -= $thorns_damage;
            if ($state['players'][$attacker_id]['HITPOINTS'] <= 0) {
                $state['players'][$attacker_id]['HITPOINTS'] = 0;
                $state['players'][$attacker_id]['STATUS'] = 'DEATH';
            }
            $attack_message .= ' (получил ' . $thorns_damage . ' урона от шипов)';
        }
        
        // Применяем SPLASH к каждой атакованной клетке
        applySplashDamage($state, $attacker_id, $hit_x, $hit_y);
    }
    
    // Если не попали ни в кого - атака пустой клетки
    if (empty($targets_hit)) {
        $attack_message = $attacker_id . ' атакует пустую клетку (' . $target_x . ',' . $target_y . ')';
        // SPLASH всё равно применяется к целевой клетке
        applySplashDamage($state, $attacker_id, $target_x, $target_y);
    }
    
    // Проверяем ловушки на всех атакованных клетках
    foreach ($targets_hit as $hit) {
        $trap_result = processTraps($state, $attacker_id, $hit['x'], $hit['y']);
        if ($trap_result['triggered']) {
            $state = addLog($state, "ATTACK: {$attacker_id} активировал ловушку {$trap_result['trap']}");
        }
    }
    
    // Тратим очко действия
    $state['players'][$attacker_id]['ACTIONS']--;
    $state['players'][$attacker_id]['STATUS'] = 'ATTACK';
    $state['players'][$attacker_id]['ATTACK_TIME'] = time();
    
    // Направление спрайта (TURN): только лево/право.
    // Горизонтальная атака переписывает TURN, вертикальная — сохраняет последнее.
    if ($target_x > $attacker_pos['x']) $state['players'][$attacker_id]['TURN'] = 'RIGHT';
    elseif ($target_x < $attacker_pos['x']) $state['players'][$attacker_id]['TURN'] = 'LEFT';
    
    return [
        'success' => true,
        'message' => $attack_message,
        'damage' => $target ? $damage : 0,
        'target' => $target ? $target['id'] : null
    ];
}

/**
 * Обновить перемещения (вызывается каждый тик)
 */
function updateMovements(&$state) {
    $now = microtime(true);
    
    foreach ($state['players'] as $pid => &$player) {
        if ($player['STATUS'] !== 'MOVING') continue;
        
        if (!isset($player['MOVE_END_TIME'])) continue;
        
        if ($now >= $player['MOVE_END_TIME']) {
            // Перемещение завершено
            $player['CUR_POSITION_XY'] = $player['END_POSITION_XY'];
            $player['START_POSITION_XY'] = $player['END_POSITION_XY'];
            $player['STATUS'] = 'IDLE';
            unset($player['MOVE_END_TIME']);
            unset($player['MOVE_START_TIME']);
            unset($player['MOVE_DISTANCE']);
            
            // Проверяем ловушки на новой позиции
            $new_pos = parseXY($player['CUR_POSITION_XY']);
            $trap_result = processTraps($state, $pid, $new_pos['x'], $new_pos['y']);
            
            // Проверяем зоны угрозы NPC на новой позиции
            checkThreatZoneIntersection($state, $pid, $new_pos['x'], $new_pos['y']);
            
            // Проверяем выход из комнаты
            $room_key = formatXY($new_pos['x'], $new_pos['y']);
            if (isset($state['room']['exits'][$room_key])) {
                // Переход в другую комнату (заглушка - просто логируем)
                $state = addLog($state, $pid . ' переходит в комнату ' . $state['room']['exits'][$room_key]);
            }
        } else {
            // Плавное перемещение - обновляем CUR_POSITION_XY для клиента
            $start = parseXY($player['START_POSITION_XY']);
            $end = parseXY($player['END_POSITION_XY']);
            $total_time = $player['MOVE_END_TIME'] - $player['MOVE_START_TIME'];
            $elapsed = $now - $player['MOVE_START_TIME'];
            
            if ($total_time > 0) {
                $progress = min(1, $elapsed / $total_time);
                $cur_x = $start['x'] + ($end['x'] - $start['x']) * $progress;
                $cur_y = $start['y'] + ($end['y'] - $start['y']) * $progress;
                
                // Сохраняем старую позицию для проверки ловушек
                $old_pos = parseXY($player['CUR_POSITION_XY']);
                $new_x = round($cur_x);
                $new_y = round($cur_y);
                
                // Обновляем позицию
                $player['CUR_POSITION_XY'] = formatXY($new_x, $new_y);
                
                // Проверяем ловушки и зоны угрозы если клетка изменилась
                if ($old_pos['x'] !== $new_x || $old_pos['y'] !== $new_y) {
                    $trap_result = processTraps($state, $pid, $new_x, $new_y);
                    if ($trap_result['triggered']) {
                        $state = addLog($state, $pid . ' активировал ловушку ' . $trap_result['trap'] . ' во время движения');
                    }
                    
                    // Проверяем зоны угрозы NPC
                    checkThreatZoneIntersection($state, $pid, $new_x, $new_y);
                }
            }
        }
    }
}

/**
 * Обновить статусы смерти (анимации death/decay)
 */
function updateDeathAnimations(&$state) {
    $now = time();
    
    foreach ($state['players'] as $pid => &$player) {
        // Анимация DEATH (1 секунда)
        if ($player['STATUS'] === 'DEATH') {
            if (!isset($player['DEATH_TIME'])) {
                $player['DEATH_TIME'] = $now;
            } elseif ($now - $player['DEATH_TIME'] >= 1) {
                // Переходим в DECAY
                $player['STATUS'] = 'DECAY';
                $player['DECAY_TIME'] = $now;
            }
        }
        
        // Анимация DECAY (1 секунда)
        if ($player['STATUS'] === 'DECAY') {
            if (!isset($player['DECAY_TIME'])) {
                $player['DECAY_TIME'] = $now;
            } elseif ($now - $player['DECAY_TIME'] >= 1) {
                // Переносим на spawn если есть жизни
                if (isset($player['LIFE']) && $player['LIFE'] > 0) {
                    $player['LIFE']--;
                    $player['ROOM_ID'] = $player['SPAWN_ROOM'] ?? 'SPAWN';
                    $player['STATUS'] = 'IDLE';
                    $player['HITPOINTS'] = $player['MAX_HITPOINTS'];
                    $player['SHIELD'] = $player['MAX_SHIELD'];
                    $player['NEGATIVES'] = [];
                    
                    // Ищем свободную позицию для возрождения
                    $spawn_pos = parseXY($player['START_POSITION_XY']);
                    if (isPlayerAtPosition($state, $spawn_pos['x'], $spawn_pos['y'], $pid)) {
                        // Если стартовая позиция занята, ищем свободную рядом
                        $found = false;
                        for ($dx = -2; $dx <= 2 && !$found; $dx++) {
                            for ($dy = -2; $dy <= 2 && !$found; $dy++) {
                                $new_x = $spawn_pos['x'] + $dx;
                                $new_y = $spawn_pos['y'] + $dy;
                                if ($new_x >= 0 && $new_x < GRID_WIDTH && $new_y >= 0 && $new_y < GRID_HEIGHT) {
                                    if (!isPlayerAtPosition($state, $new_x, $new_y, $pid) && isCellPassable($state, $new_x, $new_y)) {
                                        $player['CUR_POSITION_XY'] = formatXY($new_x, $new_y);
                                        $player['END_POSITION_XY'] = formatXY($new_x, $new_y);
                                        $found = true;
                                    }
                                }
                            }
                        }
                        if (!$found) {
                            // Если не нашли свободную позицию, возвращаем на стартовую
                            $player['CUR_POSITION_XY'] = $player['START_POSITION_XY'];
                            $player['END_POSITION_XY'] = $player['START_POSITION_XY'];
                        }
                    } else {
                        // Стартовая позиция свободна
                        $player['CUR_POSITION_XY'] = $player['START_POSITION_XY'];
                        $player['END_POSITION_XY'] = $player['START_POSITION_XY'];
                    }
                    
                    unset($player['DEATH_TIME']);
                    unset($player['DECAY_TIME']);
                    
                    $state = addLog($state, $pid . ' возрождается на spawn (осталось жизней: ' . $player['LIFE'] . ')');
                } else {
                    // Жизни закончились - убираем игрока и очищаем позицию
                    $player['STATUS'] = 'REMOVED';
                    $player['CUR_POSITION_XY'] = '-1,-1'; // Очищаем позицию
                    $player['END_POSITION_XY'] = '-1,-1';
                    unset($player['DEATH_TIME']);
                    unset($player['DECAY_TIME']);
                    $state = addLog($state, $pid . ' полностью уничтожен');
                }
            }
        }
    }
}

/**
 * Обновить снаряды
 */
function updateProjectiles(&$state) {
    $now = time();
    
    foreach ($state['projectiles'] as &$proj) {
        if ($proj['status'] === 'cast' && $now - $proj['created'] >= 1) {
            $proj['status'] = 'hit';
        }
        if ($proj['status'] === 'hit' && $now - $proj['created'] >= 2) {
            $proj['status'] = 'done';
        }
    }
    
    // Убираем завершенные снаряды
    $state['projectiles'] = array_filter($state['projectiles'], function($p) {
        return $p['status'] !== 'done';
    });
    $state['projectiles'] = array_values($state['projectiles']);
}

/**
 * Сбросить статусы атак (после анимации)
 */
function resetAttackStatus(&$state) {
    foreach ($state['players'] as $pid => &$player) {
        // Анимация способности (ABILITY) тоже возвращается в IDLE через 1 сек
        // (ATTACK_TIME ставится и для ATTACK, и для ABILITY в action.php)
        if ($player['STATUS'] === 'ATTACK' || $player['STATUS'] === 'ABILITY') {
            // Через 1 секунду возвращаем в IDLE
            if (!isset($player['ATTACK_TIME'])) {
                $player['ATTACK_TIME'] = time();
            } elseif (time() - $player['ATTACK_TIME'] >= 1) {
                $player['STATUS'] = 'IDLE';
                unset($player['ATTACK_TIME']);
            }
        }
    }
}

/**
 * Проверить условия победы
 */
function checkVictory($state) {
    $alive_teams = [];
    
    foreach ($state['players'] as $pid => $player) {
        if ($player['STATUS'] === 'DEATH') continue;
        
        // Определяем команду
        $team = $pid;
        if ($player['ALLY'] !== 'NONE') {
            $team = min($pid, $player['ALLY']);
        }
        
        if (!isset($alive_teams[$team])) {
            $alive_teams[$team] = [];
        }
        $alive_teams[$team][] = $pid;
    }
    
    if (count($alive_teams) <= 1) {
        $winners = array_keys($alive_teams);
        return ['victory' => true, 'winners' => $winners];
    }
    
    return ['victory' => false, 'winners' => []];
}
