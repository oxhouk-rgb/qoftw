<?php
/**
 * items.php - Обработка пассивных способностей игроков (предметов)
 * 
 * ВНИМАНИЕ: этот файл отвечает ТОЛЬКО за пассивки игроков.
 * Логика автономных объектов (NPC-турели, ловушки/мины) вынесена
 * в отдельный файл objects.php.
 * 
 * Пассивные способности модифицируют поведение игроков:
 * - REGENERATION: восстановление 1 HP в ход
 * - ARMORED: снижение получаемого урона на 1 (после сопротивления)
 * - SPLASH: при атаке ближнего боя, урон также наносится соседним врагам (1 клетка)
 * - THORNS: ответный урон атакующему при получении урона
 * - SWIFT: +1 к дальности перемещения
 * - PUNCTURE: атака пробивает всех врагов на пути до цели
 * - другие пассивки могут быть добавлены
 * 
 * processTurnStart() также выполняет «ход» для автономных объектов
 * (восстановление ACTIONS у NPC) и вызывает проверку зон угрозы из
 * objects.php — это оркестрация начала хода, а не логика объектов.
 */

// Реестр пассивных способностей
function getPassiveDefinitions() {
    return [
        'REGENERATION' => [
            'name' => 'REGENERATION',
            'type' => 'on_turn_start',
            'description' => 'Восстанавливает 1 HP каждый ход',
            'value' => 1
        ],
        'ARMORED' => [
            'name' => 'ARMORED',
            'type' => 'on_damage_taken',
            'description' => 'Снижает получаемый урон на 1',
            'value' => 1
        ],
        'SPLASH' => [
            'name' => 'SPLASH',
            'type' => 'on_attack',
            'description' => 'При атаке наносит 1 урон соседним врагам',
            'value' => 1
        ],
        'THORNS' => [
            'name' => 'THORNS',
            'type' => 'on_hit',
            'description' => 'При получении урона наносит 1 урон атакующему',
            'value' => 1
        ],
        'SWIFT' => [
            'name' => 'SWIFT',
            'type' => 'passive_stat',
            'description' => '+1 к дальности перемещения',
            'value' => 1
        ],
        'PUNCTURE' => [
            'name' => 'PUNCTURE',
            'type' => 'on_attack',
            'description' => 'Атака пробивает всех врагов на пути до цели',
            'value' => 0
        ]
    ];
}

/**
 * Обработка начала хода для всех игроков
 * Применяет пассивки типа on_turn_start и обрабатывает негативные эффекты
 */
function processTurnStart(&$state) {
    foreach ($state['players'] as $pid => &$player) {
        if ($player['STATUS'] === 'DEATH') continue;
        
        // Восстановление очков действий
        $player['ACTIONS'] = $player['MAX_ACTIONS'];
        
        // Регенерация щита (1 за ход)
        if ($player['SHIELD'] < $player['MAX_SHIELD']) {
            $player['SHIELD'] = min($player['SHIELD'] + 1, $player['MAX_SHIELD']);
        }
        
        // Пассивка REGENERATION
        if (hasPassive($player, 'REGENERATION')) {
            $regen = getPassiveValue($player, 'REGENERATION');
            $player['HITPOINTS'] = min($player['HITPOINTS'] + $regen, $player['MAX_HITPOINTS']);
        }
        
        // Обработка негативных эффектов
        processNegatives($state, $pid);
    }
    
    // Обработка NPC - восстановление очков действий
    foreach ($state['npcs'] as $nid => &$npc) {
        if ($npc['STATUS'] === 'DESTROYED' || $npc['STATUS'] === 'DEATH') continue;
        $npc['ACTIONS'] = $npc['MAX_ACTIONS'];
    }
    
    // Проверяем зоны угрозы для всех стоящих на месте игроков
    // (движущиеся игроки проверяются в updateMovements)
    foreach ($state['players'] as $pid => &$player) {
        if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'MOVING') continue;
        
        $pos = parseXY($player['CUR_POSITION_XY']);
        checkThreatZoneIntersection($state, $pid, $pos['x'], $pos['y']);
    }
}

/**
 * Обработка негативных эффектов игрока
 */
function processNegatives(&$state, $player_id) {
    $player = &$state['players'][$player_id];
    $new_negatives = [];
    
    foreach ($player['NEGATIVES'] as $neg) {
        preg_match('/^([A-Z_]+)\[(\d+)\]$/', $neg, $matches);
        if (count($matches) < 3) continue;
        
        $effect_name = $matches[1];
        $duration = (int)$matches[2];
        
        // Применяем эффект текущего хода
        switch ($effect_name) {
            case 'POISONED':
                $player['HITPOINTS'] -= 1;
                if ($player['HITPOINTS'] <= 0) {
                    $player['HITPOINTS'] = 0;
                    $player['STATUS'] = 'DEATH';
                }
                break;
                
            case 'BLEED':
                $player['HITPOINTS'] -= 1;
                if ($player['HITPOINTS'] <= 0) {
                    $player['HITPOINTS'] = 0;
                    $player['STATUS'] = 'DEATH';
                }
                break;
                
            case 'FROZEN':
                // Замороженный теряет 1 очко действия
                $player['ACTIONS'] = max(0, $player['ACTIONS'] - 1);
                break;
                
            case 'STUNNED':
                // Оглушенный не может действовать.
                // ВАЖНО: STATUS='STUNNED' ставится НЕ только здесь (на старте
                // хода), но и немедленно при наложении эффекта (см.
                // applyNegativeEffect в abilities.php) — иначе игрок, оглушенный
                // ловушкой во время движения, оставался бы без статуса до
                // следующего хода. MOVING не прерываем: движение само по себе
                // блокирует действия, а спрайт должен доехать до клетки мины.
                $player['ACTIONS'] = 0;
                if ($player['STATUS'] !== 'MOVING') {
                    $player['STATUS'] = 'STUNNED';
                }
                break;
                
            case 'SLOWED':
                // Замедленный - обрабатывается при движении
                break;
                
            case 'BLINDED':
                // Ослепленный - дальность атаки/способностей / 2
                break;
        }
        
        // Уменьшаем длительность
        $duration--;
        if ($duration > 0) {
            $new_negatives[] = $effect_name . '[' . $duration . ']';
        } else {
            // Эффект закончился - убираем статус если был от него
            if ($effect_name === 'STUNNED' && $player['STATUS'] === 'STUNNED') {
                $player['STATUS'] = 'IDLE';
            }
        }
    }
    
    $player['NEGATIVES'] = $new_negatives;
}

/**
 * Модификация урона пассивками при получении урона
 */
function applyPassiveDefense($player, $damage) {
    // ARMORED снижает урон
    if (hasPassive($player, 'ARMORED')) {
        $reduction = getPassiveValue($player, 'ARMORED');
        $damage = max(1, $damage - $reduction);
    }
    return $damage;
}

/**
 * Обработка пассивки SPLASH при атаке
 */
function applySplashDamage(&$state, $attacker_id, $target_x, $target_y) {
    $state = addLog($state, "DEBUG: Вход в applySplashDamage для {$attacker_id}");
    
    $attacker = $state['players'][$attacker_id];
    
    // Проверяем наличие пассивки
    if (!isset($attacker['PASSIVES']) || !in_array('SPLASH', $attacker['PASSIVES'])) {
        $state = addLog($state, "DEBUG: У {$attacker_id} нет пассивки SPLASH");
        return;
    }
    
    $state = addLog($state, "DEBUG: У {$attacker_id} есть пассивка SPLASH");
    
    $base_damage = $attacker['ATTACK']; // Базовый урон равен ATTACK атакующего
    $ally = $attacker['ALLY'] ?? 'NONE';
    
    // Логируем вызов SPLASH
    $state = addLog($state, "SPLASH: {$attacker_id} (ATK:{$base_damage}) атакует клетку ({$target_x},{$target_y}), ищем соседних врагов");
    
    // Находим соседних врагов (все 8 соседних клеток, включая диагонали)
    $adjacent = [
        [$target_x - 1, $target_y],      // лево
        [$target_x + 1, $target_y],      // право
        [$target_x, $target_y - 1],      // верх
        [$target_x, $target_y + 1],      // низ
        [$target_x - 1, $target_y - 1],  // верхний левый
        [$target_x + 1, $target_y - 1],  // верхний правый
        [$target_x - 1, $target_y + 1],  // нижний левый
        [$target_x + 1, $target_y + 1]   // нижний правый
    ];
    
    foreach ($adjacent as $pos) {
        // Проверяем валидность координат
        if ($pos[0] < 0 || $pos[0] >= GRID_WIDTH || $pos[1] < 0 || $pos[1] >= GRID_HEIGHT) {
            $state = addLog($state, "SPLASH: клетка ({$pos[0]},{$pos[1]}) вне карты");
            continue;
        }
        
        $state = addLog($state, "SPLASH: проверяем клетку ({$pos[0]},{$pos[1]})");
        
        foreach ($state['players'] as $pid => &$player) {
            $state = addLog($state, "SPLASH: проверяем игрока {$pid}");
            
            // Пропускаем атакующего
            if ($pid === $attacker_id) {
                $state = addLog($state, "SPLASH: {$pid} - атакующий, пропускаем");
                continue;
            }
            
            // Пропускаем союзников
            if ($pid === $ally) {
                $state = addLog($state, "SPLASH: {$pid} - союзник (прямой), пропускаем");
                continue;
            }
            if (isset($player['ALLY']) && $player['ALLY'] === $attacker_id) {
                $state = addLog($state, "SPLASH: {$pid} - союзник (обратный), пропускаем");
                continue;
            }
            
            // Пропускаем мёртвых
            if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'REMOVED') {
                $state = addLog($state, "SPLASH: {$pid} - мёртв ({$player['STATUS']}), пропускаем");
                continue;
            }
            
            // Используем актуальную позицию (для движущихся - конечную позицию)
            if ($player['STATUS'] === 'MOVING' && isset($player['END_POSITION_XY'])) {
                $ppos = parseXY($player['END_POSITION_XY']);
                $state = addLog($state, "SPLASH: {$pid} двигается, позиция END: ({$ppos['x']},{$ppos['y']})");
            } else {
                $ppos = parseXY($player['CUR_POSITION_XY']);
                $state = addLog($state, "SPLASH: {$pid} стоит, позиция CUR: ({$ppos['x']},{$ppos['y']})");
            }
            
            if ($ppos['x'] === $pos[0] && $ppos['y'] === $pos[1]) {
                $state = addLog($state, "SPLASH: ✓ {$pid} найден на клетке ({$pos[0]},{$pos[1]})");
                
                // Рассчитываем урон с учётом сопротивления цели
                $resistance = $player['RESISTANCE'] ?? 0;
                $splash_damage = calculateDamage($base_damage, $base_damage, $resistance);
                
                // Применяем урон С УЧЁТОМ ЩИТА
                $original_damage = $splash_damage;
                $shield_absorbed = 0;
                
                // Сначала щит поглощает урон
                if ($state['players'][$pid]['SHIELD'] > 0) {
                    $shield_absorbed = min($state['players'][$pid]['SHIELD'], $splash_damage);
                    $state['players'][$pid]['SHIELD'] -= $shield_absorbed;
                    $splash_damage -= $shield_absorbed;
                }
                
                // Остаток идёт в HP
                if ($splash_damage > 0) {
                    $state['players'][$pid]['HITPOINTS'] -= $splash_damage;
                }
                
                // Логируем результат
                $log_msg = "SPLASH: ✓✓ {$pid} (RES:{$resistance}) получил {$original_damage} урона от splash";
                if ($shield_absorbed > 0) {
                    $log_msg .= ", щит поглотил {$shield_absorbed}";
                }
                if ($splash_damage > 0) {
                    $log_msg .= ", HP теперь: {$state['players'][$pid]['HITPOINTS']}";
                }
                $state = addLog($state, $log_msg);
                
                // Прерываем движение при получении урона
                if ($state['players'][$pid]['STATUS'] === 'MOVING') {
                    $start = parseXY($state['players'][$pid]['START_POSITION_XY']);
                    $end = parseXY($state['players'][$pid]['END_POSITION_XY']);
                    $now = microtime(true);
                    $total_time = $state['players'][$pid]['MOVE_END_TIME'] - $state['players'][$pid]['MOVE_START_TIME'];
                    $elapsed = $now - $state['players'][$pid]['MOVE_START_TIME'];
                    
                    if ($total_time > 0) {
                        $progress = min(1, $elapsed / $total_time);
                        $cur_x = $start['x'] + ($end['x'] - $start['x']) * $progress;
                        $cur_y = $start['y'] + ($end['y'] - $start['y']) * $progress;
                        $state['players'][$pid]['CUR_POSITION_XY'] = formatXY(round($cur_x), round($cur_y));
                        $state['players'][$pid]['START_POSITION_XY'] = $state['players'][$pid]['CUR_POSITION_XY'];
                        $state['players'][$pid]['END_POSITION_XY'] = $state['players'][$pid]['CUR_POSITION_XY'];
                    }
                    
                    $state['players'][$pid]['STATUS'] = 'IDLE';
                    unset($state['players'][$pid]['MOVE_END_TIME']);
                    unset($state['players'][$pid]['MOVE_START_TIME']);
                    unset($state['players'][$pid]['MOVE_DISTANCE']);
                }
                
                if ($state['players'][$pid]['HITPOINTS'] <= 0) {
                    $state['players'][$pid]['HITPOINTS'] = 0;
                    $state['players'][$pid]['STATUS'] = 'DEATH';
                }
                
                // Добавляем в лог
                $state = addLog($state, $attacker_id . ' наносит ' . $splash_damage . ' splash урона ' . $pid);
            } else {
                $state = addLog($state, "SPLASH: {$pid} не на клетке ({$ppos['x']},{$ppos['y']}) != ({$pos[0]},{$pos[1]})");
            }
        }
        
        // Проверяем NPC
        if (isset($state['npcs'])) {
            foreach ($state['npcs'] as $npc_id => &$npc) {
                if ($npc['STATUS'] === 'DEATH' || $npc['STATUS'] === 'REMOVED') {
                    continue;
                }
                
                $npc_pos = parseXY($npc['POSITION_XY']);
                if ($npc_pos['x'] === $pos[0] && $npc_pos['y'] === $pos[1]) {
                    $state = addLog($state, "SPLASH: ✓ NPC {$npc_id} найден на клетке ({$pos[0]},{$pos[1]})");
                    
                    // NPC не имеет сопротивления
                    $splash_damage = $base_damage;
                    
                    $state['npcs'][$npc_id]['HITPOINTS'] -= $splash_damage;
                    $state = addLog($state, "SPLASH: ✓✓ NPC {$npc_id} получил {$splash_damage} урона от splash, HP теперь: {$state['npcs'][$npc_id]['HITPOINTS']}");
                    
                    if ($state['npcs'][$npc_id]['HITPOINTS'] <= 0) {
                        $state['npcs'][$npc_id]['HITPOINTS'] = 0;
                        $state['npcs'][$npc_id]['STATUS'] = 'DEATH';
                    }
                }
            }
        }
        
        // Проверяем объекты
        if (isset($state['objects'])) {
            foreach ($state['objects'] as $obj_id => &$obj) {
                if ($obj['STATUS'] === 'DESTROYED') {
                    continue;
                }
                
                $obj_pos = parseXY($obj['POSITION_XY']);
                if ($obj_pos['x'] === $pos[0] && $obj_pos['y'] === $pos[1]) {
                    $state = addLog($state, "SPLASH: ✓ Объект {$obj_id} найден на клетке ({$pos[0]},{$pos[1]})");
                    
                    // Объекты не имеют сопротивления
                    $splash_damage = $base_damage;
                    
                    $state['objects'][$obj_id]['HITPOINTS'] -= $splash_damage;
                    $state = addLog($state, "SPLASH: ✓✓ Объект {$obj_id} получил {$splash_damage} урона от splash, HP теперь: {$state['objects'][$obj_id]['HITPOINTS']}");
                    
                    if ($state['objects'][$obj_id]['HITPOINTS'] <= 0) {
                        $state['objects'][$obj_id]['STATUS'] = 'DESTROYED';
                    }
                }
            }
        }
    }
}

/**
 * Проверка наличия пассивки у игрока
 */
function hasPassive($player, $passive_name) {
    return in_array($passive_name, $player['PASSIVES']);
}

/**
 * Получить значение пассивки
 */
function getPassiveValue($player, $passive_name) {
    $definitions = getPassiveDefinitions();
    if (isset($definitions[$passive_name])) {
        return $definitions[$passive_name]['value'];
    }
    return 0;
}
