<?php
/**
 * engine/turn.php — Оркестратор серверного тика и хода.
 *
 * Тик (каждый poll-цикл): movement_update → death chain → projectiles →
 * attack status reset → npc animation update.
 * Ход (раз в TURN_DURATION): emit('turn.start') — слушатели:
 *   passives(REGENERATION) → effects(DOT/CC tick) → combat(ACTIONS/shield regen,
 *   снятие STUNNED) → objects(NPC actions + threat check).
 * Порядок внутри события детерминирован приоритетами шины.
 */

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/../systems/movement.php';
require_once __DIR__ . '/../systems/combat.php';
require_once __DIR__ . '/../systems/effects.php';
require_once __DIR__ . '/../systems/passives.php';
require_once __DIR__ . '/../systems/objects.php';

/**
 * Начало хода: восстановление ACTIONS/щита + эмит turn.start.
 * (бывший items.processTurnStart; регенерация щита переехала сюда как часть
 * хода мира, пассивки/эффекты/NPC реагируют через шину)
 */
function turn_start(&$state) {
    foreach ($state['players'] as $pid => &$player) {
        if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) continue;

        // Восстановление очков действий.
        // ★ CC-носители (STUNNED и т.п.) получают actions в 0 — их обнулит
        // effects_tick ниже; всем остальным полный пул.
        $player['ACTIONS'] = $player['MAX_ACTIONS'];

        // Регенерация щита (SHIELD_REGEN_PER_TURN за ход — см. engine/rules.php).
        // Мёртвые/разлагающиеся пропускаются выше; движущиеся НЕ пропускаются:
        // реген щита на них не влияет, а вот проверка зон угрозы в turn.start
        // обязана их увидеть (см. objects_on_turn_start).
        if ($player['SHIELD'] < $player['MAX_SHIELD']) {
            $player['SHIELD'] = min($player['SHIELD'] + SHIELD_REGEN_PER_TURN, $player['MAX_SHIELD']);
        }

        // Снятие залипшего статуса STUNNED (если эффект ещё активен,
        // effects_tick ниже его снова выставит)
        if ($player['STATUS'] === 'STUNNED') {
            $player['STATUS'] = 'IDLE';
        }
    }
    unset($player);

    emit($state, 'turn.start', []);
}

/**
 * Слушатель turn.start (priority 5): прогон эффектов всех живых игроков.
 * Зарегистрирован здесь, а не в effects.php — чтобы не тянуть обратную
 * зависимость effects→combat (effects_tick вызывает combat_apply_damage).
 *
 * ★ MOVING тоже получает тик: эффект может истечь/нанести DoT ровно тогда,
 * когда спрайт едет; movement_update_step ниже по часам корректно восстановит
 * STUNNED из ещё активных NEGATIVES на финале движения (историческое
 * поведение updateMovements сохранено).
 */
function turn_effects_tick(&$state, $payload) {
    foreach ($state['players'] as $pid => $player) {
        if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) continue;
        effects_tick($state, $pid);
    }
}

/**
 * ★ Инвариант «статус = NEGATIVES» (слушатель effect.applied, priority 10 —
 * после немедленной активации cc из effects.php).
 *
 * Причина багов после рефакторинга: STATUS='STUNNED' раньше снимался только
 * на границе хода и при финале движения; если игрок получал оглушение, стоя
 * в IDLE, а эффект истёк посреди хода (или игрок умер с висевшим статусом),
 * STATUS залипал навсегда → клиент бесконечно получал «Игрок оглушен» /
 * ошибки действий. Теперь при ЛЮБОМ изменении набора эффектов статус
 * пересобирается из фактического состояния: DEATH/DECAY/MOVING не трогаем
 * (анимационные состояния приоритетнее), иначе — STUNNED если cc-эффект ещё
 * активен, иначе IDLE.
 */
function turn_sync_status_after_effect(&$state, $payload) {
    if ($payload['target_type'] !== 'player') return;
    $player = &$state['players'][$payload['target_id']] ?? null;
    if (!$player) { unset($player); return; }
    if (in_array($player['STATUS'], ['MOVING', 'DEATH', 'DECAY', 'ATTACK', 'ABILITY', 'SPECIAL'], true)) {
        unset($player);
        return; // движение/смерть/анимация удара — приоритетнее статусов
    }
    $cc = effects_blocks($player, 'actions');
    if ($cc['blocked']) {
        $defs = getEffectDefinitions();
        $p = effects_parse($cc['reason']);
        $sets = ($p && isset($defs[$p['name']]['sets_status'])) ? $defs[$p['name']]['sets_status'] : 'STUNNED';
        $player['STATUS'] = $sets;
        $player['ACTIONS'] = 0;
    } elseif ($player['STATUS'] === 'STUNNED') {
        $player['STATUS'] = 'IDLE'; // эффект истёк/снял source — освобождаем
    }
    unset($player);
}

/**
 * Один серверный тик: все анимационно-временные обновления + ход мира.
 * Возвращает true если состояние изменилось (нужен бум версии для long-poll).
 *
 * ВАЖНО (баг «таймер стоит на месте»): тик и проверка хода выполняются ВСЕГДА,
 * независимо от того, передал клиент ?player= или знает ли он этого игрока.
 * Роль-специфичные проверки остаются ответственностью вызывающего слоя.
 */
function turn_tick(&$state) {
    $changed = false;

    // 1) Движение/анимации: сравниваем по хешу только мутируемые секции
    $before = json_encode([$state['players'], $state['npcs'], $state['projectiles']]);

    if (movement_update($state)) $changed = true; // catch-up шаг виртуальных часов
    turn_update_death_animations($state);
    turn_update_projectiles($state);
    turn_reset_attack_status($state);
    objects_update_npc_statuses($state);

    $after = json_encode([$state['players'], $state['npcs'], $state['projectiles']]);
    if ($before !== $after) $changed = true;

    // 2) Ход мира раз в TURN_DURATION — эмит turn.start внутри.
    //    turn_start обновляет ACTIONS/щиты у игроков, поэтому секция players
    //    могла измениться относительно $after — пересчитываем флаг.
    if (turn_check_advance($state)) {
        $changed = true;
    }

    // 3) ★ Инвариант «STATUS ↔ NEGATIVES» — ЕДИНАЯ точка синхронизации на
    //    каждом тике. Если cc-эффект активен, но STATUS не выставлен (и
    //    состояние не анимационное) — починить; если STATUS='STUNNED', а
    //    cc-эффектов нет — снять.
    //    Раньше здесь был симметричный «починитель», который ВЫСТАВЛЯЛ
    //    STUNNED/ACTIONS=0 игрокам в MOVING/ATTACK/ABILITY: любое попадание
    //    под турель с STUNNED[2] от мины превращало короткую анимацию удара
    //    в вечный оглуш (ATTACK→STUNNED и блокировка всех действий навсегда).
    //    Анимационные состояния теперь только ОСВОБОждаются из залипшего
    //    STUNNED, но никогда не перехватываются шиной — движение доезжает,
    //    удар досвечивается, статус выставит movement-finish/effects_tick.
    foreach ($state['players'] as $pid => &$p) {
        if (in_array($p['STATUS'], ['MOVING', 'DEATH', 'DECAY', 'REMOVED', 'ATTACK', 'ABILITY', 'SPECIAL'], true)) {
            continue;
        }
        $cc = effects_blocks($p, 'actions');
        if ($cc['blocked']) {
            if ($p['STATUS'] !== 'STUNNED') {
                $cp = effects_parse($cc['reason']);
                $defs = getEffectDefinitions();
                $sets = ($cp && isset($defs[$cp['name']]['sets_status'])) ? $defs[$cp['name']]['sets_status'] : 'STUNNED';
                $p['STATUS'] = $sets;
                $p['ACTIONS'] = 0;
                $changed = true;
            }
        } elseif ($p['STATUS'] === 'STUNNED') {
            $p['STATUS'] = 'IDLE'; // эффект истёк/снят — освобождаем мгновенно
            $changed = true;
        }
    }
    unset($p);

    // 4) Пересчёт зон угрозы NPC ПОСЛЕ фазы движения: спрайт мог сойти с
    //    клетки LOS или зайти за бочку — устаревший THREAT_ZONE «стрелял» бы
    //    по мёртвому/пустой клетке либо не попал бы по живому.
    objects_refresh_threat_zones($state);

    return $changed;
}

/**
 * Проверить/вызвать начало нового хода (если прошло TURN_DURATION).
 * @return bool был ли новый ход
 *
 * ВАЖНО: если turn_time не задан (0) — это первый контакт с миром после
 * init.php или legacy-состояние. Инициализируем его ТЕКУЩИМ временем и НЕ
 * запускаем ход: иначе весь long-poll цикл (poll → tick → advance → save)
 * инкрементировал бы REDIS_VERSION на каждой итерации, версия менялась бы
 * быстрее, чем клиент успевает обработать ответ, и client-side таймер хода
 * «замирал» бы до первого действия игрока (баг рефакторинга).
 */
function turn_check_advance(&$state) {
    $now = time();
    if (!isset($state['turn_time']) || (int)$state['turn_time'] <= 0) {
        $state['turn_time'] = $now;
        return false;
    }
    if ($now - $state['turn_time'] >= TURN_DURATION) {
        // Дедлайн, а не «now»: иначе при редких опросах ход смещался бы назад.
        $state['turn_time'] += TURN_DURATION;
        if ($now - $state['turn_time'] >= TURN_DURATION) {
            $state['turn_time'] = $now; // отстали больше чем на ход — синхронизация
        }
        turn_start($state);
        return true;
    }
    return false;
}

/**
 * ★ Фазы DEATH/DECAY: переход на респаун в Личную SPAWN_ROOM.
 * Возвращает true, если цепочка на этом тике сдвинулась (нужен world_save).
 *
 * ВАЖНО (пункт плана №1): возрождение идёт НЕ прямой записью координат, а
 * через rooms_teleport(..., 'respawn') — иначе игрок «воскресал» на клетке
 * спавна в чужой комнате (позиция без смены ROOM_ID), и state['rooms'] не
 * пополнялся картой его SPAWN_ROOM. rooms_teleport сам подбирает ближайшую
 * свободную клетку от личной START_POSITION_XY (якорь спавна) и эмитит
 * 'room.changed'.
 */
function turn_update_death_animations(&$state) {
    $now = time();
    $changed = false;

    foreach ($state['players'] as $pid => &$player) {
        if ($player['STATUS'] === 'DEATH') {
            if (!isset($player['DEATH_TIME'])) {
                $player['DEATH_TIME'] = $now;
            } elseif ($now - $player['DEATH_TIME'] >= DEATH_ANIM_SECONDS) {
                $player['STATUS'] = 'DECAY';
                $player['DECAY_TIME'] = $now;
                unset($player['DEATH_TIME']);
                $changed = true;
            }
        } elseif ($player['STATUS'] === 'DECAY') {
            if (!isset($player['DECAY_TIME'])) {
                $player['DECAY_TIME'] = $now;
            } elseif ($now - $player['DECAY_TIME'] >= DECAY_ANIM_SECONDS) {
                if ($player['LIFE'] > 0) {
                    $player['LIFE']--;
                    $player['HITPOINTS'] = $player['MAX_HITPOINTS'];
                    $player['SHIELD'] = $player['MAX_SHIELD'];
                    // Респаун через телепорт в личную SPAWN_ROOM (см. шапку):
                    // позиция, ROOM_ID и карта комнаты обновляются атомарно.
                    $spawn_room = $player['SPAWN_ROOM'] ?? '';
                    unset($player['DEATH_TIME'], $player['DECAY_TIME']);
                    $res = rooms_teleport($state, $pid, $spawn_room, 'respawn');
                    if (empty($res['success'])) {
                        // Страховка legacy-поведения: телепорт невозможен
                        // (нет карты/свободной клетки) — возвращаем на стартовую
                        // клетку внутри текущей комнаты, статус всё равно IDLE.
                        $player['CUR_POSITION_XY'] = $player['START_POSITION_XY'];
                        $player['END_POSITION_XY'] = $player['START_POSITION_XY'];
                        $player['STATUS'] = 'IDLE';
                        $state = world_log($state, "{$pid}: респаун-телепорт не удался (" . ($res['message'] ?? '?') . ") — возврат на клетку старта");
                    }
                    $changed = true;
                } else {
                    $player['STATUS'] = 'REMOVED';
                    unset($player['DEATH_TIME'], $player['DECAY_TIME']);
                    $changed = true;
                }
            }
        }
    }
    unset($player);
    return $changed;
}

/** Проектели: cast → hit → done по серверным часам (бывш. updateProjectiles) */
function turn_update_projectiles(&$state) {
    $now = time();

    foreach ($state['projectiles'] as &$projectile) {
        if ($projectile['status'] === 'cast' && $now - $projectile['created'] >= PROJECTILE_CAST_SECONDS) {
            $projectile['status'] = 'hit';
            $projectile['hit_time'] = $now;
        } elseif ($projectile['status'] === 'hit' && $now - $projectile['hit_time'] >= PROJECTILE_HIT_SECONDS) {
            $projectile['status'] = 'done';
        }
    }
    unset($projectile);

    $state['projectiles'] = array_values(array_filter(
        $state['projectiles'],
        function ($p) use ($now) {
            return !($p['status'] === 'done' && $now - $p['hit_time'] >= PROJECTILE_HIT_SECONDS);
        }
    ));
}

/** Сброс ATTACK/ABILITY/SPECIAL → IDLE через секунду (бывш. resetAttackStatus) */
function turn_reset_attack_status(&$state) {
    $now = time();

    foreach ($state['players'] as $pid => &$player) {
        if (($player['STATUS'] === 'ATTACK' || $player['STATUS'] === 'ABILITY' || $player['STATUS'] === 'SPECIAL') &&
            isset($player['ATTACK_TIME']) &&
            $now - $player['ATTACK_TIME'] >= ATTACK_RESET_SECONDS) {
            $player['STATUS'] = 'IDLE';
            unset($player['ATTACK_TIME']);
        }
    }
    unset($player);
}

/** Регистрация оркестрационных слушателей */
function turn_register_hooks() {
    // effects-тик идёт на равных с прочими turn.start слушателями (priority 5)
    hook('turn.start', 'turn_effects_tick', 5);
    // Пересборка STATUS из NEGATIVES — после немедленной cc-активации effects (p0)
    hook('effect.applied', 'turn_sync_status_after_effect', 10);
}
