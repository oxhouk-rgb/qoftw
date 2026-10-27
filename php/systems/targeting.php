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
 * ['id','type','x','y','room','ally','resistance','status']
 *
 * @param string|null $room_filter Опциональная комната: сущность из другой
 *        комнаты невидима (null — фильтр выключен, поведение единой карты).
 */
function resolveEntity($state, $type, $id, $room_filter = null) {
    if ($type === 'player') {
        $p = $state['players'][$id] ?? null;
        if (!$p) return null;
        if (in_array($p['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) return null;
        $room = $p['ROOM_ID'] ?? null;
        if ($room_filter !== null && $room !== $room_filter) return null;
        // Для движущегося игрока «фактическая» клетка — промежуточная CUR
        $pos = parseXY($p['CUR_POSITION_XY']);
        return [
            'id' => $id, 'type' => 'player',
            'x' => $pos['x'], 'y' => $pos['y'],
            'room' => $room,
            'ally' => $p['ALLY'] ?? 'NONE',
            'resistance' => $p['RESISTANCE'] ?? 0,
            'status' => $p['STATUS'],
        ];
    }
    if ($type === 'npc') {
        $n = $state['npcs'][$id] ?? null;
        if (!$n) return null;
        if (in_array($n['STATUS'], ['DESTROYED', 'DEATH', 'REMOVED'], true)) return null;
        $room = $n['ROOM_ID'] ?? null;
        if ($room_filter !== null && $room !== $room_filter) return null;
        $pos = parseXY($n['POSITION_XY']);
        return [
            'id' => $id, 'type' => 'npc',
            'x' => $pos['x'], 'y' => $pos['y'],
            'room' => $room,
            'ally' => $n['ALLY'] ?? 'NONE',
            'resistance' => 0,
            'status' => $n['STATUS'],
        ];
    }
    if ($type === 'object') {
        $o = $state['objects'][$id] ?? null;
        if (!$o) return null;
        if (in_array($o['STATUS'], ['DESTROYED', 'TRIGGERED'], true)) return null;
        $room = $o['ROOM_ID'] ?? null;
        if ($room_filter !== null && $room !== $room_filter) return null;
        $pos = parseXY($o['POSITION_XY']);
        return [
            'id' => $id, 'type' => 'object',
            'x' => $pos['x'], 'y' => $pos['y'],
            'room' => $room,
            'ally' => $o['ALLY'] ?? 'NONE',
            'resistance' => 0,
            'status' => $o['STATUS'],
        ];
    }
    return null;
}

/** Все живые сущности всех типов (для обхода целей); опционально — одной комнаты */
function all_entities($state, $room_filter = null) {
    $list = [];
    foreach (array_keys($state['players']) as $id) {
        $e = resolveEntity($state, 'player', $id, $room_filter);
        if ($e) $list[] = $e;
    }
    foreach (array_keys($state['npcs']) as $id) {
        $e = resolveEntity($state, 'npc', $id, $room_filter);
        if ($e) $list[] = $e;
    }
    foreach (array_keys($state['objects']) as $id) {
        $e = resolveEntity($state, 'object', $id, $room_filter);
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

/** Найти первую враждебную цель на клетке (в комнате кастера; или null) */
function find_target_at_position($state, $x, $y, $caster_id) {
    $caster = resolveEntity($state, 'player', $caster_id);
    if (!$caster) return null;
    foreach (all_entities($state, $caster['room']) as $e) {
        if ($e['x'] === $x && $e['y'] === $y && hostile_to($e, $caster_id, $caster['ally'])) {
            return $e;
        }
    }
    return null;
}

/**
 * Все враждебные цели на прямой от (x1,y1) до (x2,y2), луч останавливается
 * на непроходимой клетке (стена/объект) — как раньше в abilities.php.
 * ★ Комнатная изоляция: сканируются только сущности комнаты кастера —
 * выход через дверь не «видит» цели в соседней комнате.
 */
function get_targets_on_line($state, $x1, $y1, $x2, $y2, $caster_id) {
    $targets = [];
    $caster = resolveEntity($state, 'player', $caster_id);
    if (!$caster) return $targets;

    list($dx, $dy) = directionStep($x1, $y1, $x2, $y2);
    $cx = $x1 + $dx;
    $cy = $y1 + $dy;

    while (isValidPosition($cx, $cy)) {
        foreach (all_entities($state, $caster['room']) as $e) {
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

/** Все враждебные цели в манхэттен-радиусе от точки (комната кастера) */
function get_targets_in_area($state, $center_x, $center_y, $radius, $caster_id) {
    $targets = [];
    $caster = resolveEntity($state, 'player', $caster_id);
    if (!$caster) return $targets;

    foreach (all_entities($state, $caster['room']) as $e) {
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
 *
 * @param string|null $room_filter — если задан, учитываются только игроки этой
 *        комнаты (мультикартовый мир: соседи в других комнатах клетки не занимают).
 * @param bool $moving_cur_only — для выбора КЛЕТКИ ПОЯВЛЕНИЯ после перехода/
 *        респауна: движущийся боец чужой комнаты ещё может «долететь» до своей
 *        END-клетки, поэтому от появляющегося игрока его блокирует только та
 *        клетка, где он стоит СЕЙЧАС (CUR), а не обе. Иначе два независимых
 *        пути в разных комнатах ложно конфликтовали бы по совпадающим XY.
 */
function is_player_at_position($state, $x, $y, $exclude_id = null, $room_filter = null, $moving_cur_only = false) {
    foreach ($state['players'] as $pid => $player) {
        if ($pid === $exclude_id) continue;
        // Мертвый/распадающийся/удаленный игрок клетку НЕ занимает (иначе
        // сервер продолжал блокировать клетку после смерти игрока)
        if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) continue;
        if ($room_filter !== null && ($player['ROOM_ID'] ?? null) !== $room_filter) continue;

        $pos = parseXY($player['CUR_POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return true;

        if ($player['STATUS'] === 'MOVING' && !$moving_cur_only) {
            $end_pos = parseXY($player['END_POSITION_XY']);
            if ($end_pos['x'] === $x && $end_pos['y'] === $y) return true;
        }
    }
    return false;
}

/**
 * Проходимость клетки: стена (grid==0), неразрушенный объект или живой NPC
 * (например TURRET) блокируют.
 * (Игроков проверяет вызывающий — см. is_player_at_position.)
 *
 * ★ Мультикартовость: карта комнаты берётся по ROOM_ID ('' — legacy-режим
 * единой карты: используется state['room']). Стены соседних комнат не влияют.
 */
function is_cell_passable($state, $x, $y, $room_id = null) {
    if (!isValidPosition($x, $y)) return false;
    if ($room_id === null || $room_id === '') {
        $room = $state['room'] ?? null;
    } else {
        $room = $state['rooms'][$room_id] ?? room_get($state, $room_id);
    }
    if ($room === null) return false; // неизвестная комната — всё под стеной
    if (isset($room['grid'][$y][$x]) && (int)$room['grid'][$y][$x] === 0) {
        return false;
    }
    foreach ($state['objects'] as $obj) {
        if ($obj['STATUS'] === 'DESTROYED') continue;
        if ($room_id !== null && $room_id !== '' && ($obj['ROOM_ID'] ?? null) !== $room_id) continue;
        $pos = parseXY($obj['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return false;
    }
    // Живой NPC (TURRET и т.п.) — непроходимое препятствие, как игрок или объект;
    // уничтоженные/мертвые/удаленные NPC клетку не занимают.
    foreach ($state['npcs'] as $npc) {
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH', 'REMOVED'], true)) continue;
        if ($room_id !== null && $room_id !== '' && ($npc['ROOM_ID'] ?? null) !== $room_id) continue;
        $pos = parseXY($npc['POSITION_XY']);
        if ($pos['x'] === $x && $pos['y'] === $y) return false;
    }
    return true;
}
