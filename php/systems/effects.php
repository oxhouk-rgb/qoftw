<?php
/**
 * systems/effects.php — ★ Реестр и единственный владелец статус-эффектов.
 *
 * ДО РЕФАКТОРИНГА эффект был «строкой + switch в двух файлах + strpos-знаниями
 * в четырёх». Теперь эффект = запись в getEffectDefinitions() с декларацией
 * поведения. Носители статуса (movement/combat/action) ОПРАШИВАЮТ effects,
 * а не парсят NEGATIVES сами. Новый эффект = одна строка реестра (+ обработчик
 * здесь же), правки в других файлах не нужны.
 *
 * ФОРМАТ ХРАНЕНИЯ: state['players'][id]['NEGATIVES'] = ["NAME[duration]", ...]
 * Формат владеет ТОЛЬКО этот модуль (parse/format). Долгосрочно (фаза 3):
 * перейти на структуры {name,duration,source} — тогда появятся dispel/иммунитеты.
 *
 * KIND'Ы ЭФФЕКТОВ:
 *   dot      — тикает уроном на turn.start (ticks_damage); универсальный
 *              постепенный урон DOT[N] (заменил дубликаты BLEED/POISONED)
 *   cc       — блокирует глаголы (blocks: move|attack|ability|special|actions);
 *              immediate=true → при наложении вне движения сразу ставит STATUS
 *              и обнуляет ACTIONS (кроме MOVING/DEATH/DECAY — спрайт должен
 *              доехать до клетки мины, см. исторические комментарии)
 *   modifier — меняет статы через getModifier (SLOWED→move_distance и т.п.)
 *   passive  — ничего не делает сам, только предоставляет данные запросам
 *
 * API МОДУЛЯ (единственные точки входа для остального кода):
 *   effects_parse($str)                 — "STUNNED[2]" → ['name','duration']
 *   effects_format($name,$duration)     — обратно в строку
 *   effects_apply(&$state,$type,$id,$str) — наложить/продлить (max duration)
 *   effects_tick(&$state,$player_id)    — прогон NEGATIVES на начале хода
 *   effects_blocks($player,$verb)       — блокирует ли что-то из NEGATIVES?
 *   effects_block_message($player,$verb)— ИГРОВОЕ сообщение для игрока, если
 *              глагол заблокирован (из реестра: 'block_message'); '' если блокера нет
 *   effects_get_modifier($player,$stat) — суммарный модификатор стата
 *   effects_has($player,$name)          — есть ли эффект по имени
 */

require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/rules.php';

/**
 * Реестр определений эффектов — ЕДИНСТВЕННЫЙ список семантики статусов.
 */
function getEffectDefinitions() {
    return [
        // DoT: универсальный постепенный урон в начале каждого хода
        // (заменил дубликаты BLEED/POISONED — механика одна: −1 HP/ход, N ходов)
        'DOT' => [
            'kind' => 'dot', 'ticks_damage' => 1,
            'description' => 'Постепенный урон: 1 урон за ход',
        ],
        // CC: блокируют действия; декларируют ЧТО именно
        // (глаголы-ключи: move/attack/ability/special — special = третья
        //  способность, самостоятельный глагол; 'actions' = блокировка всего)
        'STUNNED' => [
            'kind' => 'cc',
            'blocks' => ['move', 'attack', 'ability', 'special', 'actions'],
            'immediate' => true,           // STATUS='STUNNED' сразу при наложении
            'sets_status' => 'STUNNED',    // какой STATUS ставить/снимать
            'block_message' => 'Игрок оглушен', // игровое сообщение (совпадает с текстом по STATUS)
            'description' => 'Оглушение: полная блокировка действий',
        ],
        'PARALYZED' => [
            'kind' => 'cc',
            'blocks' => ['move'],
            'actions_penalty' => 1,        // −1 ACTION за ход
            'block_message' => 'Вы обездвижены', // игровое сообщение при попытке заблокированного глагола
            'description' => 'Обездвиживание: нельзя двигаться, −1 очко действий',
        ],
        // Modifiers: влияют на статы через effects_get_modifier
        'SLOWED' => [
            'kind' => 'modifier', 'stat' => 'move_distance', 'value' => -2,
            'description' => 'Замедление: −2 к дистанции перемещения',
        ],
        'BLINDED' => [
            'kind' => 'modifier', 'stat' => 'attack_range', 'value_factor' => 0.5,
            'description' => 'Ослепление: дальность атаки/способностей /2',
        ],
    ];
}

/** Парсинг "NAME[n]" → ['name'=>..., 'duration'=>n] | null */
function effects_parse($str) {
    if (preg_match('/^([A-Z_]+)\[(\d+)\]$/', $str, $m)) {
        return ['name' => $m[1], 'duration' => (int)$m[2]];
    }
    return null;
}

/** Форматирование эффекта в строку хранения */
function effects_format($name, $duration) {
    return $name . '[' . $duration . ']';
}

/** Есть ли у носителя эффект с именем name */
function effects_has($player, $name) {
    foreach ($player['NEGATIVES'] as $neg) {
        $p = effects_parse($neg);
        if ($p && $p['name'] === $name) return true;
    }
    return false;
}

/**
 * Блокирует ли набор NEGATIVES игрока указанный глагол?
 * @param array  $player состояние игрока
 * @param string $verb   'move'|'attack'|'ability'|'special'|'actions'
 * @return array ['blocked'=>bool,'reason'=>string]
 */
function effects_blocks($player, $verb) {
    $defs = getEffectDefinitions();
    foreach ($player['NEGATIVES'] as $neg) {
        $p = effects_parse($neg);
        if (!$p) continue;
        $def = $defs[$p['name']] ?? null;
        if ($def && ($def['kind'] ?? '') === 'cc' && in_array($verb, $def['blocks'] ?? [])) {
            return ['blocked' => true, 'reason' => $neg];
        }
    }
    return ['blocked' => false, 'reason' => ''];
}

/**
 * Игровое сообщение для игрока о том, ПОЧЕМУ глагол заблокирован.
 * Текст владеет реестр эффектов (поле 'block_message') — эндпоинты не хардкодят
 * имена статусов. Если у блокера сообщения нет, используется общее «Действие
 * заблокировано эффектом: <NEGATIVES-строка>».
 * @return string '' если блокировки глагола нет вообще
 */
function effects_block_message($player, $verb) {
    $res = effects_blocks($player, $verb);
    if (!$res['blocked']) return '';
    $p = effects_parse($res['reason']);
    $def = ($p ? getEffectDefinitions()[$p['name']] ?? null : null);
    if ($def && !empty($def['block_message'])) return $def['block_message'];
    return 'Действие заблокировано эффектом: ' . $res['reason'];
}

/**
 * Суммарный модификатор стата от всех NEGATIVES носителя.
 * @return array ['add'=>int, 'factor'=>float] — применить: value*factor + add
 */
function effects_get_modifier($player, $stat) {
    $defs = getEffectDefinitions();
    $add = 0; $factor = 1.0;
    foreach ($player['NEGATIVES'] as $neg) {
        $p = effects_parse($neg);
        if (!$p) continue;
        $def = $defs[$p['name']] ?? null;
        if ($def && ($def['kind'] ?? '') === 'modifier' && ($def['stat'] ?? '') === $stat) {
            if (isset($def['value'])) $add += $def['value'];
            if (isset($def['value_factor'])) $factor *= $def['value_factor'];
        }
    }
    return ['add' => $add, 'factor' => $factor];
}

/**
 * Наложить эффект (или продлить до max длительности). Единственный способ
 * добавления в NEGATIVES для всего остального кода.
 * Эмитит 'effect.applied' — слушатель ниже выполняет немедленную активацию cc.
 */
function effects_apply(&$state, $target_type, $target_id, $effect_str) {
    // Пока эффекты поддерживают только игроков (см. заметку в шапке, фаза 3)
    if ($target_type !== 'player') return;
    if (!isset($state['players'][$target_id])) return;

    $parsed = effects_parse($effect_str);
    if (!$parsed) return;
    $defs = getEffectDefinitions();
    if (!isset($defs[$parsed['name']])) return; // неизвестный эффект игнорируется

    $player = &$state['players'][$target_id];
    $found = false;
    foreach ($player['NEGATIVES'] as &$neg) {
        $m = effects_parse($neg);
        if ($m && $m['name'] === $parsed['name']) {
            $neg = effects_format($parsed['name'], max($m['duration'], $parsed['duration']));
            $found = true;
            break;
        }
    }
    unset($neg);
    if (!$found) {
        $player['NEGATIVES'][] = effects_format($parsed['name'], $parsed['duration']);
    }

    emit($state, 'effect.applied', [
        'target_type' => $target_type,
        'target_id' => $target_id,
        'name' => $parsed['name'],
        'duration' => $parsed['duration'],
    ]);
}

/**
 * Слушатель 'effect.applied': немедленная активация cc-эффектов с immediate=true.
 * Эффект, полученный ВНЕ хода игрока (ловушка, способность, ответный урон),
 * должен отражаться на STATUS сразу — иначе клиент увидит «оглушённого» только
 * на следующем ходе. MOVING/DEATH/DECAY не прерываем: спрайт обязан доехать до
 * клетки мины (STUNNED выставит movement-finish), смерть важнее статусов.
 */
function effects_on_applied(&$state, $payload) {
    if ($payload['target_type'] !== 'player') return;
    $defs = getEffectDefinitions();
    $def = $defs[$payload['name']] ?? null;
    if (!$def || empty($def['immediate'])) return;

    $player = &$state['players'][$payload['target_id']];
    if (!in_array($player['STATUS'], ['MOVING', 'DEATH', 'DECAY'], true)) {
        $player['STATUS'] = $def['sets_status'] ?? strtoupper($payload['name']);
        $player['ACTIONS'] = 0;
    }
}

/**
 * Прогон NEGATIVES носителя на начале хода (turn.start):
 *   dot → урон (через combat.applyDamage — единая цепочка щит/HP/смерть),
 *   cc  → блокировки actions / штрафы,
 *   все → уменьшение duration; при истечении — снятие STATUS, если он
 *   принадлежит этому эффекту (sets_status), а не чужому состоянию.
 */
function effects_tick(&$state, $player_id) {
    $player = &$state['players'][$player_id];
    $defs = getEffectDefinitions();
    $new_negatives = [];

    foreach ($player['NEGATIVES'] as $neg) {
        $p = effects_parse($neg);
        if (!$p) continue; // мусор — молча выбрасываем
        $def = $defs[$p['name']] ?? null;
        if (!$def) { $new_negatives[] = $neg; continue; } // неизвестный — сохраняем

        $kind = $def['kind'] ?? '';

        if ($kind === 'dot' && !empty($def['ticks_damage'])) {
            // Единая цепочка урона (щит→HP→death) — systems/combat.php.
            // Движение прерываем ЯВНО ДО урона, чтобы cc-эффекты этого же тика
            // (ниже) застали STATUS != MOVING и сразу выставили STUNNED.
            if ($player['STATUS'] === 'MOVING') {
                movement_interrupt($state, $player_id);
            }
            combat_apply_damage($state, 'player', $player_id, $def['ticks_damage'],
                                ['type' => 'effect', 'id' => $p['name']]);
        }

        if ($kind === 'cc') {
            if (in_array('actions', $def['blocks'] ?? [])) {
                $player['ACTIONS'] = 0;
                if (!in_array($player['STATUS'], ['MOVING', 'DEATH', 'DECAY'], true)) {
                    $player['STATUS'] = $def['sets_status'] ?? strtoupper($p['name']);
                }
            }
            if (!empty($def['actions_penalty'])) {
                $player['ACTIONS'] = max(0, $player['ACTIONS'] - $def['actions_penalty']);
            }
        }
        // modifier-эффекты действуют пассивно через effects_get_modifier — тик не нужен

        $duration = $p['duration'] - 1;
        if ($duration > 0) {
            $new_negatives[] = effects_format($p['name'], $duration);
        } else {
            // Эффект истёк: снять STATUS, только если он выставлен ИМЕННО этим эффектом
            $sets = $def['sets_status'] ?? null;
            if ($sets && $player['STATUS'] === $sets) {
                $player['STATUS'] = 'IDLE';
            }
        }
    }

    $player['NEGATIVES'] = $new_negatives;

    // ★ Инвариант «STATUS ↔ NEGATIVES»: после пересборки списка эффектов статус
    // обязан соответствовать фактическому набору NEGATIVES. Лечит залипание
    // STUNNED навсегда (баг рефакторинга: STATUS снимался только на границе
    // хода/финале движения, а эффект мог истечь посреди хода или игрок мог
    // умереть с висевшим статусом) и обратный случай — cc активен, STATUS нет.
    if (!in_array($player['STATUS'], ['MOVING', 'DEATH', 'DECAY', 'REMOVED', 'ATTACK', 'ABILITY', 'SPECIAL'], true)) {
        $cc = effects_blocks($player, 'actions');
        if ($cc['blocked']) {
            $cp = effects_parse($cc['reason']);
            $sets = ($cp && isset($defs[$cp['name']]['sets_status'])) ? $defs[$cp['name']]['sets_status'] : 'STUNNED';
            $player['STATUS'] = $sets;
            $player['ACTIONS'] = 0;
        } elseif ($player['STATUS'] === 'STUNNED') {
            $player['STATUS'] = 'IDLE';
        }
    }
    unset($player);
}

/** Регистрация слушателей шины (вызывается bootstrap'ом ровно один раз) */
function effects_register_hooks() {
    hook('effect.applied', 'effects_on_applied');
}
