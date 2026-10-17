<?php
/**
 * systems/targeting.php — Единый универсальный доступ к сущностям мира.
 *
 * ДО РЕФАКТОРИНГА выбор целей был завязан на поле ALLY игрока и дублировал
 * обход players/npcs/objects в 4 местах (findTargetAtPosition, getTargetsOnLine,
 * getTargetsInArea, applySplashDamage). Теперь:
 *   - resolveEntity($state,$type,$id) — позиция/живость/ALLYSHIP/RESISTANCE для
 *     player|npc|object одним кодом;
 *   - hostile_to() — ЕДИНСТВЕННАЯ функция «враждебен ли caster цели» (учитывает
 *     двустороннюю ALLY-связь игроков и ALLY создателя у npc/object);
 *   - find_target_at_position / get_targets_on_line / get_targets_in_area —
 *     работают для любых типов целей (NPC и объекты тоже могут быть целью AoE).
 */

require_once __DIR__ . '/../engine/geometry.php';

/**
 * Универсальный снимок сущности. Возвращает null если сущность мертва/удалена.
 * ['id','type','x','y','ally','resistance','status']
 */
function resolveEntity($state, $type, $id) {
    if ($type === 'player') {
        $p = $state['players'][$id] ?? null;
        if (!$p) return null;
        if (in_array($p['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) return null;
        // Для движущегося игрока «фактическая» клетка — промежуточная CUR
        $pos = parseXY($p['CUR_POSITION_XY']);
        return [
            'id' => $id, 'type' => 'player',
            'x' => $pos['x'], 'y' => $pos['y'],
            'ally' => $p['ALLY'] ?? 'NONE',
            'resistance' => $p['RESISTANCE'] ?? 0,
            'status' => $p['STATUS'],
        ];
    }
    if ($type === 'npc') {
        $n = $state['npcs'][$id] ?? null;
        if (!$n) return null;
        if (in_array($n['STATUS'], ['DESTROYED', 'DEATH', 'REMOVED'], true)) return null;
        $pos = parseXY($n['POSITION_XY']);
        return [
            'id' => $id, 'type' => 'npc',
            'x' => $pos['x'], 'y' => $pos['y'],
            'ally' => $n['ALLY'] ?? 'NONE',
            'resistance' => 0,
            'status' => $n['STATUS'],
        ];
    }
    if ($type === 'object') {
        $o = $state['objects'][$id] ?? null;
        if (!$o) return null;
        if (in_array($o['STATUS'], ['DESTROYED', 'TRIGGERED'], true)) return null;
        $pos = parseXY($o['POSITION_XY']);
        return [
            'id' => $id, 'type' => 'object',
            'x' => $pos['x'], 'y' => $pos['y'],
            'ally' => $o['ALLY'] ?? 'NONE',
            'resistance' => 0,
            'status' => $o['STATUS'],
        ];
    }
    return null;
}

/** Все живые сущности всех типов (для обхода целей) */
function all_entities($state) {
    $list = [];
    foreach (array_keys($state['players']) as $id) {
        $e = resolveEntity($state, 'player', $id);
        if ($e) $list[] = $e;
    }
    foreach (array_keys($state['npcs']) as $id) {
        $e = resolveEntity($state, 'npc', $id);
        if ($e) $list[] = $e;
    }
    foreach (array_keys($state['objects']) as $id) {
        $e = resolveEntity($state, 'object', $id);
        if ($e) $list[] = $e;
    }
    return $list;
}

/**
 * Враждебна ли сущность $entity кастеру $caster_id (игрок)?
 * Правила (сохраняют прежнее поведение):
 *   - игрок враждебен всем, кроме себя, своего ALLY и тех, чей ALLY == caster;
 *   - npc/object с ALLY='NONE' враждебны всем; иначе не враждебны создателю
 *     и его союзнику.
 */
function hostile_to($entity, $caster_id, $caster_ally) {
    if ($entity['id'] === $caster_id) return false;

    if ($entity['type'] === 'player') {
        if ($entity['id'] === $caster_ally) return false;
        if ($entity['ally'] === $caster_id) return false;
        return true;
    }
    // npc / object
    if ($entity['ally'] !== 'NONE') {
        if ($entity['ally'] === $caster_id || $entity['ally'] === $caster_ally) return false;
    }
    return true;
}

/** Найти первую враждебную цель на клетке (или null) */
function find_target_at_position($state, $x, $y, $caster_id) {
    $caster = resolveEntity($state, 'player', $caster_id);
    if (!$caster) return null;
    foreach (all_entities($state) as $e) {
        if ($e['x'] === $x && $e['y'] === $y && hostile_to($e, $caster_id, $caster['ally'])) {
            return $e;
        }
    }
    return null;
}

/**
 * Все враждебные цели на прямой от (x1,y1) до (x2,y2), луч останавливается
 * на непроходимой клетке (стена/объект) — как раньше в abilities.php.
 */
function get_targets_on_line($state, $x1, $y1, $x2, $y2, $caster_id) {
    $targets = [];
    $caster = resolveEntity($state, 'player', $caster_id);
    if (!$caster) return $targets;

    list($dx, $dy) = directionStep($x1, $y1, $x2, $y2);
    $cx = $x1 + $dx;
    $cy = $y1 + $dy;

    while (isValidPosition($cx, $cy)) {
        foreach (all_entities($state) as $e) {
            if ($e['x'] === $cx && $e['y'] === $cy && hostile_to($e, $caster_id, $caster['ally'])) {
                $targets[] = $e;
            }
        }
        if (!is_cell_passable($state, $cx, $cy)) break; // препятствие останавливает луч
        $cx += $dx;
        $cy += $dy;
    }
    return $targets;
}

/** Все враждебные цели в манхэттен-радиусе от точки */
function get_targets_in_area($state, $center_x, $center_y, $radius, $caster_id) {
    $targets = [];
    $caster = resolveEntity($state, 'player', $caster_id);
    if (!$caster) return $targets;

    foreach (all_entities($state) as $e) {
        if (inRadius($center_x, $center_y, $e['x'], $e['y'], $radius) &&
            hostile_to($e, $caster_id, $caster['ally'])) {
            $targets[] = $e;
        }
    }
    return $targets;
}

/**
 * Есть ли игрок на клетке (занятость для движения). Учитывает MOVING:
 * конечная клетка движущегося тоже считается занятой (прежнее поведение battle.php).
 */
function is_player_at_position($state, $x, $y, $exclude_id = null) {
    foreach ($state['players'] as $pid => $player) {
        if ($pid === $exclude_id) continue;
        if ($player['STATUS'] === 'DEATH') continue;

        $pos = parseXY($player['CUR_POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return true;

        if ($player['STATUS'] === 'MOVING') {
            $end_pos = parseXY($player['END_POSITION_XY']);
            if ($end_pos['x'] === $x && $end_pos['y'] === $y) return true;
        }
    }
    return false;
}

/**
 * Проходимость клетки: стена (grid==0) или неразрушенный объект блокируют.
 * (Игроков проверяет вызывающий — см. is_player_at_position.)
 */
function is_cell_passable($state, $x, $y) {
    if (!isValidPosition($x, $y)) return false;
    if (isset($state['room']['grid'][$y][$x]) && $state['room']['grid'][$y][$x] === 0) {
        return false;
    }
    foreach ($state['objects'] as $obj) {
        if ($obj['STATUS'] === 'DESTROYED') continue;
        $pos = parseXY($obj['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return false;
    }
    return true;
}
