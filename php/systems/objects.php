<?php
/**
 * systems/objects.php — Автономные объекты: NPC (турели) и ловушки.
 *
 * Семантика прежняя (см. исторические комментарии ниже), но урон переведён на
 * ЕДИНУЮ цепочку combat_apply_damage: раньше trap-версия не прерывала MOVING,
 * а NPC-версия писала щит/HP вручную. Теперь оба идут через combat, который
 * сам эмитит damage.dealt → interrupt move / thorns и entity.died.
 *
 * Поле ALLY у автономного объекта = «кто создал объект»; ALLY='NONE' — враждебен
 * всем. Игрок-создатель и его союзник целями не считаются.
 */

require_once __DIR__ . '/../engine/events.php';
require_once __DIR__ . '/../engine/geometry.php';
require_once __DIR__ . '/../engine/rules.php';
require_once __DIR__ . '/../engine/world.php';
require_once __DIR__ . '/targeting.php';
require_once __DIR__ . '/effects.php';
require_once __DIR__ . '/movement.php'; // engine_active_room() — карты комнат для LOS/зон угрозы
require_once __DIR__ . '/rooms.php';    // room_get() — ленивая загрузка карт комнат NPC

/** Клиентский реестр типов автономных объектов (описания для UI/диагностики) */
function getAutonomousObjectDefinitions() {
    $out = [];
    foreach (getObjectTemplates() as $type => $tpl) {
        $out[$type] = [
            'image' => $tpl['image'],
            'category' => $tpl['category'],
            'description' => $tpl['description'],
        ];
    }
    return $out;
}

/**
 * ★ РЕЕСТР ШАБЛОНОВ ОБЪЕКТОВ / ЛОВУШЕК / NPC — единый источник их характеристик.
 *
 * Отвечает на вопрос «где теперь ИЗНАЧАЛЬНОЕ состояние мира, если хранилище —
 * Redis»: характеристики ТИПА живут в коде (этот реестр), конкретная
 * РАССТАНОВКА — в JSON-картах комнат (секции objects/traps/npcs файлов
 * data/<ID>.json). Снимок в Redis — производная этих двух источников, поэтому
 * мир воспроизводим: reset = перечитать код + карты и записать мир заново
 * (tools/reset_world.php → seed-файл → Redis). Держать числа в двух местах
 * нельзя: правка урона мины в данных расходилась бы с описанием в клиентском
 * реестре, а ability-spawn собирал бы объект «наполовину из прошлого».
 *
 * Поля шаблона: category (object|trap|npc — секция state), image (спрайт),
 * defaults (CAPS-поля экземпляра), description (для клиента и отладки).
 *
 * ЗАДЕЛ НА БУДУЩЕЕ: создаваемые способностями объекты (стены, тумбы, миньоны)
 * регистрируются здесь же — способности вызывают objects_spawn_entity(), поэтому
 * ни нового хранилища, ни правок рантайма для них не потребуется.
 */
function getObjectTemplates() {
    return [
        // ── Разрушаемые объекты (укрытия) ──
        'SMALL_BARRELS' => [
            'category' => 'object', 'image' => 'SMALL_BARRELS',
            'defaults' => ['HITPOINTS' => 3],
            'description' => 'Стопка бочек. Неподвижное укрытие: занимает клетку и блокирует линию видимости, разрушается уроном (STATUS DESTROYED).',
        ],
        // ── Ловушки ──
        'LAND_MINE' => [
            'category' => 'trap', 'image' => 'LAND_MINE',
            'defaults' => [
                'HITPOINTS' => 1, 'DAMAGE' => 3, 'RANGE' => 1,
                'EFFECT' => ['STUNNED[2]', 'BLEED[2]'],
            ],
            'description' => 'Наземная мина. Взрывается при входе враждебного бойца на клетку: урон + STUNNED/BLEED, затем STATUS TRIGGERED.',
        ],
        // ── NPC ──
        'DEFAULT_TURRET' => [
            'category' => 'npc', 'image' => 'DEFAULT_TURRET',
            'defaults' => [
                'HITPOINTS' => 5, 'MAX_HITPOINTS' => 5, 'ATTACK' => 2, 'RANGE' => 4,
                'ACTIONS' => 1, 'MAX_ACTIONS' => 1,
            ],
            'description' => 'Стационарная турель. Атакует любого враждебного игрока в зоне угрозы (манхэттен-RANGE + LOS), один выстрел за ход.',
        ],
    ];
}

/**
 * Канонический набор полей сущности по умолчанию. Единственное место, где они
 * определены: и сидинг, и спавн собирают экземпляр через objects_build_entity().
 */
function objectEntityDefaults($category) {
    switch ($category) {
        case 'trap':
            return ['IMAGE' => '', 'ROOM_ID' => '', 'POSITION_XY' => '0,0', 'STATUS' => 'INTACT',
                    'HITPOINTS' => 1, 'DAMAGE' => 1, 'RANGE' => 1, 'EFFECT' => [], 'ALLY' => 'NONE'];
        case 'npc':
            return ['IMAGE' => '', 'ROOM_ID' => '', 'POSITION_XY' => '0,0', 'STATUS' => 'IDLE',
                    'HITPOINTS' => 5, 'MAX_HITPOINTS' => 5, 'ATTACK' => 2, 'RANGE' => 4,
                    'CUR_TARGET' => 'NONE', 'ALLY' => 'NONE', 'ACTIONS' => 1, 'MAX_ACTIONS' => 1];
        default: // object
            return ['IMAGE' => '', 'ROOM_ID' => '', 'POSITION_XY' => '0,0', 'STATUS' => 'INTACT',
                    'HITPOINTS' => 1, 'ALLY' => 'NONE'];
    }
}

/** Секция состояния по категории шаблона */
function objects_section_of($category) {
    return ['object' => 'objects', 'trap' => 'traps', 'npc' => 'npcs'][$category] ?? 'objects';
}

/**
 * Собрать ЭКЗЕМПЛЯР сущности: дефолты категории ← defaults шаблона ← override
 * из карты комнаты (ID / POSITION_XY / ROOM_ID / ALLY / отдельные статы).
 * Один путь сборки и для стартового сида, и для будущих spawn-способностей.
 * @return array|null null — неизвестный тип (вызывающий решает, ругаться или нет)
 */
function objects_build_entity($type, $overrides = [], $default_room_id = null) {
    $tpl = getObjectTemplates()[$type] ?? null;
    if ($tpl === null) return null;
    $category = $tpl['category'];
    $entity = objectEntityDefaults($category);
    $entity['TYPE'] = $type;
    $entity['IMAGE'] = $tpl['image'];
    foreach ((array)$tpl['defaults'] as $k => $v) $entity[$k] = $v;
    foreach ((array)$overrides as $k => $v) $entity[$k] = $v;
    if (($entity['ROOM_ID'] ?? '') === '' && $default_room_id !== null && $default_room_id !== '') {
        $entity['ROOM_ID'] = $default_room_id;
    }
    if (empty($entity['ID'])) $entity['ID'] = $type;
    if ($category === 'trap' && is_string($entity['EFFECT'] ?? null)) {
        $entity['EFFECT'] = [$entity['EFFECT']];
    }
    if ($category === 'npc') $entity['THREAT_ZONE'] = [];
    return $entity;
}

/**
 * ★ Расстановка сущностей по карте комнаты — источник ИЗНАЧАЛЬНОГО состояния.
 * Элемент секции objects/traps/npcs карты = {TYPE (или IMAGE как имя шаблона),
 * ID?, POSITION_XY, ...override}. Если ID не задан — генерируется
 * "<TYPE>_<комната>_<номер>". Неизвестный тип или тип чужой секции пропускается
 * (мир не должен падать из-за опечатки в данных).
 * @return array ['objects'=>[..], 'traps'=>[..], 'npcs'=>[..]] — словари по ID
 */
function objects_layout_from_room($room, $room_id = null) {
    $rid = $room_id ?? ($room['room_id'] ?? '');
    $out = ['objects' => [], 'traps' => [], 'npcs' => []];
    foreach (array_keys($out) as $sec) {
        foreach ((array)($room[$sec] ?? []) as $i => $item) {
            if (!is_array($item)) continue;
            $type = $item['TYPE'] ?? ($item['IMAGE'] ?? null);
            if (!$type) continue;
            $tpl = getObjectTemplates()[$type] ?? null;
            if ($tpl === null || objects_section_of($tpl['category']) !== $sec) continue;
            $ov = $item;
            unset($ov['TYPE']);
            if (empty($ov['ID'])) $ov['ID'] = $type . '_' . $rid . '_' . ($i + 1);
            $entity = objects_build_entity($type, $ov, $rid);
            $out[$sec][$entity['ID']] = $entity;
        }
    }
    return $out;
}

/**
 * ★ СПАВН экземпляра сущности в мире (сюда придут spawn-объекты способностей).
 * Только мутирует переданный $state — сохранение (world_save/world_save_cas)
 * обязан сделать вызывающий, иначе изменение не дойдёт до Redis и версии.
 * @return array ['success'=>bool,'message'=>string,'id'=>string|null]
 */
function objects_spawn_entity(&$state, $type, $x, $y, $room_id, $creator_id = 'NONE', $overrides = []) {
    $tpl = getObjectTemplates()[$type] ?? null;
    if ($tpl === null) {
        return ['success' => false, 'message' => 'Неизвестный тип объекта: ' . $type, 'id' => null];
    }
    if (!isValidPosition($x, $y)) {
        return ['success' => false, 'message' => 'Координаты вне поля', 'id' => null];
    }
    $sec = objects_section_of($tpl['category']);
    $base_id = $type . '_' . $room_id . '_' . $x . '_' . $y;
    $id = $base_id;
    $n = 1;
    while (isset($state[$sec][$id])) { $id = $base_id . '-' . (++$n); }

    $entity = objects_build_entity($type, array_merge([
        'ID' => $id,
        'POSITION_XY' => formatXY($x, $y),
        'ROOM_ID' => $room_id,
        'ALLY' => (($creator_id !== null && $creator_id !== '') ? $creator_id : 'NONE'),
    ], (array)$overrides), $room_id);

    $state[$sec][$id] = $entity;
    $state = world_log($state, "OBJECT: заспавнен {$type} (#{$id}) в комнате {$room_id} на клетке ({$x},{$y})");
    if ($tpl['category'] === 'npc') {
        $state[$sec][$id]['THREAT_ZONE'] = calculateThreatZone($state, $id);
    }
    return ['success' => true, 'message' => "Создан объект {$type}", 'id' => $id];
}

/**
 * ============================================================================
 * РАЗДЕЛ 1: NPC (автономные стрелковые объекты)
 * ============================================================================
 */

/**
 * Линия видимости для NPC: прямые (гориз/верт) и диагонали (Г-образная проверка).
 * ★ Комнатная изоляция: сетка берётся из карты комнаты npc_room (engine_active_room),
 * объекты/NPC/игроки учитываются только той же комнаты — иначе турель в B1
 * «видела» бы стены и тела игроков из соседних комнат A1/A5/E1/E5.
 */
function objects_has_line_of_sight($state, $x1, $y1, $x2, $y2, $npc_room = null) {
    list($dx, $dy) = directionStep($x1, $y1, $x2, $y2);
    $room = engine_active_room($state, $npc_room);

    $blocked_at = function ($cx, $cy) use ($state, $room, $npc_room) {
        if (!isValidPosition($cx, $cy)) return true; // выход за карту = блок
        if (isset($room['grid'][$cy][$cx]) && (int)$room['grid'][$cy][$cx] === 0) return true;
        foreach ($state['objects'] as $obj) {
            if ($npc_room !== null && ($obj['ROOM_ID'] ?? null) !== $npc_room) continue;
            if ($obj['STATUS'] === 'DESTROYED') continue;
            $pos = parseXY($obj['POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) return true;
        }
        foreach ($state['players'] as $pid => $player) {
            if ($npc_room !== null && ($player['ROOM_ID'] ?? null) !== $npc_room) continue;
            if ($player['STATUS'] === 'DEATH' || $player['STATUS'] === 'DECAY' || $player['STATUS'] === 'REMOVED') continue;
            $pos = parseXY($player['CUR_POSITION_XY']);
            if ($pos['x'] === $cx && $pos['y'] === $cy) return true;
        }
        return false;
    };

    if ($dx === 0 || $dy === 0) {
        $cx = $x1 + $dx; $cy = $y1 + $dy;
        while (($cx !== $x2 || $cy !== $y2) && isValidPosition($cx, $cy)) {
            if ($blocked_at($cx, $cy)) return false;
            $cx += $dx; $cy += $dy;
        }
        return true;
    }

    // Диагональ: сначала горизонталь до x2, затем вертикаль до y2
    $cx = $x1 + $dx;
    while ($cx !== $x2 && isValidPosition($cx, $y1)) {
        if ($blocked_at($cx, $y1)) return false;
        $cx += $dx;
    }
    $cy = $y1 + $dy;
    while ($cy !== $y2 && isValidPosition($x2, $cy)) {
        if ($blocked_at($x2, $cy)) return false;
        $cy += $dy;
    }
    return true;
}

/**
 * Зона угрозы NPC: клетки в манхэттен-RANGE с линией видимости.
 * ★ Комнатная изоляция: зона считается только внутри комнаты NPC — соседняя
 * комната может иметь ту же систему координат, без фильтра турель «видела» бы
 * клетки через дверь.
 */
function calculateThreatZone(&$state, $npc_id) {
    $npc = $state['npcs'][$npc_id];
    $npc_pos = parseXY($npc['POSITION_XY']);
    $npc_room = $npc['ROOM_ID'] ?? null;
    $threat_cells = [];

    foreach (cellsInRadius($npc_pos['x'], $npc_pos['y'], $npc['RANGE']) as $cell) {
        if ($cell['x'] === $npc_pos['x'] && $cell['y'] === $npc_pos['y']) continue;
        if (objects_has_line_of_sight($state, $npc_pos['x'], $npc_pos['y'], $cell['x'], $cell['y'], $npc_room)) {
            $threat_cells[] = $cell;
        }
    }

    return $threat_cells;
}

/**
 * Проверить пересечение игрока с зонами угрозы всех NPC.
 * Вызывается: при входе движущегося игрока на клетку, на финале движения,
 * и на начале хода для стоящих (turn.start listener ниже).
 *
 * @param array $opts ['dry_run'=>bool] — dry_run НЕ тратит ACTIONS NPC, не
 *        выставляет ему STATUS ATTACK и не мутирует ловушки: используется
 *        фазой предсказания movement_process_move, чтобы «посмотреть» путь,
 *        не сжигая боезапас турели до реального прохода клетки (иначе фаза 2
 *        видела бы ACTIONS=0 и никогда не стреляла — баг рефакторинга).
 *
 * ★ Целеполагание историческое (объекты/бочки не атакует) + жёсткий фильтр
 *   живости цели: мёртвый/разлагающийся/удалённый игрок — не цель. Раньше
 *   турель «добила» труп (повтор ATTACK-анимации каждый ход из баг-репорта):
 *   combat_apply_damage по DEATH возвращает 0 урона, но ACTION тратился и
 *   анимация запускалась впустую.
 */
function objects_check_threat_zone(&$state, $player_id, $x, $y, $opts = []) {
    $dry_run = !empty($opts['dry_run']);
    $results = [];
    $player = $state['players'][$player_id] ?? null;
    if (!$player) return $results;
    if (in_array($player['STATUS'], ['DEATH', 'DECAY', 'REMOVED'], true)) return $results;
    // ★ Комнатная изоляция: турель атакует только целей в СВОЕЙ комнате —
    // координатные сетки соседних комнат совпадают, без фильтра игрок из A1
    // «стоял» бы на той же клетке, что и зона угрозы турели в B1.
    $player_room = $player['ROOM_ID'] ?? null;

    foreach ($state['npcs'] as $npc_id => &$npc) {
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH'], true)) continue;
        if ($npc['ACTIONS'] <= 0) continue;
        if (($npc['ROOM_ID'] ?? null) !== $player_room) continue;

        // Пропускаем союзников (NPC 'ALLY' = создатель)
        if ($npc['ALLY'] !== 'NONE') {
            if ($npc['ALLY'] === $player_id || $player['ALLY'] === $npc['ALLY']) continue;
        }

        if (!isset($npc['THREAT_ZONE'])) {
            $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
        }

        $in_zone = false;
        foreach ($npc['THREAT_ZONE'] as $cell) {
            if ($cell['x'] === $x && $cell['y'] === $y) { $in_zone = true; break; }
        }
        if (!$in_zone) continue;

        $damage = $npc['ATTACK'];

        if (!$dry_run) {
            $npc['ACTIONS']--; // одно действие = одна атака за ход

            // Анимация ATTACK (сбросится в IDLE через updateNpcStatuses)
            if ($npc['STATUS'] !== 'ATTACK') {
                $npc['STATUS'] = 'ATTACK';
                unset($npc['ATTACK_TIME']);
            }

            $state = world_log($state, "NPC {$npc_id}: атакует {$player_id} при пересечении зоны на клетке ({$x},{$y})");

            // ★ Единая цепочка урона (щит→HP→death→interrupt move внутри combat)
            $res = combat_apply_damage($state, 'player', $player_id, $damage,
                                       ['type' => 'turret', 'id' => $npc_id]);

            $log_msg = "NPC {$npc_id}: {$player_id} получил {$damage} урона";
            if ($res['shield_absorbed'] > 0) $log_msg .= ", щит поглотил {$res['shield_absorbed']}";
            if ($res['dealt'] > 0) $log_msg .= ", HP теперь: {$state['players'][$player_id]['HITPOINTS']}";
            $state = world_log($state, $log_msg);

            if ($res['killed']) {
                $state = world_log($state, "NPC {$npc_id}: убил {$player_id}!");
            }
        } else {
            // Предсказание: смоделировать урон локально (без emit-цепочки),
            // чтобы фаза 1 могла решить — остановит ли смерть спрайт на этой клетке.
            $sim_hp = max(0, $state['players'][$player_id]['HITPOINTS'] - $damage);
            if ($sim_hp === 0) $state['players'][$player_id]['STATUS'] = 'DEATH';
        }

        $results[] = ['npc' => $npc_id, 'damage' => $damage];
    }
    unset($npc);

    return $results;
}

/**
 * ★ Пересчёт зон угрозы всех живых NPC (вызывается из turn_tick после фазы
 * движения и на старте мира из init.php). LOS зависит от позиций игроков,
 * поэтому кэш THREAT_ZONE обязан обновляться каждый тик — иначе турель
 * продолжает «видеть» пустые клетки (анимация атаки по мёртвому/пустому) или
 * теряет реально вошедших в зону целей.
 *
 * ★ Мультикомнатность: карта комнаты NPC грузится лениво (room_get), но в
 * state['rooms'] её может ещё не быть (игрок ещё не заходил). Поместим карту
 * в состояние ПЕРЕД расчётом — иначе engine_active_room вернёт null, LOS
 * сочтёт все клетки непроходимыми и зона угрозы окажется пустой до первого
 * входа игрока в комнату («турель молчит, пока в неё не зайдёшь»).
 */
function objects_refresh_threat_zones(&$state) {
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH'], true)) continue;
        $npc_room = $npc['ROOM_ID'] ?? null;
        if ($npc_room !== null && $npc_room !== '' && !isset($state['rooms'][$npc_room])) {
            $card = room_get($state, $npc_room);
            if ($card !== null) $state['rooms'][$npc_room] = $card;
        }
        $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
    }
    unset($npc);
}

/** Статусы анимаций NPC: ATTACK → IDLE через 1 сек (как resetAttackStatus для игроков) */
function objects_update_npc_statuses(&$state) {
    $now = time();
    foreach ($state['npcs'] as $nid => &$npc) {
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH'], true)) continue;
        if ($npc['STATUS'] === 'ATTACK') {
            if (!isset($npc['ATTACK_TIME'])) {
                $npc['ATTACK_TIME'] = $now;
            } elseif ($now - $npc['ATTACK_TIME'] >= ATTACK_RESET_SECONDS) {
                $npc['STATUS'] = 'IDLE';
                unset($npc['ATTACK_TIME']);
            }
        }
    }
    unset($npc);
}

/** Восстановление ACTIONS у NPC на начале хода (было в items.processTurnStart) */
function objects_restore_npc_actions(&$state) {
    foreach ($state['npcs'] as $nid => &$npc) {
        if (in_array($npc['STATUS'], ['DESTROYED', 'DEATH'], true)) continue;
        $npc['ACTIONS'] = $npc['MAX_ACTIONS'];
    }
    unset($npc);
}

/** Слушатель turn.start: восстановить действия NPC + проверить стоящих игроков */
function objects_on_turn_start(&$state, $payload) {
    objects_restore_npc_actions($state);
    foreach ($state['players'] as $pid => $player) {
        if (in_array($player['STATUS'], ['DEATH', 'MOVING'], true)) continue;
        $pos = parseXY($player['CUR_POSITION_XY']);
        objects_check_threat_zone($state, $pid, $pos['x'], $pos['y']);
    }
}

/**
 * Обработка ИИ NPC — оставлена пустой для обратной совместимости
 * (основная логика живёт в objects_check_threat_zone).
 */
function processNPCs(&$state) {}

/**
 * ============================================================================
 * РАЗДЕЛ 2: Ловушки
 * ============================================================================
 */

/**
 * Срабатывание ловушки при входе игрока на её клетку.
 * Эффекты → effects_apply (немедленная активация cc — через шину),
 * урон → combat_apply_damage (прерывание MOVING теперь работает и здесь —
 * раньше trap-клон урона его не делал).
 */
function processTraps(&$state, $player_id, $x, $y, $opts = []) {
    $dry_run = !empty($opts['dry_run']);
    $player = $state['players'][$player_id] ?? null;
    if (!$player) return ['triggered' => false];
    $ally = $player['ALLY'];
    // ★ Комнатная изоляция: мина «видит» только игрока своей комнаты —
    // координатные сетки комнат совпадают, без фильтра игрок из A1 взводил бы
    // ловушку, лежащую в B1.
    $player_room = $player['ROOM_ID'] ?? null;

    foreach ($state['traps'] as $tid => &$trap) {
        if ($trap['STATUS'] !== 'INTACT') continue;
        if (($trap['ROOM_ID'] ?? null) !== $player_room) continue;

        if ($trap['ALLY'] !== 'NONE') {
            if ($trap['ALLY'] === $player_id || $trap['ALLY'] === $ally) continue;
        }

        $tpos = parseXY($trap['POSITION_XY']);
        if ($tpos['x'] !== $x || $tpos['y'] !== $y) continue;

        if ($dry_run) {
            // Только факт наличия ловушки на пути — без мутаций мира
            unset($trap);
            return ['triggered' => true, 'trap' => $tid];
        }

        $state = world_log($state, "TRAP: {$player_id} наступил на ловушку {$tid} на клетке ({$x},{$y})");
        $trap['STATUS'] = 'TRIGGERED';

        if (isset($trap['EFFECT']) && is_array($trap['EFFECT'])) {
            foreach ($trap['EFFECT'] as $effect) {
                effects_apply($state, 'player', $player_id, $effect);
            }
        }

        if (isset($trap['DAMAGE']) && $trap['DAMAGE'] > 0) {
            $res = combat_apply_damage($state, 'player', $player_id, $trap['DAMAGE'],
                                       ['type' => 'trap', 'id' => $tid]);
            $state = world_log($state, "TRAP: {$tid} -> {$player_id}: урон {$trap['DAMAGE']}"
                . ($res['shield_absorbed'] > 0 ? ", щит поглотил {$res['shield_absorbed']}" : '')
                . ", HP: {$state['players'][$player_id]['HITPOINTS']}");
        }

        emit($state, 'trap.triggered', ['trap_id' => $tid, 'player_id' => $player_id, 'x' => $x, 'y' => $y]);
        unset($trap);
        return ['triggered' => true, 'trap' => $tid];
    }
    unset($trap);

    return ['triggered' => false];
}

/** Регистрация слушателей */
function objects_register_hooks() {
    hook('turn.start', 'objects_on_turn_start', 20); // после passives/effects
}
