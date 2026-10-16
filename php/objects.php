<?php
/**
 * objects.php - Логика автономных объектов боя (NPC и ловушки)
 *
 * Автономные объекты - сущности, которые действуют сами по себе,
 * без прямого управления игроком:
 *
 * - NPC (стационарные турели, будущие дроны):
 *   имеют ACTIONS, ATTACK, RANGE, ALLY и зону угрозы (THREAT_ZONE).
 *   Атакуют враждебных игроков, попадающих в зону угрозы.
 *
 * - Ловушки (мины, будущие размещаемые объектами/способностями устройства):
 *   имеют EFFECT, DAMAGE, ALLY. Срабатывают при входе враждебного
 *   игрока на свою клетку и самоликвидируются (STATUS = TRIGGERED).
 *
 * Поле ALLY у автономных объектов означает "кто создал объект":
 * игрок-создатель и его союзник НЕ воспринимаются объектом как враги.
 * У нейтральных объектов ALLY = 'NONE' (враждебны всем).
 *
 * ВАЖНО (задел на будущее): часть автономных объектов будет
 * создаваться СПОСОБНОСТЯМИ игроков (дроны, турели, мины). Поэтому
 * вся логика таких объектов вынесена из items.php в этот файл:
 * items.php отвечает ТОЛЬКО за пассивные способности игроков.
 *
 * Подключается через battle.php (require_once abilities.php + items.php),
 * поэтому функции calculateDamage / applyNegativeEffect доступны.
 */

// Реестр типов автономных объектов (задел на будущее: сюда добавляются
// новые типы spawn-объектов, создаваемые способностями игроков)
function getAutonomousObjectDefinitions() {
    return [
        'DEFAULT_TURRET' => [
            'image' => 'DEFAULT_TURRET',
            'category' => 'npc',
            'description' => 'Стационарная турель. Контролирует зону угрозы (RANGE, манхэттен + линия видимости), атакует любого враждебного игрока, попавшего в зону.',
        ],
        'LAND_MINE' => [
            'image' => 'LAND_MINE',
            'category' => 'trap',
            'description' => 'Ловушка. При входе враждебного игрока на клетку наносит DAMAGE и накладывает EFFECT, после чего срабатывает (TRIGGERED).',
        ],
    ];
}

/**
 * ============================================================================
 * РАЗДЕЛ 1: NPC (автономные стрелковые объекты)
 * ============================================================================
 */

/**
 * Вычислить зону угрозы для NPC (клетки под контролем)
 * Проверяет линию видимости для каждой клетки
 */
function calculateThreatZone(&$state, $npc_id) {
    $npc = &$state['npcs'][$npc_id];
    $npc_pos = parseXY($npc['POSITION_XY']);
    $range = $npc['RANGE'];
    $threat_cells = [];

    // Проверяем все клетки в радиусе (манхэттенское расстояние)
    for ($dx = -$range; $dx <= $range; $dx++) {
        for ($dy = -$range; $dy <= $range; $dy++) {
            $x = $npc_pos['x'] + $dx;
            $y = $npc_pos['y'] + $dy;

            // Проверяем границы
            if (!isValidPosition($x, $y)) continue;

            // Пропускаем саму позицию NPC
            if ($x === $npc_pos['x'] && $y === $npc_pos['y']) continue;

            // Проверяем манхэттенское расстояние
            $dist = abs($dx) + abs($dy);
            if ($dist > $range) continue;

            // Проверяем линию видимости для ВСЕХ клеток
            if (hasLineOfSightNPC($state, $npc_pos['x'], $npc_pos['y'], $x, $y)) {
                $threat_cells[] = ['x' => $x, 'y' => $y];
            }
        }
    }

    return $threat_cells;
}

/**
 * Проверка линии видимости между двумя точками (для NPC)
 * Проверяет по прямой (горизонталь/вертикаль) и по диагонали
 */
function hasLineOfSightNPC($state, $x1, $y1, $x2, $y2) {
    $dx = ($x2 > $x1) ? 1 : (($x2 < $x1) ? -1 : 0);
    $dy = ($y2 > $y1) ? 1 : (($y2 < $y1) ? -1 : 0);

    // Если движение только по одной оси (прямая линия)
    if ($dx === 0 || $dy === 0) {
        $cx = $x1 + $dx;
        $cy = $y1 + $dy;

        while (($cx !== $x2 || $cy !== $y2) && isValidPosition($cx, $cy)) {
            // Проверяем стену
            if (isset($state['room']['grid'][$cy][$cx]) && $state['room']['grid'][$cy][$cx] === 0) {
                return false;
            }

            // Проверяем объекты (блокируют линию видимости)
            foreach ($state['objects'] as $obj) {
                if ($obj['STATUS'] === 'DESTROYED') continue;
                $pos = parseXY($obj['POSITION_XY']);
                if ($pos['x'] === $cx && $pos['y'] === $cy) {
                    return false;
                }
            }

            // Проверяем других игроков (блокируют линию видимости)
            foreach ($state['players'] as $pid => $player) {
                if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'REMOVED') continue;
                $pos = parseXY($player['CUR_POSITION_XY']);
                if ($pos['x'] === $cx && $pos['y'] === $cy) {
                    return false;
                }
            }

            $cx += $dx;
            $cy += $dy;
        }

        return true;
    }

    // Если движение по диагонали - проверяем обе оси отдельно
    // Сначала проверяем горизонтальную линию
    $cx = $x1 + $dx;
    $cy = $y1;
    while ($cx !== $x2 && isValidPosition($cx, $cy)) {
        if (isset($state['room']['grid'][$cy][$cx]) && $state['room']['grid'][$cy][$cx] === 0) {
            return false;
        }
        foreach ($state['objects'] as $obj) {
            if ($obj['STATUS'] === 'DESTROYED') continue;
            $pos = parseXY($obj['POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) {
                return false;
            }
        }
        foreach ($state['players'] as $pid => $player) {
            if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'REMOVED') continue;
            $pos = parseXY($player['CUR_POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) {
                return false;
            }
        }
        $cx += $dx;
    }

    // Затем проверяем вертикальную линию
    $cx = $x2;
    $cy = $y1 + $dy;
    while ($cy !== $y2 && isValidPosition($cx, $cy)) {
        if (isset($state['room']['grid'][$cy][$cx]) && $state['room']['grid'][$cy][$cx] === 0) {
            return false;
        }
        foreach ($state['objects'] as $obj) {
            if ($obj['STATUS'] === 'DESTROYED') continue;
            $pos = parseXY($obj['POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) {
                return false;
            }
        }
        foreach ($state['players'] as $pid => $player) {
            if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'REMOVED') continue;
            $pos = parseXY($player['CUR_POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) {
                return false;
            }
        }
        $cy += $dy;
    }

    return true;
}

/**
 * Проверить пересечение игрока с зонами угрозы
 */
function checkThreatZoneIntersection(&$state, $player_id, $x, $y) {
    $player = &$state['players'][$player_id];
    $results = [];

    // Проверяем все NPC
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if ($npc['STATUS'] === 'DESTROYED' || $npc['STATUS'] === 'DEATH') continue;

        // NPC может атаковать только если есть ACTIONS
        if ($npc['ACTIONS'] <= 0) continue;

        // Пропускаем союзников
        if ($npc['ALLY'] !== 'NONE') {
            if ($npc['ALLY'] === $player_id || $player['ALLY'] === $npc['ALLY']) continue;
        }

        // Проверяем есть ли зона угрозы
        if (!isset($npc['THREAT_ZONE'])) {
            $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
        }

        // Проверяем находится ли игрок в зоне угрозы
        $in_zone = false;
        foreach ($npc['THREAT_ZONE'] as $cell) {
            if ($cell['x'] === $x && $cell['y'] === $y) {
                $in_zone = true;
                break;
            }
        }

        if ($in_zone) {
            // NPC атакует игрока
            $damage = $npc['ATTACK'];
            $npc['ACTIONS']--; // Тратим действие (атакует только один раз за ход)

            $state = addLog($state, "NPC {$npc_id}: атакует {$player_id} при пересечении зоны на клетке ({$x},{$y})");

            // Применяем урон с учётом щита
            $shield_absorbed = 0;
            if ($player['SHIELD'] > 0) {
                $shield_absorbed = min($player['SHIELD'], $damage);
                $player['SHIELD'] -= $shield_absorbed;
                $damage -= $shield_absorbed;
            }

            if ($damage > 0) {
                $player['HITPOINTS'] -= $damage;

                // Прерываем движение при получении урона
                if ($player['STATUS'] === 'MOVING') {
                    $player['CUR_POSITION_XY'] = formatXY($x, $y);
                    $player['START_POSITION_XY'] = formatXY($x, $y);
                    $player['END_POSITION_XY'] = formatXY($x, $y);
                    $player['STATUS'] = 'IDLE';
                    unset($player['MOVE_END_TIME']);
                    unset($player['MOVE_START_TIME']);
                    unset($player['MOVE_DISTANCE']);
                }

                if ($player['HITPOINTS'] <= 0) {
                    $player['HITPOINTS'] = 0;
                    $player['STATUS'] = 'DEATH';
                    $state = addLog($state, "NPC {$npc_id}: убил {$player_id}!");
                }
            }

            $log_msg = "NPC {$npc_id}: {$player_id} получил {$npc['ATTACK']} урона";
            if ($shield_absorbed > 0) {
                $log_msg .= ", щит поглотил {$shield_absorbed}";
            }
            if ($damage > 0) {
                $log_msg .= ", HP теперь: {$player['HITPOINTS']}";
            }
            $state = addLog($state, $log_msg);

            $results[] = ['npc' => $npc_id, 'damage' => $npc['ATTACK']];
        }
    }

    return $results;
}

/**
 * Обработка ИИ для NPC (Turret)
 * Упрощённая версия - основная логика перенесена в checkThreatZoneIntersection
 * Эта функция оставлена для обратной совместимости
 */
function processNPCs(&$state) {
    // Основная логика теперь в checkThreatZoneIntersection
    // который вызывается при каждом движении игрока и в начале хода
    // Эта функция оставлена для совместимости
}

/**
 * ============================================================================
 * РАЗДЕЛ 2: Ловушки (мины и future размещаемые объектами/способностями устройства)
 * ============================================================================
 */

/**
 * Обработка ловушек при входе на клетку
 */
function processTraps(&$state, $player_id, $x, $y) {
    $player = &$state['players'][$player_id];
    $ally = $player['ALLY'];

    foreach ($state['traps'] as $tid => &$trap) {
        if ($trap['STATUS'] !== 'INTACT') {
            continue;
        }

        // Проверяем не своя ли ловушка
        // Ловушка с ALLY="NONE" враждебна всем
        if ($trap['ALLY'] !== 'NONE') {
            if ($trap['ALLY'] === $player_id || $trap['ALLY'] === $ally) {
                continue;
            }
        }

        $tpos = parseXY($trap['POSITION_XY']);
        if ($tpos['x'] === $x && $tpos['y'] === $y) {
            $state = addLog($state, "TRAP: {$player_id} наступил на ловушку {$tid} на клетке ({$x},{$y})");

            // Ловушка срабатывает!
            $trap['STATUS'] = 'TRIGGERED';

            // Применяем эффекты ловушки
            if (isset($trap['EFFECT']) && is_array($trap['EFFECT'])) {
                foreach ($trap['EFFECT'] as $effect) {
                    applyNegativeEffect($state, $player_id, 'player', $effect);
                }
            }

            // Урон от ловушки (если есть)
            if (isset($trap['DAMAGE']) && $trap['DAMAGE'] > 0) {
                $damage = $trap['DAMAGE'];
                $state = addLog($state, "TRAP: ловушка наносит {$damage} урона {$player_id}");

                // Сначала щит
                if ($player['SHIELD'] > 0) {
                    $shield_absorb = min($player['SHIELD'], $damage);
                    $player['SHIELD'] -= $shield_absorb;
                    $damage -= $shield_absorb;
                    $state = addLog($state, "TRAP: щит поглотил {$shield_absorb}");
                }

                // Остаток в HP
                if ($damage > 0) {
                    $player['HITPOINTS'] -= $damage;
                    $state = addLog($state, "TRAP: {$player_id} получил {$damage} урона, HP: {$player['HITPOINTS']}");

                    if ($player['HITPOINTS'] <= 0) {
                        $player['HITPOINTS'] = 0;
                        $player['STATUS'] = 'DEATH';
                    }
                }
            }

            return ['triggered' => true, 'trap' => $tid];
        }
    }

    return ['triggered' => false];
}
