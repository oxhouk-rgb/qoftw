<?php
/**
 * systems/movement.php — Движение игроков: валидация, очередь, серверные часы.
 *
 * ДВЕ ФАЗЫ (инвариант из ARCH.md §5, сохранён полностью):
 *   1) processMove() — МОМЕНТ ДЕЙСТВИЯ: путь только ПРЕДСКАЗЫВАЕТСЯ на черновом
 *      копийстве состояния (makeTrapPreviewState). Реальный урон/эффекты НЕ
 *      применяются — иначе клиент получил бы stun/death раньше, чем анимация
 *      довела спрайт до клетки мины.
 *   2) movement_update() — КАЖДЫЙ ТИК по серверным часам (MOVE_START/END_TIME):
 *      плавное обновление CUR_POSITION_XY, реальные ловушки/зоны угрозы при
 *      входе на клетку, финал движения + восстановление STUNNED-статуса.
 *
 * Блокировки и дальность больше НЕ хардкодят имена эффектов:
 *   - блокировка движения → emitReduce('movement.query_block') (effects отвечает)
 *   - дальность хода      → emitReduce('movement.query_distance') (passives SWIFT,
 *                           effects SLOWED отвечают редукцией)
 */

require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/geometry.php';
require_once __DIR__ . '/../engine/rules.php';
require_once __DIR__ . '/../engine/world.php';
require_once __DIR__ . '/targeting.php';
require_once __DIR__ . '/effects.php';
require_once __DIR__ . '/rooms.php'; // room_get() — карты комнат для is_cell_passable

/**
 * ★ Карта, в которой происходит действие: state['room'] (единая карта legacy)
 * либо rooms[$room_id] (мультикарта). Все «стеновые» проверки движка обязаны
 * брать сетку отсюда, иначе выход через дверь ломался бы о чужие стены.
 */
function engine_active_room($state, $room_id = null) {
    if ($room_id === null || $room_id === '') return $state['room'] ?? null;
    if (isset($state['rooms'][$room_id])) return $state['rooms'][$room_id];
    return room_get($state, $room_id);
}

/**
 * Начать перемещение игрока (валидация + предсказание пути + очередь анимации).
 * @return array ['success'=>bool,'message'=>string,...]
 */
function movement_process_move(&$state, $player_id, $target_x, $target_y) {
    // Единая валидация глагола (dead/stunned/moving/no-actions) — combat.action_validate
    $v = action_validate($state, $player_id, 'move');
    if (!$v['success']) {
        return ['success' => false, 'message' => $v['message']];
    }

    $player = $state['players'][$player_id];

    $cur_pos = parseXY($player['CUR_POSITION_XY']);
    // Комната игрока: все проверки пути/целей идут по её карте и сущностям
    $room_id = $player['ROOM_ID'] ?? '';
    $room = engine_active_room($state, $room_id);
    if ($room === null) {
        return ['success' => false, 'message' => 'Комната не найдена: ' . $room_id];
    }

    // Дальность хода — базовая константа + редукция слушателями шины
    $dist_query = emitReduce($state, 'movement.query_distance', [
        'player_id' => $player_id,
        'distance' => MAX_MOVE_DISTANCE,
    ]);
    $max_move = max(1, $dist_query['distance']);

    $distance = manhattan($cur_pos['x'], $cur_pos['y'], $target_x, $target_y);
    if ($distance > $max_move) {
        return ['success' => false, 'message' => 'Слишком далеко (макс ' . $max_move . ')'];
    }

    // Только по прямой (только X или только Y меняется)
    if ($cur_pos['x'] !== $target_x && $cur_pos['y'] !== $target_y) {
        return ['success' => false, 'message' => 'Перемещение только по прямой'];
    }

    // Путь: препятствия и занятость клеток (в СВОЕЙ комнате)
    $path = calculatePath($cur_pos['x'], $cur_pos['y'], $target_x, $target_y);
    foreach ($path as $step) {
        if (!is_cell_passable($state, $step['x'], $step['y'], $room_id)) {
            return ['success' => false, 'message' => 'Путь заблокирован'];
        }
        if (is_player_at_position($state, $step['x'], $step['y'], $player_id, $room_id)) {
            return ['success' => false, 'message' => 'Клетка занята другим игроком'];
        }
    }
    if (is_player_at_position($state, $target_x, $target_y, $player_id, $room_id)) {
        return ['success' => false, 'message' => 'Конечная клетка занята'];
    }

    // ─── ФАЗА 1: предсказание ловушек/зон угрозы на ЧЕРНОВОМ копийстве ───
    // Реальный урон применяется в movement_update() при фактическом входе на
    // клетку (серверные часы синхронны с клиентской анимацией).
    $path = calculatePath($cur_pos['x'], $cur_pos['y'], $target_x, $target_y);
    $trap_found = false;
    $trap_distance = 0;
    $stopped_by_npc = false;

    $preview_state = makeTrapPreviewState($state);
    // Предсказание НЕ должно тратить ACTIONS турели и выставлять ей STATUS ATTACK —
    // иначе к моменту фазы 2 (реальный проход клетки) npc['ACTIONS'] === 0 и
    // objects_check_threat_zone пропускает атаку: «турель не стреляет» (баг рефакторинга).
    $preview_npc_attacks = [];

    foreach ($path as $index => $cell) {
        $trap_result = processTraps($preview_state, $player_id, $cell['x'], $cell['y'], ['dry_run' => true]);
        if ($trap_result['triggered']) {
            $trap_found = true;
            $trap_distance = $index + 1;
            $state = world_log($state, $player_id . ' наступил на ловушку ' . $trap_result['trap'] . ' на клетке (' . $cell['x'] . ',' . $cell['y'] . ')');
            $target_x = $cell['x'];
            $target_y = $cell['y'];
            $distance = $trap_distance;
            break;
        }

        // Предсказание зон угрозы NPC: смертельный урон останавливает движение здесь
        $death_preview = $preview_state;
        $death_preview['players'][$player_id]['CUR_POSITION_XY'] = formatXY($cell['x'], $cell['y']);
        $attacks = objects_check_threat_zone($death_preview, $player_id, $cell['x'], $cell['y'], ['dry_run' => true]);
        if ($attacks) $preview_npc_attacks[] = $attacks[0];
        if ($death_preview['players'][$player_id]['STATUS'] === 'DEATH') {
            $stopped_by_npc = true;
            $trap_distance = $index + 1;
            $target_x = $cell['x'];
            $target_y = $cell['y'];
            $distance = $trap_distance;
            $state = world_log($state, $player_id . ' будет остановлен NPC на клетке (' . $cell['x'] . ',' . $cell['y'] . ')');
            break;
        }
    }

    // ─── Очередь плавного движения ───
    $state['players'][$player_id]['END_POSITION_XY'] = formatXY($target_x, $target_y);
    // STATUS='MOVING' ВСЕГДА (даже если ловушка предсказана): анимация должна
    // довести спрайт до клетки мины; DEATH/STUNNED применятся в update.
    $state['players'][$player_id]['STATUS'] = 'MOVING';
    $state['players'][$player_id]['ACTIONS']--;

    $new_turn = rules_turn_formula($cur_pos['x'], $target_x, $player['TURN']);
    if ($new_turn !== null) $state['players'][$player_id]['TURN'] = $new_turn;

    $move_time = $distance / MOVE_SPEED;
    $state['players'][$player_id]['MOVE_START_TIME'] = microtime(true);
    $state['players'][$player_id]['MOVE_END_TIME'] = microtime(true) + $move_time;
    $state['players'][$player_id]['MOVE_DISTANCE'] = $distance;

    $message = $player_id . ' начинает перемещение на (' . $target_x . ',' . $target_y . ')';
    if ($trap_found) $message .= ' (остановлен ловушкой)';
    elseif ($stopped_by_npc) $message .= ' (остановлен NPC)';

    return [
        'success' => true,
        'message' => $message,
        'distance' => $distance,
        'move_time' => $move_time,
    ];
}

/**
 * Прервать движение игрока на текущей (промежуточной, округлённой по серверным
 * часам) клетке. Вызывается слушателем damage.dealt и сам по себе идемпотентен.
 */
function movement_interrupt(&$state, $player_id) {
    $player = &$state['players'][$player_id];
    if ($player['STATUS'] !== 'MOVING') { unset($player); return; }

    if (isset($player['MOVE_END_TIME'], $player['MOVE_START_TIME'])) {
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
        }
    }
    $player['START_POSITION_XY'] = $player['CUR_POSITION_XY'];
    $player['END_POSITION_XY'] = $player['CUR_POSITION_XY'];
    $player['STATUS'] = 'IDLE';
    unset($player['MOVE_END_TIME'], $player['MOVE_START_TIME'], $player['MOVE_DISTANCE']);
    unset($player);

    emit($state, 'movement.interrupted', ['player_id' => $player_id]);
}

/**
 * Черновое копийство состояния для предсказания ловушек/NPC в phase 1.
 * Глубоко копируем только то, что мутируют processTraps/checkThreatZone:
 * traps/npcs целиком и NEGATIVES игроков (реальные игроки не затрагиваются).
 */
function makeTrapPreviewState($state) {
    $preview = $state;
    $preview['traps'] = unserialize(serialize($state['traps']));
    $preview['players'] = [];
    foreach ($state['players'] as $pid => $p) {
        $pc = $p;
        $pc['NEGATIVES'] = $p['NEGATIVES'];
        $preview['players'][$pid] = $pc;
    }
    $preview['npcs'] = unserialize(serialize($state['npcs']));
    return $preview;
}

/**
 * ★ ФАЗА 2: тик движения (вызывается каждый server tick из engine/turn.php).
 * Плавное обновление позиций + РЕАЛЬНОЕ применение ловушек/зон угрозы при входе
 * на новую клетку + финал движения с восстановлением CC-статусов.
 */
function movement_update(&$state) {
    // ── Виртуальные часы движения (catch-up tick) ──────────────────────────
    // Движение анимируется по серверным часам, но тик poll-цикла дискретен.
    // Если реальный now обогнал MOVE_END_TIME, спрайт «перескочил» бы путь —
    // ловушки и зоны угрозы на промежуточных клетках не сработали бы (баги
    // «STUNNED от мины не приходит», «турель не стреляет в ответ»). Поэтому
    // догоняем мир шагами по 0.1с от последних сохранённых часов мира.
    $real_now = microtime(true);
    $tick_floor = $state['WORLD_MOVE_NOW'] ?? ($real_now - 1);
    foreach ($state['players'] as $p) { // не заводим часы назад из-за свежего хода
        if (isset($p['MOVE_START_TIME'])) $tick_floor = max($tick_floor, $p['MOVE_START_TIME']);
    }
    $tick_floor = max($tick_floor, $real_now - 15); // hard cap catch-up: 15 секунд пути
    $now = min($real_now, $tick_floor + 0.1);
    $advanced = false;
    while ($now < $real_now - 0.005) {
        movement_update_step($state, $now);
        $now += 0.1;
        $advanced = true;
    }
    movement_update_step($state, $real_now);
    $state['WORLD_MOVE_NOW'] = $real_now;
    return $advanced;
}

/** Один шаг тика движения при виртуальном времени $now */
function movement_update_step(&$state, $now) {
    foreach ($state['players'] as $pid => &$player) {
        if ($player['STATUS'] !== 'MOVING') continue;
        if (!isset($player['MOVE_END_TIME'])) continue;

        if ($now >= $player['MOVE_END_TIME']) {
            // ── Финал движения ──
            $player['CUR_POSITION_XY'] = $player['END_POSITION_XY'];
            $player['START_POSITION_XY'] = $player['END_POSITION_XY'];
            // IDLE только если не оглушён/убит в пути (иначе сбросили бы статус раньше времени)
            if ($player['STATUS'] !== 'STUNNED' && $player['STATUS'] !== 'DEATH') {
                $player['STATUS'] = 'IDLE';
            }
            unset($player['MOVE_END_TIME'], $player['MOVE_START_TIME'], $player['MOVE_DISTANCE']);

            $new_pos = parseXY($player['CUR_POSITION_XY']);
            processTraps($state, $pid, $new_pos['x'], $new_pos['y']);
            objects_check_threat_zone($state, $pid, $new_pos['x'], $new_pos['y']);

            // Восстановить STUNNED, если эффект ещё висит (ловушка сработала
            // на промежуточной клетке). DEATH/DECAY имеют приоритет.
            if ($player['STATUS'] !== 'DEATH' && $player['STATUS'] !== 'DECAY') {
                $stun = effects_blocks($player, 'actions');
                if ($stun['blocked'] && strpos($stun['reason'], 'STUNNED[') === 0) {
                    $player['STATUS'] = 'STUNNED';
                }
            }

            // Выход из комнаты (заглушка — переходы между комнатами не реализованы)
            $room_key = formatXY($new_pos['x'], $new_pos['y']);
            if (isset($state['room']['exits'][$room_key])) {
                $state = world_log($state, $pid . ' переходит в комнату ' . $state['room']['exits'][$room_key]);
            }
        } else {
            // ── Плавное перемещение по серверным часам ──
            $start = parseXY($player['START_POSITION_XY']);
            $end = parseXY($player['END_POSITION_XY']);
            $total_time = $player['MOVE_END_TIME'] - $player['MOVE_START_TIME'];
            $elapsed = $now - $player['MOVE_START_TIME'];

            if ($total_time > 0) {
                $progress = min(1, $elapsed / $total_time);
                $cur_x = $start['x'] + ($end['x'] - $start['x']) * $progress;
                $cur_y = $start['y'] + ($end['y'] - $start['y']) * $progress;

                $old_pos = parseXY($player['CUR_POSITION_XY']);
                $new_x = round($cur_x);
                $new_y = round($cur_y);
                $player['CUR_POSITION_XY'] = formatXY($new_x, $new_y);

                // Реальные ловушки/зоны угрозы — ровно при смене клетки
                if ($old_pos['x'] !== $new_x || $old_pos['y'] !== $new_y) {
                    $trap_result = processTraps($state, $pid, $new_x, $new_y);
                    if ($trap_result['triggered']) {
                        $state = world_log($state, $pid . ' активировал ловушку ' . $trap_result['trap'] . ' во время движения');

                        // Остановка ровно на клетке мины — финал сразу
                        $player['CUR_POSITION_XY'] = formatXY($new_x, $new_y);
                        $player['START_POSITION_XY'] = formatXY($new_x, $new_y);
                        $player['END_POSITION_XY'] = formatXY($new_x, $new_y);
                        unset($player['MOVE_END_TIME'], $player['MOVE_START_TIME'], $player['MOVE_DISTANCE']);
                        $final_turn = rules_turn_formula($old_pos['x'], $new_x, $player['TURN']);
                        if ($final_turn !== null) $player['TURN'] = $final_turn;

                        // Немедленная активация stun от ловушки (не ждём хода):
                        // combat/effects уже применили эффекты внутри processTraps;
                        // здесь лишь отражаем их на статус прерванного движения.
                        if ($player['STATUS'] !== 'DEATH') {
                            $stun = effects_blocks($player, 'actions');
                            if ($stun['blocked'] && strpos($stun['reason'], 'STUNNED[') === 0) {
                                $player['STATUS'] = 'STUNNED';
                                $player['ACTIONS'] = 0;
                            }
                        }
                        continue;
                    }

                    objects_check_threat_zone($state, $pid, $new_x, $new_y);

                    // Смертельный урон NPC прерывает движение на этой клетке
                    if ($player['STATUS'] === 'DEATH') {
                        $player['CUR_POSITION_XY'] = formatXY($new_x, $new_y);
                        $player['START_POSITION_XY'] = formatXY($new_x, $new_y);
                        $player['END_POSITION_XY'] = formatXY($new_x, $new_y);
                        unset($player['MOVE_END_TIME'], $player['MOVE_START_TIME'], $player['MOVE_DISTANCE']);
                        $final_turn = rules_turn_formula($old_pos['x'], $new_x, $player['TURN']);
                        if ($final_turn !== null) $player['TURN'] = $final_turn;
                        $state = world_log($state, $pid . ' убит NPC на клетке (' . $new_x . ',' . $new_y . ')');
                        continue;
                    }
                }
            }
        }
    }
    unset($player);
}

/** Слушатель movement.query_block — ответ системы эффектов */
function movement_on_query_block(&$state, &$payload) {
    $player = $state['players'][$payload['player_id']] ?? null;
    if (!$player) return;
    $res = effects_blocks($player, 'move');
    if ($res['blocked']) {
        $payload['blocked'] = true;
        $payload['reason'] = $res['reason'];
    }
}

/**
 * ★ ФАЗА 2-инициализация: зафиксировать старт движения в состоянии.
 * Вызывается из action.php ПОСЛЕ world_load() непосредственно перед сохранением.
 *
 * Причина (баг «движение работает только пока игрок сам дёргает сервер»):
 * MOVE_START/MOVE_END_TIME — это microtime(true), а poll-цикл тикает раз в
 * ~100 мс. Если клиент следующий poll сделает позже MOVE_END_TIME, update
 * увидит лишь финальную точку и «пролетит» все промежуточные клетки пути —
 * именно на них должны срабатывать ловушки и зоны угрозы NPC (в т.ч. STUNNED
 * от мины и ответный огонь турели). Поэтому фиксируем виртуальные часы мира
 * (WORLD_MOVE_NOW) на момент действия и при догоняющем тике проходим путь
 * клетка-за-клеткой, а не телепортируем спрайт в конец.
 */
function movement_finalize_start(&$state, $player_id) {
    $player = &$state['players'][$player_id];
    if ($player['STATUS'] !== 'MOVING') { unset($player); return; }
    $state['WORLD_MOVE_NOW'] = microtime(true);
    unset($player);
}

/** Регистрация слушателей */
function movement_register_hooks() {
    hook('movement.query_block', 'movement_on_query_block');
}
