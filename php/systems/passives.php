<?php
/**
 * systems/passives.php — Реестр пассивных способностей игроков.
 *
 * ДО РЕФАКТОРИНГА пассивки были «размазаны»: REGENERATION/ARMORED/SPLASH/THORNS
 * проверялись if-ками внутри battle.php/items.php напрямую. Теперь каждая
 * пассивка = запись в getPassiveDefinitions() + слушатель шины (или ответ на
 * query-событие). Новая пассивка = строка реестра + обработчик здесь же.
 *
 * Типы поведения (поле 'hook'/'query' в реестре):
 *   on_turn_start  → слушатель turn.start        (REGENERATION)
 *   damage_reduce  → слушатель damage.query_mitigate (ARMORED, by-ref!)
 *   on_damage_dealt→ слушатель damage.dealt      (THORNS)
 *   on_attack      → слушатель attack.resolved   (SPLASH; PUNCTURE читается
 *                    боевой системой напрямую через hasPassive — это query
 *                    «стиля атаки», не мутация мира)
 *   move_distance  → слушатель movement.query_distance (SWIFT; SLOWED живёт
 *                    в effects как modifier — оба редуктят одно событие)
 */

require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/geometry.php';
require_once __DIR__ . '/../engine/world.php';
require_once __DIR__ . '/targeting.php';
require_once __DIR__ . '/effects.php';

/**
 * Реестр пассивок. value — числовое значение эффекта.
 */
function getPassiveDefinitions() {
    return [
        'REGENERATION' => [
            'name' => 'REGENERATION', 'type' => 'on_turn_start',
            'description' => 'Восстанавливает 1 HP каждый ход', 'value' => 1,
        ],
        'ARMORED' => [
            'name' => 'ARMORED', 'type' => 'damage_reduce',
            'description' => 'Снижает получаемый урон на 1', 'value' => 1,
        ],
        'SPLASH' => [
            'name' => 'SPLASH', 'type' => 'on_attack',
            'description' => 'При атаке наносит урон всем соседним врагам (8 клеток)', 'value' => 1,
        ],
        'THORNS' => [
            'name' => 'THORNS', 'type' => 'on_damage_taken',
            'description' => 'При получении урона наносит 1 урон атакующему', 'value' => 1,
        ],
        'SWIFT' => [
            'name' => 'SWIFT', 'type' => 'move_distance',
            'description' => '+1 к дальности перемещения', 'value' => 1,
        ],
        'PUNCTURE' => [
            'name' => 'PUNCTURE', 'type' => 'attack_style',
            'description' => 'Атака пробивает всех врагов на пути до цели', 'value' => 0,
        ],
    ];
}

/** Проверка наличия пассивки у игрока (публичный API, сигнатура прежняя) */
function hasPassive($player, $passive_name) {
    return in_array($passive_name, $player['PASSIVES'] ?? []);
}

/** Значение пассивки из реестра (публичный API, сигнатура прежняя) */
function getPassiveValue($player, $passive_name) {
    if (!hasPassive($player, $passive_name)) return 0;
    $definitions = getPassiveDefinitions();
    return $definitions[$passive_name]['value'] ?? 0;
}

/**
 * Модификация входящего урона пассивками (старое имя сохранено как обёртка —
 * основной путь теперь слушатель passives_on_mitigate ниже).
 */
function applyPassiveDefense($player, $damage) {
    if (hasPassive($player, 'ARMORED')) {
        $reduction = getPassiveValue($player, 'ARMORED');
        $damage = max(1, $damage - $reduction);
    }
    return $damage;
}

/**
 * Слушатель damage.query_mitigate (by-ref!): ARMORED снижает урон на value,
 * но не ниже 1 (сохраняется прежнее поведение applyPassiveDefense: мин 1).
 */
function passives_on_mitigate(&$state, &$payload) {
    if ($payload['target_type'] !== 'player') return;
    $player = $state['players'][$payload['target_id']] ?? null;
    if (!$player || !hasPassive($player, 'ARMORED')) return;
    $reduction = getPassiveValue($player, 'ARMORED');
    $payload['damage'] = max(1, $payload['damage'] - $reduction);
}

/**
 * Слушатель damage.dealt: THORNS — жертва-игрок бьёт атакующего-игрока во
 * возврат (рекурсивно через единую цепочку combat_apply_damage).
 * Источник 'effect' (DoT) шипы не провоцирует — как раньше (thorns срабатывал
 * только в processAttack; поведение сохранено: реагируем лишь на player/npc).
 */
function passives_on_damage_dealt(&$state, $payload) {
    if ($payload['target_type'] !== 'player') return;
    if ($payload['source_type'] !== 'player') return;
    if ($payload['damage'] <= 0) return; // шипы отвечают только на урон по HP

    $victim = $state['players'][$payload['target_id']] ?? null;
    if (!$victim || !hasPassive($victim, 'THORNS')) return;

    $attacker_id = $payload['source_id'];
    if (!isset($state['players'][$attacker_id])) return;
    if ($state['players'][$attacker_id]['STATUS'] === 'DEATH') return;

    $thorns_damage = getPassiveValue($victim, 'THORNS');
    combat_apply_damage($state, 'player', $attacker_id, $thorns_damage,
                        ['type' => 'thorns', 'id' => $payload['target_id']]);
    $state = world_log($state, $payload['target_id'] . ' отразил ' . $thorns_damage . ' урона шипами на ' . $attacker_id);
}

/**
 * Слушатель movement.query_distance (by-ref): SWIFT добавляет +value к
 * дистанции хода. SLOWED уменьшает — его редуктит effects-адаптер ниже
 * (единое событие, порядок: SWIFT(+1) затем SLOWED(−2), floor min 1 в movement).
 */
function passives_on_query_distance(&$state, &$payload) {
    $player = $state['players'][$payload['player_id']] ?? null;
    if (!$player) return;
    if (hasPassive($player, 'SWIFT')) {
        $payload['distance'] += getPassiveValue($player, 'SWIFT');
    }
}

/** Адаптер: эффекты-модификаторы движения (SLOWED) редуктят тот же запрос */
function passives_effects_move_modifier(&$state, &$payload) {
    $player = $state['players'][$payload['player_id']] ?? null;
    if (!$player) return;
    $mod = effects_get_modifier($player, 'move_distance');
    if ($mod['add'] != 0) {
        $payload['distance'] = max(1, $payload['distance'] + $mod['add']);
    }
}

/**
 * Слушатель REGENERATION на turn.start: +value HP (кэп MAX_HITPOINTS).
 */
function passives_on_turn_start(&$state, $payload) {
    foreach ($state['players'] as $pid => &$player) {
        if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) continue;
        if (hasPassive($player, 'REGENERATION')) {
            $regen = getPassiveValue($player, 'REGENERATION');
            $player['HITPOINTS'] = min($player['HITPOINTS'] + $regen, $player['MAX_HITPOINTS']);
        }
    }
    unset($player);
}

/**
 * ★ SPLASH — слушатель attack.resolved. Полностью переписан: вместо цикла
 * «8 клеток × все игроки × DEBUG-лог на каждую проверку» (сотни записей на
 * удар) — один проход по живым враждебным сущностям в 8-клетной окрестности
 * КАЖДОЙ атакованной клетки, урон через единую цепочку combat_apply_damage,
 * ОДНА итоговая запись в журнал.
 * Прежние особенности сохранены:
 *   - движущаяся цель берётся по END_POSITION_XY (предсказуемо для клиента);
 *   - base damage = ATTACK атакующего, сопротивление цели учитывается;
 *   - щит/смерть/прерывание движения — внутри combat_apply_damage (раньше
 *     splash-версия сама писала щит/HP, но НЕ знала про DEATH-статус NPC —
 *     теперь единый код).
 */
function passives_on_attack_resolved(&$state, $payload) {
    $attacker_id = $payload['attacker_id'];
    $attacker = $state['players'][$attacker_id] ?? null;
    if (!$attacker || !hasPassive($attacker, 'SPLASH')) return;

    $base_damage = $attacker['ATTACK'];
    $caster_ally = $attacker['ALLY'] ?? 'NONE';

    $hit = [];
    foreach ($payload['hit_cells'] as $cell) {
        foreach (adjacentCells($cell[0], $cell[1]) as $pos) {
            $key = $pos[0] . ',' . $pos[1];
            $hit[$key] = $pos;
        }
    }

    $victims = [];
    foreach (all_entities($state) as $e) {
        if (!hostile_to($e, $attacker_id, $caster_ally)) continue;

        // Для движущегося игрока — конечная клетка (как делал старый splash)
        $x = $e['x']; $y = $e['y'];
        if ($e['type'] === 'player' && $e['status'] === 'MOVING') {
            $end = parseXY($state['players'][$e['id']]['END_POSITION_XY']);
            $x = $end['x']; $y = $end['y'];
        }

        $key = $x . ',' . $y;
        if (!isset($hit[$key])) continue;

        $splash_damage = calculateDamage($base_damage, $base_damage, $e['resistance']);
        $res = combat_apply_damage($state, $e['type'], $e['id'], $splash_damage,
                                   ['type' => 'splash', 'id' => $attacker_id]);
        if ($res['dealt'] > 0 || $res['shield_absorbed'] > 0) {
            $victims[] = $e['id'] . '(' . ($res['shield_absorbed'] > 0 ? 'щит ' . $res['shield_absorbed'] . ', ' : '') . 'hp -' . $res['dealt'] . ')';
        }
    }

    // ОДНА читаемая запись вместо десятков DEBUG-строк
    if (!empty($victims)) {
        $state = world_log($state, 'SPLASH: ' . $attacker_id . ' задел ' . implode(', ', $victims));
    }
}

/** Регистрация слушателей (bootstrap вызывает ровно один раз) */
function passives_register_hooks() {
    hook('damage.query_mitigate', 'passives_on_mitigate');
    hook('damage.dealt', 'passives_on_damage_dealt', 10); // после interrupt-move
    hook('movement.query_distance', 'passives_on_query_distance');
    hook('movement.query_distance', 'passives_effects_move_modifier', 10);
    hook('turn.start', 'passives_on_turn_start');
    hook('attack.resolved', 'passives_on_attack_resolved');
}
