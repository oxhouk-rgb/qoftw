# ARCH.md — Архитектура проекта (справочник для LLM и разработчиков)

> **Назначение файла**: краткое, но полное описание **текущей** архитектуры проекта и
> зависимостей между модулями. Читать **перед любыми важными изменениями**, чтобы не сломать
> существующие механики и договорённости кода.
> **Правило поддержки**: если в архитектуре что-то изменилось (новый модуль/эндпоинт/ключ Redis/
> зависимость между файлами/протокол обмена) — этот файл обязан быть обновлён в том же коммите.
> Идеология и смысл проекта — в `ABOUT.md`, справочник по запуску/API — в `README.md`.
> Здесь — «скелет»: компоненты, потоки данных, контракты и точки расширения.

---

## 1. Стеки и глобальные ограничения (не менять без решения автора)

- **Бэкенд**: чистый PHP 7.4+ (без фреймворков) + **Redis** (расширение phpredis). Вся игровая
  логика и все расчёты — только на сервере.
- **Фронтенд**: статические HTML/CSS/vanilla JS. Роли JS строго три: *polling*, *рендер*, *ввод*.
- **Табу**: ❌ React/Vue/Angular, ❌ Node.js/npm/сборщики, ❌ логика игры на клиенте,
  ❌ WebSocket/SSE вместо long polling.
- Состояние боя живёт **только в Redis** под префиксом `battle:`; файловое состояние отсутствует.
- `index.html` + `assets/` — **relics песочничной React-сборки, игрой не используются**. Живой
  клиент — `game.html` + `js/game.js` + `css/game.css`. Не путать и не тянуть из них код.

## 2. Карта компонентов

Архитектура двухслойная: тонкие HTTP-эндпоинты → движок (`engine/` ядро + `systems/` механики),
связанные событийной шиной. Стек не менялся: чистый PHP + Redis + vanilla JS + long polling.

```
Клиент (браузер, вкладка = игрок)              Сервер (PHP-FPM/Apache/Nginx)         Хранилище
┌───────────────────────────────┐   GET  ┌──────────────────────────────────┐
│ game.html  — точка входа       │ ─────▶ │ init.php   — world_init из       │ ──flushDB──┐
│ js/game.js — poll/render/input │        │            data/*.json (тонкий)  │           ▼
│ css/game.css — визуал/анимации │   POST ├──────────────────────────────────┤      ┌──────────┐
│ diag.html  — диагностика       │ ─────▶ │ action.php — валидация +         │      │  Redis   │
└───────────────────────────────┘        │            роутинг move/attack/  │      │ battle:* │
        ▲                                │            ability (тонкий)      │      └──────────┘
        │ GET poll.php?v=N&player=ID     ├──────────────────────────────────┤           ▲
        └─────────────────────────────── │ poll.php — long-poll + server    │           │
                                         │            clock (turn_tick)     │           │
                                         ├──────────────────────────────────┤           │
                                         │ engine/bootstrap.php — ТОЧКА      │           │
                                         │  СБОРКИ: require систем + hook()  │           │
                                         │  ├── engine/rules.php   (константы│           │
                                         │  ├── engine/geometry.php (коорд.) │           │
                                         │  ├── engine/events.php  (шина)    │           │
                                         │  ├── engine/world.php ────────────┼───────────┘
                                         │  │    (единственный владелец Redis)│
                                         │  ├── engine/turn.php   (оркестрац.│
                                         │  │    тика хода)                   │
                                         │  └── systems/*  (механики, §ниже) │
                                         ├──────────────────────────────────┤
                                         │ battle.php — ФАСАД обратной       │
                                         │  совместимости (legacy-имена →    │
                                         │  делегаты в engine/systems)       │
                                         │ config.php — Redis-коннект +      │
                                         │  JSON-хелперы (константы убраны)  │
                                         │ debug.php  — диагностика движка   │
                                         └──────────────────────────────────┘
Данные (статические): data/battledata.json (игроки/объекты/ловушки/NPC), data/B1.json (карта 16×12)
```

### PHP-модули: HTTP-слой (`php/*.php`)

| Файл | Роль | Ключевые функции |
|------|------|------------------|
| `config.php` | Только инфраструктура: подключение Redis, JSON-ответы, логи. **Игровые константы удалены отсюда** (владелец — `engine/rules.php`); функции `parseXY/formatXY/isValidPosition` обёрнуты в `function_exists` (владелец — `engine/geometry.php`) | `getRedis()`, `addLog()`, `jsonResponse()/jsonError()`, `setCorsHeaders()` |
| `init.php` | Тонкий эндпоинт: читает `data/battledata.json` + `data/B1.json` (комната из `ROOM_ID`), считает `THREAT_ZONE` NPC, вызывает `world_init()`; в ответе отдаёт клиенту `rules` + реестры abilities/passives/effects | вызывается клиентом при выборе персонажа |
| `poll.php` | Тонкий эндпоинт: `set_time_limit`, `turn_tick($state)` для ЛЮБОГО запроса (не только с `?player=`), `turn_check_advance`, long-poll по версии; без изменений — полный snapshot; в ответе поле `rules`. Ответ — плоский JSON (без обёртки `data`) | `require bootstrap.php` |
| `action.php` | Тонкий эндпоинт: предварительная валидация (жив ли, не заблокирован ли `effects_blocks()`, есть ли способность/AP) → роутинг `move/attack/ability` в systems; ошибки → JSON 400, не fatal | `require bootstrap.php` |
| `battle.php` | **Фасад обратной совместимости**: подключает `engine/rules.php` + `bootstrap.php`, содержит делегаты со всеми старыми именами (`processMove`, `processAttack`, `updateMovements`, `checkVictory`, …). Новый код здесь не писать | legacy-алиасы |
| `debug.php` | Диагностика новой структуры: PHP/Redis, наличие engine/+systems/, `php -l` модулей (если CLI доступен), загрузка фасада, вывод реестров, тест `world_init → world_load → turn_tick`. ⚠️ делает `flushDB` | standalone-страница |

### PHP-модули: ядро движка (`php/engine/`)

| Файл | Роль | Ключевые функции |
|------|------|------------------|
| `rules.php` | **Единый источник констант мира** (GRID 16×12, MAX_MOVE_DISTANCE=5, MOVE_SPEED=3, TURN_DURATION=5, SHIELD_REGEN_PER_TURN=1, LONG_POLL_TIMEOUT=25, тайминги анимаций) через guard `if (!defined(...)) define(...)` — порядок загрузки файлов не может уронить процесс | `rules_turn_formula()` (канон направления спрайта TURN), `rules_client_payload()` (правила в ответах init/poll) |
| `geometry.php` | Чистые функции координат, без состояния | `parseXY/formatXY/isValidPosition/manhattan/inRadius/calculatePath/adjacentCells/cellsInRadius/directionStep` |
| `events.php` | Шина событий: `hook(event, fn, priority)` с детерминированным порядком, `emit()` (fact-события), `emitReduce()` (query/reduser-события; **payload передаётся по ссылке** — слушатели мутируют оригинал, а не копию). Канонический список событий — в шапке файла | `hook()`, `emit()`, `emitReduce()` |
| `world.php` | **Единственный владелец хранилища**: все чтения/записи Redis идут через него; инкремент `REDIS_VERSION` централизован в `world_save()`; дедупликация записей | `world_load/world_save/world_init/world_log/world_version`; путь к данным — `dirname(__DIR__, 2) . '/data/battledata.json'` |
| `turn.php` | Оркестратор тика хода: movement → death chain → projectiles → attack reset → npc statuses → effects tick; самовосстанавливающийся инвариант STATUS↔NEGATIVES (снятие истёкших дебаффов, в т.ч. STUNNED); защита от `turn_time=0` | `turn_tick()` (вызывается каждым poll-запросом), `turn_check_advance()`, `turn_start()` (ACTIONS/shield regen + emit `turn.start`) |
| `bootstrap.php` | Точка сборки: require всех систем + регистрация слушателей в фиксированном порядке (combat → passives → effects → movement → objects → turn) + `engine_check_victory` | `*::register_hooks()` вызываются отсюда |

### PHP-модули: механики (`php/systems/`)

| Файл | Роль | Ключевые элементы |
|------|------|-------------------|
| `effects.php` | **Реестр статус-эффектов** (STUNNED, BLEED и т.д.) с kind'ами `dot / cc / modifier`; длительность `[N]` ходов из NEGATIVES теперь реально тикает и снимается | `effects_apply/tick/blocks/get_modifier`; немедленная активация CC через слушатель `effect.applied`; никто больше не парсит NEGATIVES строками в сторонних файлах |
| `combat.php` | Единая цепочка урона: mitigate-query (сопротивление, ARMORED/ARMOR_CRUSH) → щит → HP → DEATH | `combat_apply_damage()` (PUNCTURE, остановка движения только при реальном уроне), `processAttack` на её базе (TURN по формуле, SPLASH через событие) |
| `movement.php` | Движение: две фазы сохранены (предсказание на черновом копийстве `makeTrapPreviewState` vs серверное применение `updateMovements`); блокировки/дальность — query-события | слушатели `movement.query_block` (CC), `movement.query_distance` (SWIFT/SLOWED); «догоняющие тики» на виртуальных часах мира |
| `passives.php` | Реестр пассивок как слушатели шины (логика НЕ размазана по файлам) | ARMORED→`damage.query_mitigate`, THORNS→`damage.dealt`, SWIFT/SLOWED→`movement.query_distance`, REGENERATION→`turn.start`, SPLASH→`attack.resolved` (один проход по живым врагам); **ARMOR_CRUSH**→`damage.query_mitigate` атакующего: игнорирует всё сопротивление цели, но не действует, если у носителя есть ARMORED (`combat_should_pierce_resistance()`); переработанная ARMORED снижает входящий урон вдвое **только от атак с ARMOR_CRUSH** |
| `targeting.php` | Универсальный доступ к сущностям | `resolveEntity/hostile_to/all_entities`; AoE бьёт игроков, NPC и объекты |
| `abilities.php` | Реестр способностей (формат данных не менялся) + исполнение через единые сервисы combat/movement/effects | `getAbilityDefinitions`, `applyAbility` |
| `objects.php` | NPC (зоны угрозы, LOS — пересчёт каждый ход) и ловушки; урон через единую цепочку `combat_apply_damage`; жёсткий фильтр целей: DEATH/DECAY не атакуется | `getAutonomousObjectDefinitions`, `calculateThreatZone`, `hasLineOfSightNPC`, слушатель `turn.start` (actions reset + threat) |

> Legacy-файлы `abilities.php.old`, `items.php.old`, `objects.php.old` сохранены как reference
> до подтверждения смоук-тестом; в require-графе не участвуют.

### Клиент (`js/game.js`, ~1360 строк, vanilla JS)

- `CONFIG.PHP_URL = 'php/'`, сетка 16×12, `CELL_SIZE=40` (дублирует серверные константы — см. §7).
- Жизненный цикл: выбор PLAYER* → `initGame()` (fetch `init.php`) → `startPolling()` → бесконечный
  цикл `poll()` (`poll.php?v=<version>&player=<ID>`, следующий запрос через 100 мс после ответа,
  retry через 2 c при ошибке).
- Ввод: клик по клетке (`onCellClick`) открывает диалог действий (`showActionDialog`), кнопки
  способностей показываются по дальности; отправка — `sendAction()` (retry ×3 на 5xx/parse/network).
- Рендер: `render(data)` — полный снимок → сетка, сущности, снаряды, UI (HP/щит/ACTIONS, таймер
  хода, лог, победа). Плавное движение — клиентская интерполяция между `START/END_POSITION_XY`
  по `MOVE_START_TIME/MOVE_END_TIME` (авторитет — серверное время).
- Клиент **не считает правила**; единственная «копия серверной формулы» — `previewTurn()`
  (мгновенное зеркаление спрайта до ответа poll; должна совпадать с battle.php/action.php).
- Локальные проверки пути (`isPathClear`, `isCellFreeForMove`, `hasLineOfSight`) — только для UX
  (подсветка/скрытие недоступных действий); финальная валидация всегда серверная.

### Данные (`data/`)

- `battledata.json`: ключи `players`, `objects`, `traps`, `npcs`. Поля игроков — CAPS
  (наследие ТЗ): `HITPOINTS/MAX_HITPOINTS`, `SHIELD/MAX_SHIELD`, `ATTACK`, `RESISTANCE`,
  `ACTIONS/MAX_ACTIONS`, `RANGE`, `ABILITIES: ["ИМЯ[N]"]` (**N — дальность, не стоимость!**),
  `PASSIVES`, `NEGATIVES: ["ЭФФЕКТ[ходов]"]`, `ALLY`, `LIFE`, `SPAWN_ROOM`, `TURN`, `COLOR`,
  `START/CUR/END_POSITION_XY` (строка `"x,y"`).
- `B1.json`: `room_id`, `width/height`, `grid` (маска 0=проход/1=стена), `exits`, `stairs`
  (переход между комнатами — **заглушка**, только логируется в `updateMovements`).

## 3. Зависимости между PHP-модулями (граф require_once + событийная шина)

```
init.php ─┐                                  config.php (только Redis-коннект,
poll.php ─┼─▶ engine/bootstrap.php           JSON-хелперы, логи) ◀─ engine/world.php
action.php┘        │  (единственная точка     debug.php — свой путь диагностики
battle.php(фасад)──┤   сборки: require +      php/engine/: rules → geometry → events → world → turn
                   │   hook-регистрации)      php/systems/: effects, targeting, passives,
                   ▼                          movement, combat, abilities, objects
        все системы и engine/* подключаются bootstrap'ом; каждая система сама
        тянет свои прямые зависимости (events/world/geometry/effects/targeting)
        через require_once — граф ацикличен, циклов вида items↔objects больше нет
```

Связь между механиками — **не прямыми вызовами**, а шиной событий (`engine/events.php`).
Канонический список событий (из шапки events.php и bootstrap.php):

| Событие | Вид | Кто эмитит | Кто слушает |
|---|---|---|---|
| `damage.query_mitigate` | query (payload by-ref) | combat_apply_damage | passives (ARMORED ÷2 от ARMOR_CRUSH-атак, ARMOR_CRUSH pierce), combat (RESISTANCE) |
| `damage.dealt` | fact | combat_apply_damage | combat (прерывание MOVING при реальном уроне, p0), passives (THORNS, p10) |
| `attack.resolved` | fact | processAttack | passives (SPLASH) |
| `effect.applied` | fact | effects_apply | effects (немедленная активация CC) |
| `movement.query_block` | query | movement | effects (CC-блокировки) |
| `movement.query_distance` | query | movement | passives (SWIFT/SLOWED) |
| `turn.start` | fact | turn_start | turn (effects tick, p5), combat, passives (REGENERATION), objects (NPC actions reset + threat, p20) |

Правила зависимостей (жесткие):
- Эндпоинты требуют **только** `engine/bootstrap.php` (battle.php — фасад для legacy-имён).
  Не добавляйте прямые `require_once` систем в эндпоинты.
- Регистрация слушателей — **только в bootstrap.php**, фиксированный порядок:
  combat → passives → effects → movement → objects → turn (детерминизм тиков).
- Системы не вызывают друг друга напрямую — только через `emit/emitReduce` или сервисы
  lower layer (combat→effects, movement→effects и т.д. — вниз по слоям можно, «вбок» — только шиной).
- Логика автономных объектов — только в `systems/objects.php`; пассивки — только в
  `systems/passives.php`. Новые spawn-объекты — в `getAutonomousObjectDefinitions()`.
- `emitReduce` передаёт payload **по ссылке** — слушатели обязаны мутировать оригинал
  (иначе статус-эффекты «залипают»; исторический баг, зафиксирован в тестах).

## 4. Поток данных и протокол синхронизации

1. **init**: клиент → `GET php/init.php` → flushDB → загрузка JSON → расчёт THREAT_ZONE →
   `world_init()` (version=1). Каждая новая вкладка перезапускает бой с нуля (осознанно).
2. **poll-loop** (`poll.php?v=<clientVersion>&player=<ID>`): сервер держит соединение ≤25 с
   (`LONG_POLL_TIMEOUT`, `set_time_limit` снят), каждые 0.1 с: `world_load` → `turn_tick($state)`
   (**для любого запроса, даже без `?player=`** — иначе эффекты залипают) → движение по «виртуальным
   часам мира» с догоняющими тиками → death chain → projectiles → attack reset → npc statuses →
   effects tick → при `now-turn_time ≥ TURN_DURATION(5)` новый ход (`turn_check_advance` →
   `turn_start` + emit `turn.start`: passives/effects/combat/objects-слушатели) → `world_save`
   (incr version централизован в world_save; дедуп записей). Если version изменилась, отдаёт
   **полный flat snapshot**
   (`version, rules, room, grid, objects, traps, npcs, players, projectiles, log[-30],
   turn_time, turn_duration, time_to_next_turn, victory`) — КЛЮЧИ ОТВЕТА НА ВЕРХНЕМ УРОВНЕ,
   без обёртки `data` (клиентский `render(data)` читает `data.players` и т.д. напрямую;
   исторический контракт). Иначе по таймауту отдаёт тот же snapshot с `timeout:true`.
3. **action**: клиент → `POST php/action.php` (JSON) → предварительная валидация (жив/не CC/AP) →
   функция systems мутирует `$state` (by-ref) → `world_save` (incr version) →
   все зависшие poll мгновенно отдают новый мир. Ошибки — JSON 400, не fatal.
4. **Контракты**: любой mutate состояния **обязан** закончиться сохранением через `world_save`
   (он сам делает incr версии; прямой `incr` в стороннем коде — антипаттерн). Забыли save →
   клиенты «зависнут на старом мире».

### Ключи Redis (префикс `battle:`)

`players`, `objects`, `traps`, `npcs`, `room`, `turn_time`, `projectiles`, `version`, `log`
(последние 50 записей; клиенту отдаются последние 10). Значения — JSON-строки, version/turn_time — int.

### Конкурентность (важно знать перед правками)

Явных блокировок/транзакций нет: read-modify-write всего состояния из нескольких PHP-процессов
параллельны. Гонки гасятся короткими операциями и тем, что занятость клетки учитывает
`END_POSITION_XY` движущихся игроков (`isPlayerAtPosition`). `action.php` содержит retry-логику
сохранения. Это осознанный минимализм стенда — при изменении модели записи не ухудшайте это.

## 5. Модель мира и ключевые механики (что ломать нельзя)

- **Сетка 16×12** (`GRID_WIDTH/HEIGHT`), координаты строкой `"x,y"`, дробные значения допускаются
  в CUR во время движения (форматирует `formatXY`). Комната B1: стена в столбце 7, выходы `7,0→A1`,
  `7,11→C1` (заглушка).
- **Ход**: каждые `TURN_DURATION=5` с ACTIONS → MAX_ACTIONS (без накопления), щит +1 к максимуму,
  тик NEGATIVES (DoT BLEED/POISONED, счётчики), REGENERATION, восстановление ACTIONS NPC,
  перепроверка зон угрозы для стоящих игроков. Очерёдности нет — real-time со стоимостью 1 AP за
  любое действие. Кулдаунов у способностей нет.
- **Движение**: только по прямой, ≤5 клеток (`MAX_MOVE_DISTANCE=5`, скорость `MOVE_SPEED=3 кл/с`;
  SWIFT +1, SLOWED −2, min 1).
  Двухфазная модель — **предсказание vs применение**: `processMove` прогоняет путь по черновому
  копийству (`makeTrapPreviewState`) и лишь укорачивает END на клетку мины/смертельного NPC;
  реальный урон/STUNNED/DEATH применяются в `updateMovements()` ровно когда серверные часы
  довели игрока до клетки (синхрон с анимацией клиента). Вход на мину мгновенно завершает
  движение на её клетке; STUNNED от ловушки активируется сразу, не дожидаясь хода. Урон прерывает
  движение. Во время MOVING другие действия заблокированы сервером.
- **Атака**: луч по прямой в пределах RANGE (BLINDED → /2); без PUNCTURE останавливается на первом
  враге/препятствии, с PUNCTURE пробивает дальше; стены/бочки блокируют всегда.
- **Формула урона** (единая цепочка `combat_apply_damage` в `systems/combat.php`): база
  `ceil(max(ATTACK,1) × (1 − RESISTANCE×10%))`, RES≤90%, минимум 1 — применяется только если
  атакующий НЕ пробивает сопротивление; **ARMOR_CRUSH** (у атакующего, при отсутствии у него
  ARMORED) игнорирует всё сопротивление цели; **ARMORED** (у цели) снижает входящий урон вдвое
  **только от атак с ARMOR_CRUSH** (на обычные атаки/турели не действует); щит гасит первым;
  реген щита `SHIELD_REGEN_PER_TURN=1`; THORNS/SPLASH — контур и разброс (слушатели шины в
  passives.php). NPC-урон (турель/мина) идёт по той же цепочке без RESISTANCE-редукции цели —
  одинаковый по всем целям. Прерывание MOVING — только при реально нанесённом уроне
  (полностью поглощённый щитом движение не останавливает). Мёртвые (DEATH/DECAY) целями не являются.
- **Способности**: 4 механизма `projectile`: `none` (instant/self_buff/ally_buff), `beam`, `line`,
  `area(aoe)`; типы physical/fire/ice/poison; эффекты `ЭФФЕКТ[ходы]`: FROZEN, POISONED, BLEED,
  STUNNED, SLOWED, BLINDED. Снаряды-визуализации живут в `projectiles` с фазами cast→hit→done
  (1+1 сек, чистит `updateProjectiles`).
- **TURN (LEFT/RIGHT)**: сервер переписывает при горизонтальном move/attack/ability; вертикаль и
  самоприменение не меняют. Клиент зеркалит через `scaleX(-1)` (класс `turn-left`). Правило CSS:
  новые keyframes либо только filter/box-shadow, либо домножение transform на
  `scaleX(var(--turn-flip))`, иначе сломают отражение. На геймплей TURN пока не влияет.
- **Смерть**: DEATH(1с) → DECAY(1с) → при LIFE>0 респавн (полный reset HP/щита/негативов,
  стартовая позиция или свободная в ±2; SPAWN_ROOM='SPAWN' — комнаты спавна нет, фактически
  revive в той же B1), иначе REMOVED и позиция `-1,-1`. Клиент убирает div из DOM только при
  REMOVED/возрождении. Статусы анимаций сбрасываются только сервером (`updateDeathAnimations`).
- **Объекты/ловушки/NPC**: бочки имеют HP и блокируют клетки; мина срабатывает по ALLY
  (NONE — бьёт всех; иначе щадит владельца и его союзника) и становится TRIGGERED; турель — зоны
  угрозы (манхэттен + LOS), стрельба при пересечении (`checkThreatZoneIntersection`), ATTACK→IDLE
  через `updateNpcStatuses`. Поле `ALLY` обязательно для любого нового автономного объекта.
- **Победа**: `checkVictory` — осталась одна команда (team = min(pid, ALLY)); передаётся в каждом
  snapshot как `victory`.

## 6. HTTP API (контракты клиент↔сервер)

| Эндпоинт | Метод | Вход | Ответ |
|---|---|---|---|
| `php/init.php` | GET | — | `{success, message, players[], room, rules, abilities, passives, effects}`; сайд-эффект: полный rebuild Redis |
| `php/poll.php` | GET | `v` (int версия клиента), `player` (PLAYER*, опционально — тики идут в любом случае) | flat snapshot (§4.2, **без обёртки `data`**), опционально `timeout:true` |
| `php/action.php` | POST | JSON `{action: move\|attack\|ability, player, x, y, ability?}` | `{success, message, ...}`; ошибки валидации — HTTP 400 + JSON (не fatal 500); CORS preflight обрабатывается |
| `php/debug.php` | GET | — | HTML-дамп диагностики движка (не JSON!), ⚠️ flushDB в конце |

Все ответы — `application/json; charset=utf-8`, ошибки — `jsonError` (`{success:false,message}` +
HTTP code). `error_reporting(0)` в config.php — **ошибки PHP в stdout убивают JSON**, поэтому
вывод только через `jsonResponse` (он чистит буферы). Поле `rules` в ответах init/poll —
канон серверных констант (`rules_client_payload()`); клиент пока использует собственный `CONFIG`
(миграция на серверные правила — открытая задача, см. §7).

## 7. Константы и их дублирование

Сервер: **единственный владелец — `engine/rules.php`** (guard-определения `if (!defined(...))`):
GRID 16×12, `MAX_MOVE_DISTANCE=5`, `MOVE_SPEED=3`, `TURN_DURATION=5`, `SHIELD_REGEN_PER_TURN=1`,
`LONG_POLL_TIMEOUT=25`; префикс `battle:` живёт в `engine/world.php`. В `config.php` игровых
констант больше нет (исторический конфликт двойного определения → fatal 500, не возвращать).
Клиент: `CONFIG` в `js/game.js` (GRID_*, CELL_SIZE, MOVE_SPEED) + `css/game.css` (размер клетки) —
**при изменении сетки/скоростей синхронизировать вручную**; сервер отдаёт те же значения в поле
`rules` ответов, но game.js их пока не читает (открытая задача миграции).
Также дублируется формула TURN (`previewTurn()` ↔ `rules_turn_formula()`) — менять одновременно.

## 8. Точки расширения (проверенные рецепты)

- **Новая способность** → запись в `getAbilityDefinitions()` (`systems/abilities.php`) + строка
  `ИМЯ[range]` в ABILITIES игрока в battledata.json. Новый механизм — отдельный `case` в
  `applyAbility`; урон/эффекты — только через сервисы `combat_apply_damage` / `effects_apply`.
- **Новый статус-эффект (DOT/CC/modifier)** → только реестр в `systems/effects.php`
  (`kind`, длительность, модификаторы); блокировки движения берутся из `effects_blocks()`,
  DoT — из `effects_tick()`. Парсить `NEGATIVES` строками в сторонних файлах запрещено.
- **Новая пассивка** → `getPassiveDefinitions()` (`systems/passives.php`) + слушатель шины:
  `turn.start` (REGENERATION-подобные), `damage.query_mitigate` (ARMORED/ARMOR_CRUSH-подобные),
  `damage.dealt` (THORNS-подобные), `attack.resolved` (SPLASH-подобные),
  `movement.query_distance` (SWIFT/SLOWED-подобные). Регистрация — в `passives_register_hooks()`.
- **Новый автономный объект (турель/мина/spawn-объект)** → только `systems/objects.php`:
  `getAutonomousObjectDefinitions()` + данные в battledata.json + поле `ALLY`; урон — через
  `combat_apply_damage`; реакция на ход — слушатель `turn.start`.
- **Новая комната** → `data/ROOM_ID.json` по образцу B1.json + `exits` текущей + обработка перехода
  в `updateMovements()` (сейчас там заглушка-лог).
- **Новое действие** → функция в нужной системе `systems/*` + case-роутинг + валидация в
  `action.php` + кнопка/отправка в `game.js`. Правила никогда не считать на клиенте.
- Любая мутация состояния → сохранение через `world_save()` (incr версии делает сам).

## 9. Инварианты и риски (чек-лист перед рефакторингом)

1. Не менять стек (см. §1) и не переносить логику на клиент.
2. Не разрывать граф зависимостей (§3): эндпоинты знают только про `engine/bootstrap.php`
   (+config как инфраструктуру); новые связи между системами — только шиной событий, порядок
   регистрации слушателей в bootstrap фиксирован.
3. Любой mutate состояния обязан заканчиваться `world_save()` (§4) — иначе зависшие poll не проснутся.
4. `emitReduce`-слушатели обязаны принимать payload **по ссылке**; значение-копия = молчаливый
   no-op (исторические баги: залипшие STUNNED, неработающие зоны угрозы турели).
5. `turn_tick` вызывается КАЖДЫМ poll-запросом независимо от `?player=` — иначе эффекты/таймер
   хода стоят при бездействии клиента.
6. Не ломать двухфазность ловушек/NPC (§5 «движение»): предсказание в processMove работает **только
   на копии** (`makeTrapPreviewState` + dry_run для NPC) — если перенести реальные эффекты туда,
   анимация клиента разъедется с уроном и NPC будут тратить ACTIONS в фазе предсказания.
7. Константы мира определяются только в `engine/rules.php` (guard-define); повторное `define`
   в любом другом файле = fatal 500 (уже случалось с config.php).
8. Координаты — строки `"x,y"` везде; целые числа в позициях могут стать дробными во время MOVING —
   парсить только через `parseXY/formatXY` (geometry.php).
9. Поля сущностей в CAPS — стиль данных, ожидаемый и движком, и js/game.js (`renderGrid`,
   `updatePlayerEntity`). Переименование поля = синхронная правка PHP + JS + battledata.json.
10. `status`-цепочки (`IDLE/MOVING/ATTACK/ABILITY/STUNNED/DEATH/DECAY/REMOVED`, NPC
    `ATTACK/IDLE`, трапы `INTACT/TRIGGERED`, объекты `INTACT/DESTROYED`) читаются и сервером, и
    клиентом (CSS-классы по статусам) — новые статусы добавлять в оба конца. Инвариант
    STATUS↔NEGATIVES поддерживается сам в `turn_tick` — не чинить статусы вручную в системах.
11. Мёртвые/оглушённые не могут действовать (валидация в action.php) и не являются целями
    (фильтры DEATH/DECAY в objects/combat/targeting).
12. Заглушки — не баги: межкомнатные переходы, комната SPAWN, интерактивные объекты, отсутствие
    экипировки (подробно в ABOUT.md §7). «Чинить» их без задачи — вне объёма.
13. После правок сервер не перезапускают: достаточно нового захода через init.php (пересобирает Redis).
14. Фасад `battle.php` — только делегаты; новый код туда не писать, но и удалять legacy-имена
    нельзя (их ожидает часть пути диагностики/debug).
15. Открытые задачи: миграция `js/game.js` на серверное поле `rules`; смоук-тест `tests/` на стенде
    с Redis; удаление `*.old` после подтверждения тестом.
16. Тест-стенд: несколько вкладок `game.html` с разными PLAYER*; `php/debug.php` — диагностика
    загрузки движка (⚠️ делает flushDB, убивает текущий бой); проверка живости PHP/Redis.

---
*Версия описания соответствует состоянию репозитория на момент последнего коммита.
При любом изменении архитектуры (модули, зависимости, протокол, ключи, контракты) — обновить
этот файл вместе с README.md/ABOUT.md.*
