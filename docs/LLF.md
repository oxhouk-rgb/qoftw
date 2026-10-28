# LLF.md — List of Low-Level Functions (индекс для LLM)

> Автогенерируемый индекс: имя функции → файл:строка → назначение.
> Обновлять после правок: `bash docs/gen_llf.sh`
> Не читать этот файл целиком при задаче с одной функцией — делать grep по нему:
> `grep -n "имя_функции" docs/LLF.md` → затем читать код по указанному file:line.


## Клиент (js/animation.js, js/config.js, js/grid.js, js/input.js, js/main.js, js/net.js, js/path.js, js/render.js, js/state.js, js/utils.js)

### `js/animation.js` (6 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `previewTurn` | :13 | Мгновенный «превью» поворота собственного игрока при клике Attack/Ability. |
| `applyTurnClass` | :30 | Применить отражение спрайта по направлению TURN. |
| `updatePlayerEntity` | :38 | Обновить существующий элемент игрока |
| `startMoveAnimation` | :101 | ★ Запустить анимацию перемещения для игрока (интерполяция по СЕРВЕРНЫМ часам). |
| `animateMove` | :130 | Анимация перемещения (вызывается каждый кадр). |
| `catchUpMoveEnd` | :234 | ★ Мягкая дозапись позиции бойца, чья анимация была досрочно снята render-циклом |

### `js/grid.js` (5 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `buildGrid` | :9 | Построить HTML сетку игрового поля |
| `rebuildGrid` | :37 | ★ Перестроение поля при СМЕНЕ комнаты: то же, что buildGrid(), но вызывается |
| `findEntity` | :48 | ★ Найти DOM-элемент сущности СТРОГО внутри текущей видимой сетки. |
| `inCurrentRoom` | :66 | ★ Комнатная изоляция клиентских проверок (см. state.currentRoomId). |
| `purgeOutOfViewRoomEntities` | :87 | ★ КОМНАТНАЯ ИЗОЛЯЦИЯ ОТРИСОВКИ (корень бага «турель/мина видны во всех |

### `js/input.js` (4 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `onCellClick` | :7 | Обработчик клика по клетке |
| `checkAvailableActions` | :43 | Проверка наличия доступных действий для клетки |
| `showActionDialog` | :72 | Показать диалог действий для клетки |
| `closeDialog` | :182 | Закрыть диалог |

### `js/net.js` (6 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `initGame` | :7 | Инициализация игры - вызов init.php и начало polling |
| `syncClock` | :85 | ★ Синхронизация часов клиента с серверными (лечит дёрганье/телепорт движения). |
| `startPolling` | :111 | Запуск цикла long polling |
| `poll` | :120 | Один запрос long polling |
| `nextActionId` | :173 |  |
| `sendAction` | :197 | Отправить действие на сервер с retry логикой. |

### `js/path.js` (3 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `isPathClear` | :9 | Проверка прямой линии между двумя точками (нет ли препятствий) |
| `isCellFreeForMove` | :87 | Проверка свободна ли клетка для перемещения |
| `hasLineOfSight` | :142 | Проверка линии видимости для способностей (может проходить через пустые клетки) |

### `js/render.js` (13 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `render` | :7 | Отрисовка состояния |
| `highlightPlayerZones` | :22 | Подсветка зон вокруг игрока |
| `renderGrid` | :84 | Отрисовка сетки с сущностями |
| `createPlayerEntity` | :496 | Создать элемент игрока |
| `createObjectEntity` | :555 | Создать элемент объекта |
| `createTrapEntity` | :565 | Создать элемент ловушки |
| `createNpcEntity` | :575 | Создать элемент NPC |
| `renderPlayerInfo` | :595 | Отрисовка информации о текущем игроке |
| `renderLog` | :624 | Отрисовка лога |
| `renderAbilities` | :645 | Отрисовка списка способностей |
| `renderProjectiles` | :685 | Отрисовка снарядов |
| `renderTimer` | :722 | Отрисовка таймера хода |
| `checkVictory` | :732 | Проверка победы |

### `js/state.js` (2 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `serverTimeSec` | :36 | ★ Перевод локальных часов (performance.now, мс) в серверные (unix, сек) — |
| `effectiveMoveRange` | :48 | ★ Пункт плана №4: эффективная дальность хода со всеми модификаторами. |

### `js/utils.js` (4 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `showNotification` | :7 | Показать ненавязчивое уведомление |
| `parseXY` | :74 | Парсить координаты "X,Y" -> {x, y} |
| `parseAbilityName` | :82 | Получить название способности из строки "FIRE WAVE[3]" |
| `parseAbilityRange` | :89 | Получить дальность способности из строки "FIRE WAVE[3]" |


## HTTP-слой (php/*.php)

### `php/battle.php` (23 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `processMove` | :19 | ─── Совместимость: прежние имена функций → новые модули ─── |
| `processAttack` | :23 |  |
| `processTurnStart` | :27 |  |
| `updateMovements` | :31 |  |
| `updateDeathAnimations` | :35 |  |
| `updateProjectiles` | :39 |  |
| `resetAttackStatus` | :43 |  |
| `updateNpcStatuses` | :47 |  |
| `checkVictory` | :51 |  |
| `processNegatives` | :62 |  |
| `applyNegativeEffect` | :68 |  |
| `checkThreatZoneIntersection` | :74 |  |
| `hasLineOfSightNPC` | :80 |  |
| `findTargetAtPosition` | :88 |  |
| `getTargetsOnLine` | :94 |  |
| `getTargetsInArea` | :100 |  |
| `isCellPassable` | :106 |  |
| `applyDamageToTarget` | :112 |  |
| `applySplashDamage` | :118 |  |
| `applyInstantAbility` | :128 |  |
| `applyBeamAbility` | :134 |  |
| `applyLineAbility` | :140 |  |
| `applyAreaAbility` | :146 |  |

### `php/config.php` (16 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `redisConnect` | :52 |  |
| `isRedisConnected` | :76 |  |
| `getRedisHandle` | :82 |  |
| `getRedis` | :95 |  |
| `closeRedis` | :103 |  |
| `getBattleState` | :118 |  |
| `saveBattleState` | :168 |  |
| `addLog` | :192 |  |
| `parseXY` | :210 |  |
| `formatXY` | :218 |  |
| `isValidPosition` | :230 |  |
| `setCorsHeaders` | :237 |  |
| `jsonResponse` | :247 |  |
| `jsonError` | :260 |  |
| `clearOutputBuffers` | :270 |  |
| `sendJsonErrorFallback` | :278 |  |

### `php/poll.php` (1 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `build_poll_response` | :116 | Сборка flat-ответа poll (контракт клиента js/game.js render(data)). |


## Ядро движка (php/engine/)

### `php/engine/bootstrap.php` (1 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `engine_check_victory` | :50 | Проверка победы: все игроки одной стороны мертвы/удалены (кроме последнего). |

### `php/engine/events.php` (6 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `hook` | :60 | Зарегистрировать слушателя события. |
| `hooksReset` | :72 |  |
| `_hook_listeners` | :78 |  |
| `emit` | :94 | Эмитировать fact-событие. |
| `emitReduce` | :105 | Эмитировать query/reduser-событие: каждый слушатель объявляет второй |
| `call_user_func_ref` | :117 | Вызов callable с передачей 2-го аргумента по ссылке без знания сигнатуры |

### `php/engine/geometry.php` (9 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `parseXY` | :13 |  |
| `formatXY` | :19 |  |
| `isValidPosition` | :27 |  |
| `manhattan` | :32 |  |
| `inRadius` | :37 |  |
| `calculatePath` | :45 | Путь по прямой от A до B (без начальной клетки, с конечной). |
| `adjacentCells` | :67 |  |
| `cellsInRadius` | :86 | Клетки в манхэттен-радиусе от центра (включая центр), только валидные. |
| `directionStep` | :102 |  |

### `php/engine/rules.php` (2 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `rules_turn_formula` | :59 | Серверная формула направления спрайта TURN (только лево/право): |
| `rules_client_payload` | :68 | Пакет правил для отправки клиенту (поле "rules" в ответах init/poll). |

### `php/engine/turn.php` (9 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `turn_start` | :26 | Начало хода: восстановление ACTIONS/щита + эмит turn.start. |
| `turn_effects_tick` | :64 | Слушатель turn.start (priority 5): прогон эффектов всех живых игроков. |
| `turn_sync_status_after_effect` | :84 | ★ Инвариант «статус = NEGATIVES» (слушатель effect.applied, priority 10 — |
| `turn_tick` | :113 | Один серверный тик: все анимационно-временные обновления + ход мира. |
| `turn_check_advance` | :186 | Проверить/вызвать начало нового хода (если прошло TURN_DURATION). |
| `turn_update_death_animations` | :215 | ★ Фазы DEATH/DECAY: переход на респаун в Личную SPAWN_ROOM. |
| `turn_update_projectiles` | :265 |  |
| `turn_reset_attack_status` | :287 |  |
| `turn_register_hooks` | :302 |  |

### `php/engine/world.php` (7 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `world_load` | :36 | Загрузить состояние мира из Redis. |
| `world_save` | :58 | Сохранить состояние мира в Redis + инкрементировать версию (единая точка!). |
| `world_init` | :83 | Инициализировать мир заново из данных комнаты/бойцов (используется init.php). |
| `world_log` | :99 | Добавить запись в журнал боя (журнал — читаемые события, не трассировка). |
| `world_version` | :107 | Текущая версия мира (для long-poll цикла). Устойчив к закрытому соединению: |
| `world_action_ack_get` | :140 | Проверить, применено ли действие с таким action_id ранее. |
| `world_action_ack_set` | :157 | Записать подтверждение применённого действия (после успешного world_save). |


## Системы механик (php/systems/)

### `php/systems/abilities.php` (6 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `getAbilityDefinitions` | :23 | Реестр всех способностей (формат прежний — см. шапку). |
| `applyAbility` | :98 | Применить способность (валидация дальности из строки игрока "NAME[range]", |
| `ability_hit_targets` | :161 |  |
| `ability_apply_instant` | :178 |  |
| `ability_apply_beam` | :223 |  |
| `ability_apply_area` | :238 |  |

### `php/systems/combat.php` (6 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `calculateDamage` | :34 | Формула урона: base (мин 1), сопротивление −10%/очко (кэп 90%), ceil, мин 1. |
| `combat_apply_damage` | :51 | ★ Единая функция применения урона любой сущности. |
| `combat_on_damage_dealt` | :145 | (округлённой по серверным часам) клетке. Раньше это было встроено в оба |
| `action_validate` | :167 | ★ Единая точка валидации глагола перед любым действием (move/attack/ability). |
| `combat_process_attack` | :202 | Базовая линейная атака игрока (processAttack из battle.php). |
| `combat_register_hooks` | :317 |  |

### `php/systems/effects.php` (10 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `getEffectDefinitions` | :40 | Реестр определений эффектов — ЕДИНСТВЕННЫЙ список семантики статусов. |
| `effects_parse` | :78 |  |
| `effects_format` | :86 |  |
| `effects_has` | :91 |  |
| `effects_blocks` | :105 | Блокирует ли набор NEGATIVES игрока указанный глагол? |
| `effects_get_modifier` | :122 | Суммарный модификатор стата от всех NEGATIVES носителя. |
| `effects_apply` | :142 | Наложить эффект (или продлить до max длительности). Единственный способ |
| `effects_on_applied` | :182 | Слушатель 'effect.applied': немедленная активация cc-эффектов с immediate=true. |
| `effects_tick` | :202 | Прогон NEGATIVES носителя на начале хода (turn.start): |
| `effects_register_hooks` | :273 |  |

### `php/systems/movement.php` (10 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `engine_active_room` | :33 | ★ Карта, в которой происходит действие: state['room'] (единая карта legacy) |
| `movement_effective_range` | :47 | ★ Эффективная дальность хода игрока: базовая MAX_MOVE_DISTANCE со всеми |
| `movement_process_move` | :59 | Начать перемещение игрока (валидация + предсказание пути + очередь анимации). |
| `movement_interrupt` | :177 | Прервать движение игрока на текущей (промежуточной, округлённой по серверным |
| `makeTrapPreviewState` | :208 | Черновое копийство состояния для предсказания ловушек/NPC в phase 1. |
| `movement_update` | :226 | ★ ФАЗА 2: тик движения (вызывается каждый server tick из engine/turn.php). |
| `movement_update_step` | :252 |  |
| `movement_on_query_block` | :352 |  |
| `movement_finalize_start` | :375 | ★ ФАЗА 2-инициализация: зафиксировать старт движения в состоянии. |
| `movement_register_hooks` | :383 |  |

### `php/systems/objects.php` (11 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `getAutonomousObjectDefinitions` | :24 |  |
| `objects_has_line_of_sight` | :49 | Линия видимости для NPC: прямые (гориз/верт) и диагонали (Г-образная проверка). |
| `calculateThreatZone` | :100 | Зона угрозы NPC: клетки в манхэттен-RANGE с линией видимости. |
| `objects_check_threat_zone` | :133 | Проверить пересечение игрока с зонами угрозы всех NPC. |
| `objects_refresh_threat_zones` | :216 | ★ Пересчёт зон угрозы всех живых NPC (вызывается из turn_tick после фазы |
| `objects_update_npc_statuses` | :230 |  |
| `objects_restore_npc_actions` | :247 |  |
| `objects_on_turn_start` | :256 |  |
| `processNPCs` | :269 | Обработка ИИ NPC — оставлена пустой для обратной совместимости |
| `processTraps` | :283 | Срабатывание ловушки при входе игрока на её клетку. |
| `objects_register_hooks` | :337 |  |

### `php/systems/passives.php` (12 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `getPassiveDefinitions` | :33 | Реестр пассивок. value — числовое значение эффекта. |
| `hasPassive` | :73 |  |
| `getPassiveValue` | :78 |  |
| `combat_should_pierce_resistance` | :91 | ★ Query стиля атаки (как hasPassive(PUNCTURE)): должен ли атакующий-игрок |
| `applyPassiveDefense` | :104 | Модификация входящего урона пассивками (старое имя сохранено как обёртка — |
| `passives_on_mitigate` | :117 | Слушатель damage.query_mitigate (by-ref!): переработанная ARMORED — |
| `passives_on_damage_dealt` | :132 | Слушатель damage.dealt: THORNS — жертва-игрок бьёт атакующего-игрока во |
| `passives_on_query_distance` | :155 | Слушатель movement.query_distance (by-ref): SWIFT добавляет +value к |
| `passives_effects_move_modifier` | :164 |  |
| `passives_on_turn_start` | :176 | Слушатель REGENERATION на turn.start: +value HP (кэп MAX_HITPOINTS). |
| `passives_on_attack_resolved` | :200 | ★ SPLASH — слушатель attack.resolved. Полностью переписан: вместо цикла |
| `passives_register_hooks` | :249 |  |

### `php/systems/rooms.php` (10 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `room_get` | :37 | Карта комнаты из состояния (ленивая загрузка из data/<ID>.json). |
| `room_of_player` | :53 | Комната, в которой находится игрок (или null для мёртвых/удалённых — |
| `room_cell_blocked` | :67 | Одна проверка проходимости клетки в конкретной комнате: стена (grid==0), |
| `room_candidate_cells` | :96 | Клетки-кандидаты для появления рядом с дверью, упорядоченные «от лучшей к |
| `room_find_spawn_cell` | :134 | ★ Ближайшая СВОБОДНАЯ клетка появления рядом с дверью (защита от body-block: |
| `room_defined_spawn_cell` | :157 | ★ Выделенная СПАВН-КЛЕТКА игрока в комнате — берётся НЕ из позиции смерти |
| `room_player_spawn_cell` | :189 | ★ Личная СПАВН-КЛЕТКА бойца в его SPAWN_ROOM — конкретная ВЫДЕЛЕННАЯ клетка, |
| `rooms_teleport` | :266 | ★ Перенос игрока в другую комнату (аналог срабатывания exit; используется и |
| `rooms_check_exit_trigger` | :390 | ★ Триггер exit: вызывается когда игрок оказался на клетке с exit |
| `rooms_register_hooks` | :403 |  |

### `php/systems/targeting.php` (8 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `resolveEntity` | :25 | Универсальный снимок сущности. Возвращает null если сущность мертва/удалена. |
| `all_entities` | :79 |  |
| `hostile_to` | :103 | Враждебна ли сущность $entity кастеру $caster_id (игрок)? |
| `find_target_at_position` | :119 |  |
| `get_targets_on_line` | :136 | Все враждебные цели на прямой от (x1,y1) до (x2,y2), луч останавливается |
| `get_targets_in_area` | :159 |  |
| `is_player_at_position` | :185 | Есть ли игрок на клетке (занятость для движения). Учитывает MOVING: |
| `is_cell_passable` | :212 | Проходимость клетки: стена (grid==0), неразрушенный объект или живой NPC |


## Тесты (tests/)

### `tests/harness_engine.php` (7 фукн.)

| Функция | Строка | Назначение |
|---|---|---|
| `t_assert` | :85 |  |
| `harness_build_state` | :96 |  |
| `harness_room_loaded` | :133 | Проверка «комната оседла в состоянии»: карта комнаты может лежать либо |
| `test_exit_transition` | :140 | ------------------------------------------------------------------ T1 |
| `test_respawn_via_teleport` | :176 | ------------------------------------------------------------------ T2 |
| `test_poll_room_filtering` | :213 | poll.php — HTTP-эндпоинт: при require сразу исполняет long-poll цикл и |
| `test_crossroom_targeting` | :252 | T4. Комнатная изоляция целей (regression бага «атака пустой клетки задевает |


<!-- Всего функций: 197 -->
