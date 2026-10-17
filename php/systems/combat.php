<?php
/**
 * systems/combat.php — Единая цепочка урона и базовая атака.
 *
 * ДО РЕФАКТОРИНГА «щит→HP→DEATH» был написан 4 раза (attack в battle.php,
 * applyDamageToTarget в abilities.php, processTraps в objects.php, splash в
 * items.php), причём клоки разошлись: trap-версия не прерывала MOVING. Теперь
 * ЕДИНСТВЕННАЯ точка — combat_apply_damage(); все источники урона (атака,
 * способность, ловушка, NPC, DoT, thorns) вызывают её.
 *
 * Поток одного урона:
 *   1) emitReduce('damage.query_mitigate')  → passives(ARMORED) снижает damage
 *   2) щит поглощает остаток
 *   3) HP; при обнулении → STATUS='DEATH' + emit('entity.died')
 *   4) emit('damage.dealt')                 → listeners:
 *        - combat_self: прерывание MOVING жертвы (movement.interrupted)
 *        - passives:    THORNS (ответный урон атакующему — рекурсивно сюда же)
 */

require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/geometry.php';
require_once __DIR__ . '/../engine/rules.php';
require_once __DIR__ . '/../engine/world.php';
require_once __DIR__ . '/targeting.php';
require_once __DIR__ . '/effects.php';
require_once __DIR__ . '/movement.php';

/**
 * Формула урона: base (мин 1), сопротивление −10%/очко (кэп 90%), ceil, мин 1.
 * КЛИЕНТ повторять не обязан — расчёт только серверный.
 */
function calculateDamage($base_damage, $attack_stat, $resistance) {
    $damage = max($base_damage, 1);
    $resist_percent = min($resistance * RESISTANCE_PERCENT_PER_POINT, RESISTANCE_CAP_PERCENT);
    $reduced = $damage * (1 - $resist_percent / 100);
    return max(ceil($reduced), 1);
}

/**
 * ★ Единая функция применения урона любой сущности.
 * @param array  $state
 * @param string $target_type 'player'|'npc'|'object'
 * @param string $target_id
 * @param int    $damage          уже рассчитанный (после calculateDamage)
 * @param array  $source          ['type'=>'player|npc|trap|effect|turret','id'=>...]
 * @return array ['dealt'=>int,'shield_absorbed'=>int,'killed'=>bool]
 */
function combat_apply_damage(&$state, $target_type, $target_id, $damage, $source) {
    // 1) Запрос на снижение урона (ARMORED и будущие модификаторы брони)
    $query = emitReduce($state, 'damage.query_mitigate', [
        'target_type' => $target_type,
        'target_id' => $target_id,
        'damage' => $damage,
    ]);
    $damage = max(0, $query['damage']);
    if ($damage === 0) {
        return ['dealt' => 0, 'shield_absorbed' => 0, 'killed' => false];
    }

    $shield_absorbed = 0;
    $killed = false;

    if ($target_type === 'player') {
        $player = &$state['players'][$target_id];

        // Мёртвый/разлагающийся/удалённый игрок не получает урон и не может
        // «воскреснуть» с 0 HP (баг рефакторинга: DEATH-игрок мог принимать
        // действия; здесь — защита на уровне единой цепочки урона).
        if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) {
            unset($player);
            return ['dealt' => 0, 'shield_absorbed' => 0, 'killed' => false];
        }

        // 2) Щит
        if ($player['SHIELD'] > 0) {
            $shield_absorbed = min($player['SHIELD'], $damage);
            $player['SHIELD'] -= $shield_absorbed;
            $damage -= $shield_absorbed;
        }

        // 3) HP → смерть
        if ($damage > 0) {
            $player['HITPOINTS'] -= $damage;
            if ($player['HITPOINTS'] <= 0) {
                $player['HITPOINTS'] = 0;
                $player['STATUS'] = 'DEATH';
                $player['ACTIONS'] = 0; // мёртвый не может действовать до респауна
                $killed = true;
            }
        }
        unset($player);
    } elseif ($target_type === 'npc') {
        $npc = &$state['npcs'][$target_id];
        $npc['HITPOINTS'] -= $damage;
        if ($npc['HITPOINTS'] <= 0) {
            $npc['HITPOINTS'] = 0;
            $npc['STATUS'] = 'DESTROYED';
            $killed = true;
        }
        unset($npc);
    } elseif ($target_type === 'object') {
        $obj = &$state['objects'][$target_id];
        $obj['HITPOINTS'] -= $damage;
        if ($obj['HITPOINTS'] <= 0) {
            $obj['STATUS'] = 'DESTROYED';
            $killed = true;
        }
        unset($obj);
    }

    // 4) События: dealt (thorns/interrupt), died (death chain/логи)
    emit($state, 'damage.dealt', [
        'source_type' => $source['type'] ?? 'unknown',
        'source_id' => $source['id'] ?? null,
        'target_type' => $target_type,
        'target_id' => $target_id,
        'damage' => $damage,
        'shield_absorbed' => $shield_absorbed,
    ]);
    if ($killed) {
        emit($state, 'entity.died', [
            'type' => $target_type,
            'id' => $target_id,
            'killer_type' => $source['type'] ?? 'unknown',
            'killer_id' => $source['id'] ?? null,
        ]);
    }

    return ['dealt' => $damage, 'shield_absorbed' => $shield_absorbed, 'killed' => $killed];
}

/**
 * Слушатель 'damage.dealt': у игрока-жертвы прерываем движение на текущей
 * (округлённой по серверным часам) клетке. Раньше это было встроено в оба
 * клона applyDamageToTarget; теперь — один listener.
 */
function combat_on_damage_dealt(&$state, $payload) {
    if ($payload['target_type'] !== 'player') return;
    if ($payload['damage'] <= 0 && $payload['shield_absorbed'] <= 0) return;
    $player = $state['players'][$payload['target_id']] ?? null;
    if (!$player) return;
    if ($player['STATUS'] === 'MOVING') {
        movement_interrupt($state, $payload['target_id']);
    }
}

/**
 * ★ Единая точка валидации глагола перед любым действием (move/attack/ability).
 * Возвращает ['success'=>bool,'message'=>string]; при success=false эндпоинт
 * обязан отклонить действие (jsonError), состояние НЕ мутируется.
 *
 * Покрывает все баги «мёртвый действует» / «оглушённый действует»:
 *   - DEATH/DECAY/REMOVED — игрок вне игры до конца цепочки респауна;
 *   - MOVING — анимация ещё не доехала (две фазы движения, см. ARCH.md §5);
 *   - STATUS='STUNNED' ИЛИ активный cc-эффект из NEGATIVES (двойная проверка:
 *     статус может отстать от эффектов на один тик);
 *   - ACTIONS <= 0 — очки действий кончились до начала хода.
 */
function action_validate($state, $player_id, $verb) {
    if (!isset($state['players'][$player_id])) {
        return ['success' => false, 'message' => 'Игрок не найден: ' . $player_id];
    }
    $player = $state['players'][$player_id];

    if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) {
        return ['success' => false, 'message' => 'Игрок мёртв'];
    }
    if ($player['STATUS'] === 'MOVING') {
        return ['success' => false, 'message' => 'Нельзя действовать во время движения'];
    }
    if ($player['STATUS'] === 'STUNNED') {
        return ['success' => false, 'message' => 'Игрок оглушен'];
    }
    $block = effects_blocks($player, $verb);
    if ($block['blocked']) {
        return ['success' => false, 'message' => 'Действие заблокировано эффектом: ' . $block['reason']];
    }
    $block_all = effects_blocks($player, 'actions');
    if ($block_all['blocked']) {
        return ['success' => false, 'message' => 'Действие заблокировано эффектом: ' . $block_all['reason']];
    }
    if ((int)$player['ACTIONS'] <= 0) {
        return ['success' => false, 'message' => 'Нет очков действий'];
    }
    return ['success' => true, 'message' => ''];
}

/**
 * Базовая линейная атака игрока (processAttack из battle.php).
 * Валидация хода → сканирование линии (PUNCTURE пробивает или стоп на первом)
 * → урон каждой цели через combat_apply_damage → attack.resolved (SPLASH)
 * → ловушки на атакованных клетках → трата ACTION, статус ATTACK, TURN.
 */
function combat_process_attack(&$state, $attacker_id, $target_x, $target_y) {
    // Единая валидация глагола (dead/stunned/moving/no-actions) — см. выше
    $v = action_validate($state, $attacker_id, 'attack');
    if (!$v['success']) {
        return ['success' => false, 'message' => $v['message']];
    }

    $attacker = $state['players'][$attacker_id];

    $attacker_pos = parseXY($attacker['CUR_POSITION_XY']);
    $distance = manhattan($attacker_pos['x'], $attacker_pos['y'], $target_x, $target_y);

    // Модификатор дальности (BLINDED halve) — через effects, без special-case здесь
    $range_mod = effects_get_modifier($attacker, 'attack_range');
    $effective_range = max(1, (int)floor($attacker['RANGE'] * $range_mod['factor']) + $range_mod['add']);

    if ($distance > $effective_range) {
        return ['success' => false, 'message' => 'Цель вне зоны атаки'];
    }
    if ($distance === 0) {
        return ['success' => false, 'message' => 'Нельзя атаковать свою клетку'];
    }

    $has_puncture = hasPassive($attacker, 'PUNCTURE');

    list($dx, $dy) = directionStep($attacker_pos['x'], $attacker_pos['y'], $target_x, $target_y);
    $cx = $attacker_pos['x'] + $dx;
    $cy = $attacker_pos['y'] + $dy;

    $targets_hit = [];
    $stopped = false;

    while (($cx !== $target_x || $cy !== $target_y) && isValidPosition($cx, $cy) && !$stopped) {
        $target = find_target_at_position($state, $cx, $cy, $attacker_id);
        if ($target) {
            $targets_hit[] = ['target' => $target, 'x' => $cx, 'y' => $cy];
            if (!$has_puncture) $stopped = true;
        }
        $cx += $dx;
        $cy += $dy;
    }

    if (!$stopped) {
        $target = find_target_at_position($state, $target_x, $target_y, $attacker_id);
        if ($target) {
            $targets_hit[] = ['target' => $target, 'x' => $target_x, 'y' => $target_y];
        }
    }

    $attack_message = '';
    $hit_cells = [];
    $last_damage = 0;
    $last_target = null;

    foreach ($targets_hit as $hit) {
        $target = $hit['target'];
        $hit_cells[] = [$hit['x'], $hit['y']];

        $damage = calculateDamage($attacker['ATTACK'], $attacker['ATTACK'], $target['resistance']);

        $res = combat_apply_damage($state, $target['type'], $target['id'], $damage,
                                   ['type' => 'player', 'id' => $attacker_id]);

        $msg = $attacker_id . ' атакует ' . $target['id'] . ' на ';
        if ($target['type'] === 'player' && $res['shield_absorbed'] > 0 && $res['dealt'] === 0) {
            $msg .= 'щит поглотил ' . $res['shield_absorbed'];
        } elseif ($target['type'] === 'player' && $res['shield_absorbed'] > 0) {
            $msg .= $res['dealt'] . ' урона (щит поглотил ' . $res['shield_absorbed'] . ')';
        } else {
            $msg .= $res['dealt'] . ' урона';
        }
        $attack_message .= ($attack_message ? '; ' : '') . $msg;

        $last_damage = $res['dealt'];
        $last_target = $target['id'];
    }

    if (empty($targets_hit)) {
        $attack_message = $attacker_id . ' атакует пустую клетку (' . $target_x . ',' . $target_y . ')';
        $hit_cells[] = [$target_x, $target_y];
    }

    // SPLASH-пассивка — слушатель attack.resolved (systems/passives.php)
    emit($state, 'attack.resolved', [
        'attacker_id' => $attacker_id,
        'x' => $target_x,
        'y' => $target_y,
        'hit_cells' => $hit_cells,
    ]);

    // Ловушки на всех атакованных клетках (активируются попаданием атаки)
    foreach ($hit_cells as $cell) {
        $trap_result = processTraps($state, $attacker_id, $cell[0], $cell[1]);
        if ($trap_result['triggered']) {
            $state = world_log($state, "ATTACK: {$attacker_id} активировал ловушку {$trap_result['trap']}");
        }
    }

    // Трата действия, статус анимации, направление спрайта
    $state['players'][$attacker_id]['ACTIONS']--;
    $state['players'][$attacker_id]['STATUS'] = 'ATTACK';
    $state['players'][$attacker_id]['ATTACK_TIME'] = time();
    $new_turn = rules_turn_formula($attacker_pos['x'], $target_x, $attacker['TURN']);
    if ($new_turn !== null) $state['players'][$attacker_id]['TURN'] = $new_turn;

    return [
        'success' => true,
        'message' => $attack_message,
        'damage' => $last_target ? $last_damage : 0,
        'target' => $last_target,
    ];
}

/** Регистрация слушателей */
function combat_register_hooks() {
    hook('damage.dealt', 'combat_on_damage_dealt');
}
