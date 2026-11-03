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

- **Бэкенд**: чистый PHP 8.0+ (без фреймворков) + **Redis** (расширение phpredis). Вся игровая
  логика и все расчёты — только на сервере.
- **Фронтенд**: статические HTML/CSS/vanilla JS. Роли JS строго три: *приём снапшотов*, *рендер*, *ввод*.
- **Транспорт (решение автора, ноябрь 2026)**: long polling **удалён** — доставка снапшотов через
  **Centrifugo** (WebSocket push, персональный канал `battle:<PLAYER_ID>`); тик мира — отдельный
  демон `tools/ticker.php` (раньше его выполнял цикл poll.php). Публикации — HTTP API Centrifugo
  из PHP (`engine/publish.php`, fail-soft).
- **Табу**: ❌ React/Vue/Angular, ❌ Node.js/npm/сборщики, ❌ логика игры на клиенте,
  ❌ игровые состояния в файлах вместо Redis, ❌ PHP-фреймворки.
  (Прежнее «❌ WebSocket вместо long polling» снято решением автора — см. этот блок.)
- Состояние боя живёт **только в Redis** под префиксом `battle:`; файловое состояние отсутствует.
- Живой клиент — `game.html` + `js/*.js` (модули config/state/utils/grid/path/animation/render/input/net/main) + `css/game.css`. Ранние relics песочничной
  React-сборки удалены из репозитория.

## 2. Карта компонентов

Архитектура двухслойная: тонкие HTTP-эндпоинты → движок (`engine/` ядро + `systems/` механики),
связанные событийной шиной. Стек: чистый PHP + Redis + vanilla JS + **Centrifugo** (транспорт
снапшотов) + **демон тика** `tools/ticker.php` (long polling удалён, решение автора 11/2026).

```
Клиент (браузер, вкладка = игрок)              Сервер (PHP-FPM/Apache/Nginx)         Хранилище
┌───────────────────────────────┐   GET  ┌──────────────────────────────────┐
│ game.html  — точка входа       │ ─────▶ │ init.php   — проверка мира /     │◀──reset────┐
│  (экран настройки карточки +   │        │  reset=1 → world_reset (тонкий)  │           ▼
│   игровой экран)               │   POST ├──────────────────────────────────┤      ┌──────────┐
│ js/*.js    — realtime/render/  │ ─────▶ │ join.php   — подключение игрока: │      │  Redis   │
│   input (net.js — отправка     │   POST │  карточка → спавн бойца (CAS)    │      │ battle:* │
│   действий), realtime.js —     │        │  + публикация мира всем          │      └──────────┘
│   подписка Centrifugo          │        │ action.php — валидация +         │           ▲
│ css/game.css — визуал/анимации │   POST │            роутинг move/attack/  │           ▲
│ diag.html  — диагностика       │ ─────▶ │            ability (тонкий)      │           ▲
└──────────┬────────────────────┘        ├──────────────────────────────────┤           ▲
           │ WS subscribe                 │ tools/ticker.php — ДЕМОН ТИКА:  │           │
           │ battle:<PLAYER_ID>           │  world_tick_once() каждый виток  │           │
           ▼                            │  (turn_tick + CAS-save) ──────────┼───────────┘
┌──────────────────────┐                ├──────────────────────────────────┤
│ Centrifugo (:8000)    │◀── HTTP API ── │ engine/publish.php — публикация  │
│ push снапшотов        │   publish      │  снапшотов + JWT-токены (fail-   │
└──────────────────────┘                │  soft); engine/snapshot.php —    │
                                        │  build_snapshot_response          │
                                        ├──────────────────────────────────┤
                                        │ engine/bootstrap.php — ТОЧКА      │
                                        │  СБОРКИ: require систем + hook()  │
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
Данные (статические): data/initial_snapshot.json (ИЗНАЧАЛЬНЫЕ бойцы — с Этапа 1 изначально пуст,
                                     состав боя формируют подключения через php/join.php),
                                     data/A1|A5|B1|E1|E5.json (карты комнат 16×12: grid, двери,
                                     spawn_cells и расстановка objects/traps/npcs)
Сборка изначального состояния: engine/world_builder.php (код-реестры + карты + снимок) →
                                     engine/world_reset.php → Redis (CLI tools/reset_world.php
                                     или HTTP init.php?reset=1)
```

### PHP-модули: HTTP-слой (`php/*.php`)

| Файл | Роль | Ключевые функции |
|------|------|------------------|
| `config.php` | Только инфраструктура: подключение Redis, JSON-ответы, логи. **Игровые константы удалены отсюда** (владелец — `engine/rules.php`); функции `parseXY/formatXY/isValidPosition` обёрнуты в `function_exists` (владелец — `engine/geometry.php`) | `getRedis()`, `addLog()`, `jsonResponse()/jsonError()`, `setCorsHeaders()` |
| `init.php` | Тонкий эндпоинт с ДВУМЯ режимами. **Обычный вход (идемпотентен):** проверяет готовность мира в Redis (`battle:version > 0`, иначе 503 с подсказкой `php tools/reset_world.php`), пропускает состав бойцов через `world_builder_player_errors()`, догружает недостающие карты комнат (`room_get`), пересчитывает `THREAT_ZONE` NPC и вызывает `world_save()` **только если реально что-то изменил** (иначе ложно разбудил бы подписчиков лишней публикацией). **Предупреждает** (поле `warning`, не фатал) об отсутствии флага `seeded` и о молчании демона тика (`battle:ticker_beat` старше 5 с). **Явный перезапуск сессии** (`?reset=1` или POST `{"reset":true}`): `world_reset_session()` — сборка изначального мира + запись (см. `engine/world_reset.php`); при ошибках данных мир НЕ тронут, ответ 500 со списком ошибок. Отдаёт клиенту `rules` + реестры abilities/passives/effects/autonomous_objects | вызывается клиентом после успешного `join.php` (шаг инициализации боя); `?reset=1` — вручную при перезапуске сессии (состав бойцов после reset — пустой, до подключений через join.php) |
| `poll.php` | ★ **УДАЛЁН** (переход на Centrifugo, 11/2026). Его обязанности разделены: тик мира — `tools/ticker.php` (демон, вызывает `world_tick_once` из `engine/snapshot.php`), доставка — `engine/publish.php` (push снапшотов в канал `battle:<PLAYER_ID>`), сборка ответа — `build_snapshot_response()` (контракт flat-снапшота сохранён 1-в-1) | — |
| `action.php` | Тонкий эндпоинт: идемпотентность по `action_id` (ack-журнал + **атомарный pending-резерв `world_action_ack_reserve` до мутации** — параллельный ретрай не применяет действие второй раз, 503) → предварительная валидация (жив ли, не заблокирован ли `effects_blocks()`, есть ли способность/AP) → роутинг `move/attack/ability/special` в systems; ошибки → JSON 400, не fatal | `require bootstrap.php` |
| `join.php` | ★ **Подключение игрока к бою (Этап 1: имитация данных с аккаунта)** (POST JSON — карточка персонажа: `uid`, `nickname`, `stats` (обычные целые 1..10; SHIELD 0..10, RESISTANCE 0..9; ACTIONS 1..3), `abilities` (ровно 3 из реестра), `passives` (0..5 из реестра)). Сервер НЕ доверяет клиенту: мировая валидация карточки (`world_builder_player_errors` + `world_build_player_from_payload`) + собственные проверки; выделяет слот спавна (uid 1..4 → комнаты A1/A5/E1/E5); запись бойца — **CAS по версии мира** (`world_save_cas`, до 3 rebase-попыток против гонки с тиком тикера/чужим подключением); повторный вход того же uid идемпотентен (`rejoin:true`, отдаётся существующий `player_id`); пятый игрок при занятых слотах → HTTP 409 (поверхностное решение известной проблемы отсутствия лобби). Ответ: `{success, player_id, slot, rejoin?, ws:{url, channel, token, expires_in}}` — `ws.token` JWT Centrifugo на персональный канал; успех/rejoin публикуют мир всем бойцам. Альянсы: `ALLY='NONE'` (FFA) | вызывается формой настройки `game.html` (`js/net.js: joinGame()`) перед подключением к Centrifugo |
| `battle.php` | **Фасад обратной совместимости**: подключает `engine/rules.php` + `bootstrap.php`, содержит делегаты со всеми старыми именами (`processMove`, `processAttack`, `updateMovements`, `checkVictory`, …). Новый код здесь не писать | legacy-алиасы |
| `debug.php` | Диагностика новой структуры: PHP/Redis, наличие engine/+systems/, `php -l` модулей (если CLI доступен), загрузка фасада, вывод реестров, состав мира в Redis (**только чтение**, живой бой не перезаписывает). Сброс мира здесь отсутствует сознательно — он выполняется через `tools/reset_world.php` / `init.php?reset=1` | standalone-страница |

### PHP-модули: ядро движка (`php/engine/`)

| Файл | Роль | Ключевые функции |
|------|------|------------------|
| `rules.php` | **Единый источник констант мира** (GRID 16×12, MAX_MOVE_DISTANCE=5, MOVE_SPEED=3, TURN_DURATION=5, SHIELD_REGEN_PER_TURN=1, тайминги анимаций) через guard `if (!defined(...)) define(...)` — порядок загрузки файлов не может уронить процесс. `LONG_POLL_TIMEOUT` удалён с transport-миграцией (ритм тика — `--interval` тикера) | `rules_turn_formula()` (канон направления спрайта TURN), `rules_client_payload()` (правила в ответах init и в каждом снапшоте) |
| `geometry.php` | Чистые функции координат, без состояния | `parseXY/formatXY/isValidPosition/manhattan/inRadius/calculatePath/adjacentCells/cellsInRadius/directionStep` |
| `events.php` | Шина событий: `hook(event, fn, priority)` с детерминированным порядком, `emit()` (fact-события), `emitReduce()` (query/reduser-события; **payload передаётся по ссылке** — слушатели мутируют оригинал, а не копию). Канонический список событий — в шапке файла | `hook()`, `emit()`, `emitReduce()` |
| `world.php` | **Единственный владелец хранилища**: все чтения/записи Redis идут через него; инкремент `REDIS_VERSION` централизован в `world_save()`; дедупликация записей; виртуальные часы движения — отдельный ключ `battle:world_move_now` (обновляется каждый тик без инкремента версии); журнал ack для идемпотентности действий — hash `battle:action_acks` | `world_load/world_save/world_init/world_log/world_version`, `world_action_ack_get/set` (ретраи `action.php` отдают сохранённое подтверждение вместо повторного применения), `world_action_ack_reserve/release` (атомарный HSETNX-pending резерв action_id ДО применения: параллельный ретрай, пришедший пока оригинал ещё в CAS-цикле, получает 503, а не второй удар; протухший pending перехватывается через `ACTION_ACK_PENDING_STALE`); константа ключа `REDIS_WORLD_MOVE_NOW` определяется здесь |
| `turn.php` | Оркестратор тика хода: movement → death chain → projectiles → attack reset → npc statuses → effects tick; самовосстанавливающийся инвариант STATUS↔NEGATIVES (снятие истёкших дебаффов, в т.ч. STUNNED); защита от `turn_time=0` | `turn_tick()` (вызывается каждым витком `world_tick_once()` из тикера), `turn_check_advance()`, `turn_start()` (ACTIONS/shield regen + emit `turn.start`) |
| `bootstrap.php` | Точка сборки: require всех систем + регистрация слушателей в фиксированном порядке (combat → passives → effects → movement → objects → turn) + require транспортного слоя (snapshot/publish) + `engine_check_victory` | `*::register_hooks()` вызываются отсюда |
| `snapshot.php` | ★ Транспортно-нейтральная сборка состояния: `build_snapshot_response()` (бывш. build_poll_response из poll.php — flat-контракт без обёртки `data`, комната запросившего бойца, MOVE_RANGE, server_now) + `world_tick_once()` (ОДИН виток тика: load → guard turn_time → turn_tick → CAS-save; гонка = null-возврат, виток повторяется) | `build_snapshot_response(state, version, player_id)`, `world_tick_once()` |
| `publish.php` | ★ Centrifugo-слой доставки (замена long polling): JWT user-токены (HS256, claim `channels` — только свой канал), публикация снапшотов через HTTP API (`stream context`, без curl), **fail-soft** (сбой шины не роняет тик/действие/join), метка живости тикера `battle:ticker_beat`; env `CENTRIFUGO_SINK_FILE` — JSONL-дам публикаций для тестов; константы `CENTRIFUGO_*` (guard + env) | `centrifugo_user_token()`, `centrifugo_ws_payload()`, `centrifugo_publish()`, `battle_publish_world()`, `battle_channel()`, `ticker_beat_touch/age()` |
| `world_builder.php` | ★ **Единственный сборщик ИЗНАЧАЛЬНОГО состояния мира** (ответ на «где старт, если хранилище — Redis?»): бойцы из `data/initial_snapshot.json` + расстановка объектов/ловушек/NPC из карт комнат + характеристики из `getObjectTemplates()` + расчёт `THREAT_ZONE`. Чистая функция (state не мутируется, ничего не пишет). Публичные алиасы `player_defaults()` / `normalize_player()` — чтобы валидация состава не жила во втором месте | `world_build_initial()`, `world_builder_normalize_player(raw, room_map, keep_stored)`, `world_builder_player_errors()`, `world_builder_read_snapshot()`, `world_build_player_from_payload()` |
| `world_reset.php` | ★ Перезапуск игровой сессии: сборка (`world_reset_build`) + запись (`world_reset_write`: MULTI → flushDB → все секции + `room:list`/`room:<ID>` → `version=1` → `seeded`). Один код для CLI и HTTP — правила сборки не имеют права дублироваться | `world_reset_build($snapshot_path)`, `world_reset_write(&$state)`, `world_reset_session()` |

### PHP-модули: механики (`php/systems/`)

| Файл | Роль | Ключевые элементы |
|------|------|-------------------|
| `effects.php` | **Реестр статус-эффектов** (STUNNED, DOT и т.д.) с kind'ами `dot / cc / modifier`; длительность `[N]` ходов из NEGATIVES теперь реально тикает и снимается | `effects_apply/tick/blocks/get_modifier`; немедленная активация CC через слушатель `effect.applied`; никто больше не парсит NEGATIVES строками в сторонних файлах |
| `combat.php` | Единая цепочка урона: mitigate-query (сопротивление, ARMORED/ARMOR_CRUSH) → щит → HP → DEATH | `combat_apply_damage()` (PUNCTURE, остановка движения только при реальном уроне), `processAttack` на её базе (TURN по формуле, SPLASH через событие) |
| `movement.php` | Движение: две фазы сохранены (предсказание на черновом копийстве `makeTrapPreviewState` vs серверное применение `movement_update`); блокировки/дальность — query-события | `movement_process_move`, слушатели `movement.query_block` (CC), `movement.query_distance` (SWIFT/SLOWED); «догоняющие тики» на виртуальных часах мира (`WORLD_MOVE_NOW`) |
| `passives.php` | Реестр пассивок как слушатели шины (логика НЕ размазана по файлам) | ARMORED→`damage.query_mitigate`, THORNS→`damage.dealt`, SWIFT/SLOWED→`movement.query_distance`, REGENERATION→`turn.start`, SPLASH→`attack.resolved` (один проход по живым врагам); **ARMOR_CRUSH**→`damage.query_mitigate` атакующего: игнорирует всё сопротивление цели; собственная ARMORED носителя НЕ гасит пробитие — ARMORED взаимодействует только с чужим ARMOR_CRUSH (`combat_should_pierce_resistance()`); переработанная ARMORED снижает входящий урон вдвое **только от атак с ARMOR_CRUSH** |
| `targeting.php` | Универсальный доступ к сущностям + правила проходимости клеток | `resolveEntity/hostile_to/all_entities`; AoE бьёт игроков, NPC и объекты; `is_cell_passable()` (стены/целые объекты/живые NPC блокируют), `is_player_at_position()` (DEATH/DECAY/REMOVED клетку не занимают) |
| `abilities.php` | Реестр способностей (формат данных не менялся) + исполнение через единые сервисы combat/movement/effects | `getAbilityDefinitions`, `applyAbility` |
| `objects.php` | NPC (зоны угрозы, LOS — пересчёт каждый ход) и ловушки; урон через единую цепочку `combat_apply_damage`; жёсткий фильтр целей: DEATH/DECAY не атакуется. Плюс **реестр шаблонов типов** — серверный источник характеристик объектов/ловушек/NPC (нужен и сборщику изначального мира, и будущим spawn-объектам способностей) | `getObjectTemplates()` (SMALL_BARRELS/LAND_MINE/DEFAULT_TURRET: category, image, defaults), `objectEntityDefaults(category)`, `objects_build_entity(type, overrides, room_id)`, `objects_layout_from_room(card, room_id)` (расстановка из секций карты; неизвестные `IMAGE` молча пропускаются), `objects_spawn_entity(&$state, ...)` (задел под способности; сохранение — на вызывающем), `getAutonomousObjectDefinitions()` — проекция шаблонов для клиента, `calculateThreatZone`, `objects_has_line_of_sight` (legacy-алиас `hasLineOfSightNPC` — в фасаде battle.php), слушатель `turn.start` |
| `rooms.php` | Мультикомнатный мир: сущности и игроки привязаны к комнате полем `ROOM_ID`; координатные сетки комнат ПЕРЕКРЫВАЮТСЯ (одна клетка x,y живёт в нескольких комнатах), поэтому ИЗОЛЯЦИЯ КОМНАТ — инвариант: любая проверка позиции (цели, лучи, AoE, зоны угрозы, ловушки, занятость клеток, проходимость) обязана фильтровать по `ROOM_ID`. Карта комнаты грузится лениво из `data/<ID>.json` в `state['rooms']` (в Redis лежат только «оседлавшие» мир комнаты); двери (`exits["x,y"]=КУДА`) — постоянный триггер вида LAND_MINE: наступил → мгновенный телепорт; `room.changed` — fact-событие шины после переноса | `room_get`, `room_of_player`, `room_cell_blocked`, `room_candidate_cells`, `room_find_spawn_cell`, `rooms_teleport(reason: exit/respawn)`, `rooms_check_exit_trigger`; снапшот отдаёт карту/объекты/ловушки/NPC только комнаты бойца (параметр `player_id` в `build_snapshot_response`); клиент (`js/grid.js`/`js/render.js`/`js/path.js`) фильтрует отрисовку и проверки путей (`inCurrentRoom`, `rebuildGrid`, `purgeOutOfViewRoomEntities`) строго по `ROOM_ID` рисуемой комнаты — иначе «урон по пустой клетке задевает бойца в другой комнате» |

### Клиент (`js/*.js`, модули, vanilla JS; был единый game.js на 1724 строки — разбит)

Модули (порядок загрузки в `game.html`): `config.js` (CONFIG), `state.js` (state + effectiveMoveRange),
`utils.js` (showNotification, парсеры), `grid.js` (buildGrid/rebuildGrid/findEntity/inCurrentRoom/purge*),
`path.js` (isPathClear/isCellFreeForMove/hasLineOfSight), `animation.js` (previewTurn/applyTurnClass/*MoveAnimation),
`render.js` (render/renderGrid/create*Entity/render*UI/checkVictory), `input.js` (onCellClick/showActionDialog),
`net.js` (joinGame/initGame/nextActionId/sendAction + syncClock по серверному `server_now`), `realtime.js` (startRealtime/handleRealtimeSnapshot/refreshRealtimeToken — подписка Centrifugo на канал `battle:<playerId>`, publication → syncClock+render; heartbeat-снапшот той же версии обновляет только таймер), `main.js` (DOMContentLoaded — точка входа: экран настройки карточки → joinGame).
`js/vendor/centrifuge.min.js` — статический UMD-файл centrifuge-js 5 (globalThis.Centrifuge; без npm/сборщиков).
Функции объявлены в глобальном скоупе обычными `<script>` — без сборщиков и ES-модулей (табу стека не нарушается).

- `CONFIG.PHP_URL = 'php/'`, сетка 16×12 (`GRID_WIDTH/HEIGHT`), `CELL_SIZE=40` (дублирует серверные константы — см. §7; скорости клиента в `CONFIG` нет — анимация движения строится на временах хода `MOVE_START/END_TIME` из snapshot).
- Жизненный цикл: **экран настройки карточки в `game.html`** (uid, ник, stats: обычные 1..10, SHIELD 0..10, RESISTANCE 0..9, ровно 3
  способности из реестра, 0–5 пассивов чекбоксами) → `joinGame(card)` (POST `join.php`, сервер
  возвращает `player_id` + `ws`) → `initGame(playerId)` (fetch `init.php`) → `startRealtime()` →
  подписка Centrifugo на `ws.channel` (персональный канал бойца); снапшоты приходят push-ем
  (тик-демон + мгновенные публикации action/join). До подключения игрока мир пуст для бойцов:
  0 игроков = 0 персонажей. ★ Требуется запущенные `centrifugo` и `php tools/ticker.php`.
- Ввод: клик по клетке (`onCellClick`) открывает диалог действий (`showActionDialog`), кнопки
  способностей показываются по дальности; отправка — `sendAction()` (retry ×3 на 5xx/parse/network).
- Рендер: `render(data)` — полный снимок → сетка, сущности, снаряды, UI (HP/щит/ACTIONS, таймер
  хода, лог, победа). Плавное движение — клиентская интерполяция между `START/END_POSITION_XY`
  по `MOVE_START_TIME/MOVE_END_TIME` (авторитет — серверное время).
- Клиент **не считает правила**; единственная «копия серверной формулы» — `previewTurn()`
  (мгновенное зеркаление спрайта до ответа poll; должна совпадать с `rules_turn_formula()`).
- Комнатная изоляция отрисовки: snapshot приносит карту комнаты запросившего игрока
  (`room`/`room_id`/`grid`); при смене `room_id` сетка перестраивается (`rebuildGrid`),
  DOM-сущности чужой комнаты вычищаются (`purgeOutOfViewRoomEntities`), а все локальные
  проверки путей и подсветки фильтруют сущности через `inCurrentRoom(e)` по `ROOM_ID`.
- Локальные проверки пути (`isPathClear`, `isCellFreeForMove`, `hasLineOfSight`) — только для UX
  (подсветка/скрытие недоступных действий); финальная валидация всегда серверная.

### Данные (`data/`)

- `initial_snapshot.json` — **ИЗНАЧАЛЬНЫЕ бойцы** (упразднённый `battledata.json` оставил после
  себя только этот фрагмент): `{ "players": [ {...}, ... ] }`, поля — CAPS (наследие ТЗ):
  `NICKNAME`/`CHAR_ID` (ключ бойца), `HITPOINTS/MAX_HITPOINTS`, `SHIELD/MAX_SHIELD`, `ATTACK`,
  `DEFENCE`, `RESISTANCE`, `RANGE`, `ACTIONS/MAX_ACTIONS`, `ABILITIES: ["ИМЯ[N]"]`
  (**N — дальность, не стоимость!**), `PASSIVES`, `NEGATIVES: ["ЭФФЕКТ[ходов]"]`, `ALLY`, `LIFE`,
  `ROOM_ID`/`SPAWN_ROOM`, `TURN`, `COLOR`. Обязательны `ROOM_ID` и `SPAWN_ROOM` — без них
  неизвестно место появления (fail-fast в `world_builder_player_errors()`).
  ★ Позиционные поля (`START/CUR/END_POSITION_XY`) при сборке **игнорируются**: стартовая клетка
  вычисляется кодом респауна (`room_initial_spawn_cell` → секция `spawn_cells` карты), иначе CUR и
  START разъезжаются и первое движение превращается в телепорт к серверной клетке.
  ★★ **Этап 1: файл изначально пуст** (`{"players": []}`). Состав боя больше не берётся отсюда:
  карточку персонажа (uid/ник/stats/abilities/passives) присылает клиент через `php/join.php`
  (`world_build_player_from_payload()` + нормализация тем же `world_builder_normalize_player`).
  Формат записей сохранён как резервный источник и пример схемы; в будущем карточку будет отдавать
  аккаунт игрока из БД вместо ручной формы.
- Расстановка объектов/ловушек/NPC больше не живёт в одном файле: записи `{ID, IMAGE, POSITION_XY,
  ...переопределения}` в секциях `objects` / `traps` / `npcs` карт комнат, а характеристики типа —
  код `systems/objects.php::getObjectTemplates()`. `ROOM_ID` сущности берётся из имени карты;
  запись с неизвестным `IMAGE` молча пропускается (типов в реестре больше, чем расстановки).
- Карты комнат `A1.json`, `A5.json`, `B1.json`, `E1.json`, `E5.json` — единый формат:
  `room_id`, `width/height`, `grid` (маска: **1 = проходима, 0 = стена/препятствие** — так же
  трактуют сервер `room_cell_blocked()` и клиент), `exits` («x,y» → КУДА),
  `exit_rooms` («x,y» → ОТКУДА, обратная дверь — по ней `rooms_teleport` выбирает, у какой
  двери появляться входящему), `exit_positions` (эталонная клетка появления со стороны двери),
`spawn_cells` (`{"PLAYER1": "8,6", ...}` — выделенные стартовые клетки, в углах это центр
   комнаты, поодаль от двери; нужны только в спавн-комнатах, читаются
   `room_defined_spawn_cell()`/`room_initial_spawn_cell()`/`room_player_spawn_cell()`), секции
  `objects`/`traps`/`npcs` (расстановка автономных сущностей комнаты) и `stairs` (пусто во всех
  картах — вертикальные переходы не реализованы).
  Переходы **работают**: наступил на exit-клетку → `movement_update` вызывает
  `rooms_check_exit_trigger()` → мгновенный `rooms_teleport(..., 'exit')`.

## 3. Зависимости между PHP-модулями (граф require_once + событийная шина)

```
init.php ─┬─▶ engine/bootstrap.php           config.php (только Redis-коннект,
action.php│   (единственная точка сборки:     JSON-хелперы, логи) ◀─ engine/world.php
          │    require всех engine/*+systems/* +
          │    hook-регистрации + snapshot/publish)
battle.php┘── (фасад legacy-имён)
tools/ticker.php —— bootstrap.php (world_tick_once из engine/snapshot.php +
                   battle_publish_world/ticker_beat_touch из engine/publish.php)
join.php —— bootstrap.php + world_builder.php (карточка → боец, запись через world_save_cas,
           публикация мира + ws-токен через engine/publish.php)
debug.php —— свой путь диагностики (свой require bootstrap.php)

★ Перезапуск сессии (единственный путь, где мир СОБИРАЕТСЯ заново):
  init.php(?reset=1) / tools/reset_world.php
        └─▶ engine/world_reset.php ─┬─▶ engine/bootstrap.php   (тот же граф зависимостей)
             (build → write:         └─▶ engine/world_builder.php
              flushDB+секции)              ├─▶ systems/objects.php (getObjectTemplates() —
                                            │                       характеристики типов)
                                            └─▶ systems/rooms.php   (room_get() — карты комнат
                                                                     с секциями objects/traps/npcs)
                                            и data/initial_snapshot.json (бойцы)

php/engine/: rules → geometry → events → world → turn → world_builder → world_reset
php/systems/: effects, targeting, passives, movement, combat, abilities, objects, rooms

        все системы и engine/* подключаются bootstrap'ом; каждая система сама
        тянет свои прямые зависимости (events/world/geometry/effects/targeting)
        через require_once — граф ацикличен, циклов вида items↔objects больше нет
```

Связь между механиками — **не прямыми вызовами**, а шиной событий (`engine/events.php`).
Канонический список событий (из шапки events.php и bootstrap.php):

| Событие | Вид | Кто эмитит | Кто слушает |
|---|---|---|---|
| `damage.query_mitigate` | query (payload by-ref) | combat_apply_damage | passives (ARMORED ÷2 от pierce-атак). RESISTANCE редукцию combat применяет сам ПОСЛЕ редьюсера; pierce (ARMOR_CRUSH) читается напрямую через `combat_should_pierce_resistance`, слушателем события combat не является |
| `damage.dealt` | fact | combat_apply_damage | combat (прерывание MOVING при реальном уроне, p0; источники с `non_interrupting=true` — DoT и будущие фоновые — не прерывают), passives (THORNS, p10) |
| `entity.died` | fact | combat_apply_damage (HP ≤ 0 → STATUS='DEATH') | слушателей пока нет — death-chain выполняется inline в самом `combat_apply_damage`, событие зарезервировано для логов/подписчиков |
| `attack.resolved` | fact (payload включает `hit_ids` — цели, уже получившие прямой урон) | processAttack | passives (SPLASH; `hit_ids` пропускает — иначе движущаяся цель бьётся дважды: CUR при прямом ударе, END при splash) |
| `effect.applied` | fact | effects_apply | effects (немедленная активация CC), turn (`turn_sync_status_after_effect`, p10 — синхрон STATUS↔NEGATIVES) |
| `movement.query_block` | query | movement | movement-адаптер `movement_on_query_block` (ход к CC-правилам через `effects_blocks()`; сами правила — в effects) |
| `movement.query_distance` | query | movement | passives (SWIFT), passives-адаптер эффектов SLOWED (p10, читает `effects_get_modifier`) |
| `movement.interrupted` | fact | combat (прерывание при реальном уроне) | movement (остановка на текущей клетке) |
| `turn.start` | fact | turn_start | turn (effects tick, p5), passives (REGENERATION), objects (NPC actions reset + threat, p20). Восстановление ACTIONS и реген щита — inline в `turn_start()`, не через слушателей |
| `room.changed` | fact | rooms_teleport (после успешного переноса: exit/respawn) | слушателей пока нет — событие зарезервировано для будущих подписчиков (в канонической шапке `events.php` ещё не перечислено — при правке шапки добавить) |

Эмитятся, но слушателей пока нет: `trap.triggered` (objects), `entity.died` (combat), `room.changed` (rooms).
Зарезервированы в каноническом списке шапки `events.php` без эмитентов и слушателей:
`threat.entered`, `tick.server`, `victory.check`.

Правила зависимостей (жесткие):
- Эндпоинты требуют **только** `engine/bootstrap.php` (battle.php — фасад для legacy-имён).
  Не добавляйте прямые `require_once` систем в эндпоинты. Исключение осознанное: `init.php`
  дополнительно требует `engine/world_reset.php`, потому что перезапуск сессии — единственный
  HTTP-путь, где мир пересобирается; сам `world_reset.php` тянет `bootstrap.php`, поэтому
  граф слушателей и правил от этого не меняется. Второе осознанное исключение: `join.php`
  дополнительно требует `engine/world_builder.php` — сборка бойца из карточки идёт теми же
  функциями (`world_build_player_from_payload` / `world_builder_normalize_player`), чтобы
  валидация состава не жила во втором месте; прямых `require_once` систем (`systems/*`) у join.php нет.
- Изначальное состояние мира собирается **только** `engine/world_builder.php` (чистая функция:
  ничего не пишет, state не мутирует) и записывается **только** `engine/world_reset.php`.
  CLI `tools/reset_world.php` обязан вызывать ровно те же функции — правила сборки запрещено
  дублировать во втором месте (исторический баг: `tools/seed_redis.py` имел свои словари
  дефолтов и расходился с PHP).
- Регистрация слушателей — **только в bootstrap.php**, фиксированный порядок:
  combat → passives → effects → movement → objects → turn (детерминизм тиков).
- Системы не вызывают друг друга напрямую — только через `emit/emitReduce` или сервисы
  lower layer (combat→effects, movement→effects и т.д. — вниз по слоям можно, «вбок» — только шиной).
- Логика автономных объектов — только в `systems/objects.php`; пассивки — только в
  `systems/passives.php`. Характеристики типов объектов/ловушек/NPC — кодовый реестр
  `getObjectTemplates()`; новые spawn-объекты — запись туда + вызов `objects_spawn_entity()`
  из способности (`getAutonomousObjectDefinitions()` — его клиентская проекция, не дублировать числа).
- `emitReduce` передаёт payload **по ссылке** — слушатели обязаны мутировать оригинал
  (иначе статус-эффекты «залипают»; исторический баг, зафиксирован в тестах).

## 4. Поток данных и протокол синхронизации

1. **join**: клиент → `POST php/join.php` (карточка персонажа с экрана настройки `game.html`) →
   серверная валидация + выделение слота спавна → `world_save_cas` (боец появляется в мире;
   0 подключений = 0 бойцов) → ответ `{player_id, ws:{url, channel, token, expires_in}}` →
   далее init + подписка Centrifugo на `ws.channel` (персональный канал бойца).
   Ответ join публикует мир всем бойцам (новичок виден сразу).
2. **init**: клиент → `GET php/init.php` → проверка готовности мира в Redis + пересчёт
   THREAT_ZONE (идемпотентно, живой бой НЕ обнуляется). Перезапуск сессии — только явно:
   `GET php/init.php?reset=1` или CLI `php tools/reset_world.php` → `world_reset_build()`
   (сборка изначального состояния) → `world_reset_write()` (flushDB, version=1, метка `seeded`).
   Причина правки: старый flushDB на каждом входе терял бой между вкладками.
3. **tick-loop** (`tools/ticker.php` — демон, НЕ HTTP): каждый виток ≤100 мс: `world_load` →
   `world_tick_once()` (engine/snapshot.php: `turn_tick($state)` — движение по «виртуальным
   часам мира» с догоняющими тиками → death chain → projectiles → attack reset → npc statuses →
   effects tick (внутри хода) → пересчёт зон угрозы NPC после фазы движения
   (`objects_refresh_threat_zones`) → при `now-turn_time ≥ TURN_DURATION(5)` новый ход (`turn_check_advance` →
   `turn_start` + emit `turn.start`: passives/effects/combat/objects-слушатели) → CAS-`world_save`
   (incr version централизован в world_save) → метка живости `battle:ticker_beat` (без версии) →
   **публикация**: при смене версии ИЛИ не реже heartbeat 1 с — `battle_publish_world(state, version)`:
   на каждого бойца собирается ЕГО снапшот (`build_snapshot_response`, прежний flat-контракт
   poll-ответа: `version, rules, server_now, room, room_id, grid, objects, traps, npcs, players,
   projectiles, log[-30], turn_time, turn_duration, time_to_next_turn, victory` — ключи на верхнем
   уровне, без обёртки `data`) и публикуется в персональный канал `battle:<PLAYER_ID>` через
   HTTP API Centrifugo (`engine/publish.php`, fail-soft: шина недоступна — мир живёт дальше,
   клиент догонит следующим публикационным витком). `server_now` — серверные часы на момент
   сборки снапшота (`microtime(true)`); клиент синхронизирует локальные часы через `syncClock()`
   (RTT — ping/pong по WS, `state.wsRttMs`) и интерполирует движение по ним. `room/room_id/grid` —
   карта комнаты бойца, объекты/ловушки/NPC отфильтрованы по её `ROOM_ID`; игроки — список целиком
   (клиент рисует совпадающих по комнате). ★ Раньше этот цикл жил в php/poll.php (файл удалён):
   мир тикал только пока клиенты держат long-poll; теперь тик не зависит от подключений, а
   N клиентов ≠ N висящих PHP-процессов — бутылочное горлышко long polling снято.
4. **action**: клиент → `POST php/action.php` (JSON) → предварительная валидация (жив/не CC/AP) →
   функция systems мутирует `$state` (by-ref) → `world_save_cas` (incr version) →
   `battle_publish_world` — все подписчики получают новый мир мгновенно (замена «зависшие poll
   отдают сразу»). Публикация fail-soft: сбой шины не отменяет сохранённое действие — тикер
   донесёт состояние следующим витком. Ошибки — JSON 400, не fatal.
5. **Контракты**: любой mutate состояния **обязан** закончиться сохранением через `world_save`
   (он сам делает incr версии; прямой `incr` в стороннем коде — антипаттерн). Забыли save →
   клиенты «зависнут на старом мире».

### Ключи Redis (префикс `battle:`)

`players`, `objects`, `traps`, `npcs`, `room` (legacy-карта единой комнаты — её пишет init.php,
читают debug-пути и вызовы без room_id), `turn_time`, `projectiles`, `version`, `log`
(последние 50 записей; клиенту отдаются последние 30), `world_move_now` (виртуальные часы
движения, float-микровремя; обновляется каждый тик без инкремента версии), `action_acks`
(hash журнал подтверждений применённых действий для идемпотентности retry-запросов из
`action.php`; TTL `ACTION_ACK_TTL`, компакция при >2000 записей). Мультикомнатный слой:
`room:list` (JSON-массив ID комнат, реально «оседлавших» мир) и `room:<ID>` (JSON-карта каждой
из них; пишутся/читаются только эти комнаты — остальные лежат на диске в `data/<ID>.json` и
подхватываются лениво). Плюс `seeded` (метка готовности: мир записан `engine/world_reset.php`;
отсутствие даёт предупреждение в init.php, но не блокирует вход) и `ticker_beat` (float-микросекунды,
метка живости демона тика — пишет tools/ticker.php каждый виток БЕЗ инкремента версии; init.php и
debug.php предупреждают, если демон молчит >5 с). Значения секций — JSON-строки, version/turn_time — int.
★ Изначального состояния мира в этих ключах нет: оно собирается кодом
(`engine/world_builder.php`) и лишь затем сюда пишется, поэтому рестарт Redis с RDB/AOF
восстанавливает прошлый бой, а не старт.
★ Centrifugo использует ЭТОТ ЖЕ redis-server, но **db 1** (engine type redis, prefix `centrifugo`):
reset-сессии делает `flushDB` на db 0 — боевой движок Centrifugo не должен лежать в той же БД.

### Конкурентность (важно знать перед правками)

Явных блокировок/транзакций нет: read-modify-write всего состояния из нескольких PHP-процессов
параллельны. Гонки гасятся короткими операциями и тем, что занятость клетки учитывает
`END_POSITION_XY` движущихся игроков (`is_player_at_position`; мёртвые DEATH/DECAY/REMOVED клетку
не занимают). `action.php` содержит retry-логику
сохранения. Подключение игрока (`join.php`) идёт через `world_save_cas($state, $expected_version)`:
CAS-запись по версии мира с перечитыванием (rebase) и повтором до 3 попыток — параллельный тик
тикера или чужое подключение не затираются. ★ После удаления poll.php писателей тика стало меньше
(один демон вместо N long-poll-процессов) — конкурентность записей только тикер↔действия/join.
Это осознанный минимализм стенда — при изменении
модели записи не ухудшайте это.

## 5. Модель мира и ключевые механики (что ломать нельзя)

- **Сетка 16×12** (`GRID_WIDTH/HEIGHT`), координаты строкой `"x,y"`, дробные значения допускаются
  в CUR во время движения (форматирует `formatXY`). Мир — пять комнат (`data/<ID>.json`): центральный
  зал B1 и угловые A1/A5/E1/E5. Двери B1: `0,0→A1`, `15,0→A5`, `0,11→E1`, `15,11→E5`; обратные двери
  углов `0,0→B1`. Переходы реализованы (`rooms.php`: exit-триггер в финале `movement_update` →
  `rooms_teleport(reason='exit')`), не заглушка. Поле `stairs` во всех картах пусто — вертикальных
  переходов нет.
- **Ход**: каждые `TURN_DURATION=5` с ACTIONS → MAX_ACTIONS (без накопления), щит +1 к максимуму,
  тик NEGATIVES (DoT DOT[N], счётчики), REGENERATION, восстановление ACTIONS NPC,
  перепроверка зон угрозы для стоящих игроков. Очерёдности нет — real-time со стоимостью 1 AP за
  любое действие. Кулдаунов у способностей нет.
- **Движение**: только по прямой, ≤5 клеток (`MAX_MOVE_DISTANCE=5`, скорость `MOVE_SPEED=3 кл/с`;
  SWIFT +1, SLOWED −2, min 1). Проходимые клетки — `is_cell_passable()` (стены, неразрушенные
  объекты и **живые NPC** блокируют; мёртвые DEATH/DECAY/REMOVED игроки клетку не занимают —
  `is_player_at_position`).
  Двухфазная модель — **предсказание vs применение**: `movement_process_move` прогоняет путь по черновому
  копийству (`makeTrapPreviewState`) и лишь укорачивает END на клетку мины/смертельного NPC;
  реальный урон/STUNNED/DEATH применяются в `movement_update()` ровно когда серверные часы
  довели игрока до клетки (синхрон с анимацией клиента). Вход на мину мгновенно завершает
  движение на её клетке; STUNNED от ловушки активируется сразу, не дожидаясь хода. Урон прерывает
  движение. Во время MOVING другие действия заблокированы сервером.
- **Атака**: луч по прямой в пределах RANGE (BLINDED → /2); без PUNCTURE останавливается на первом
  враге/препятствии, с PUNCTURE пробивает дальше; стены/бочки/живые NPC блокируют всегда.
  Ловушки (traps) — не «живые цели» (`all_entities` их не содержит), они активируются
  **покрытием клетки** финальным `hit_cells` луча: дошедший до цели луч (!stopped) покрывает
  целевую клетку всегда, даже если по пути уже пробит блокировщик (regression: PUNCTURE-атака
  сквозь игрока не доставала Land Mine за ним).
- **Формула урона** (единая цепочка `combat_apply_damage` в `systems/combat.php`): база
  `ceil(max(ATTACK,1) × (1 − RESISTANCE×10%))`, RES≤90%, минимум 1 — применяется только если
  атакующий НЕ пробивает сопротивление; **ARMOR_CRUSH** (у атакующего; собственная ARMORED
  его не отключает) игнорирует всё сопротивление цели; **ARMORED** (у цели) снижает входящий урон вдвое
  **только от атак с ARMOR_CRUSH** (на обычные атаки/турели не действует); щит гасит первым;
  реген щита `SHIELD_REGEN_PER_TURN=1`; THORNS/SPLASH — контур и разброс (слушатели шины в
  passives.php). NPC-урон (турель/мина) идёт по той же цепочке без RESISTANCE-редукции цели —
  одинаковый по всем целям. Прерывание MOVING — только при реально нанесённом уроне
  от прерывающих источников (полностью поглощённый щитом движение не останавливает;
  DoT помечен в реестре эффектов `interrupts_movement=false` → `source['non_interrupting']`
  → слушатель `damage.dealt` пропускает прерывание: тик DoT в пути не гасит перемещение,
  даже если урон прошёл сквозь щит на HP). Флаг — общий механизм для будущих
  «фоновых» источников урона без торможения. Мёртвые (DEATH/DECAY) целями не являются.
- **Способности**: 4 механизма `projectile`: `none` (instant/self_buff/ally_buff), `beam`, `line`,
  `area(aoe)`; типов урона нет — урон просто урон; эффекты `ЭФФЕКТ[ходы]`: PARALYZED, DOT,
  STUNNED, SLOWED, BLINDED. Снаряды-визуализации живут в `projectiles` с фазами cast→hit→done
  (1 сек каст + 2 сек полёт/попадание, чистит `turn_update_projectiles`; legacy-фасад
  `updateProjectiles` в battle.php делегирует ему).
- **TURN (LEFT/RIGHT)**: сервер переписывает при горизонтальном move/attack/ability/special; вертикаль и
  самоприменение не меняют. Клиент зеркалит через `scaleX(-1)` (класс `turn-left`). Правило CSS:
  новые keyframes либо только filter/box-shadow, либо домножение transform на
  `scaleX(var(--turn-flip))`, иначе сломают отражение. На геймплей TURN пока не влияет.
- **Смерть**: DEATH(1с) → DECAY(1с) → при LIFE>0 респавн (LIFE−1, полный reset HP/щита, **очистка `NEGATIVES`**) через
  `rooms_teleport(..., 'respawn')` в личную комнату `SPAWN_ROOM` (= спавн-комната, выделенная
  `join.php` по слоту uid: A1/A5/E1/E5; historically — комната старта бойца из
  `data/initial_snapshot.json`) с подбором ближайшей свободной клетки вокруг `START_POSITION_XY`
  (`room_find_spawn_cell`, защита от вставания на дверь/занятую клетку); если телепорт невозможен —
  legacy-страховка: строгий возврат на `START_POSITION_XY` внутри текущей комнаты. Иначе REMOVED
   (позиция не перестирается). ★ Смерть снимает ВСЕ статус-эффекты: `combat_apply_damage` при
   переходе в DEATH обнуляет `NEGATIVES` (DoT/CC/модификаторы не «доживают» труп и не должны
   блокировать воскресшего), `effects_apply` на DEATH/DECAY/REMOVED игнорирует наложение, а
   убивающий DoT-тик в `effects_tick` прерывает прогон, чтобы список прогона не вернул эффекты.
   Клиент убирает div из DOM только при
  REMOVED/возрождении. Статусы анимаций сбрасываются только сервером (`turn_update_death_animations`).
- **Объекты/ловушки/NPC**: бочки имеют HP и блокируют клетки; **живой NPC (турель) — тоже
  непроходимое препятствие** (клетку занимает `is_cell_passable()`, после DESTROYED/DEATH/REMOVED
  освобождает); мина срабатывает по ALLY
  (NONE — бьёт всех; иначе щадит владельца и его союзника) и становится TRIGGERED; турель — зоны
  угрозы (манхэттен + LOS), стрельба при пересечении (`objects_check_threat_zone`, фасад
  `checkThreatZoneIntersection` в battle.php), ATTACK→IDLE через `objects_update_npc_statuses`
  (фасад `updateNpcStatuses`). Поле `ALLY` обязательно для любого нового автономного объекта.
- **Победа**: ★ **ВРЕМЕННО ВЫКЛЮЧЕНА (Этап 1)**: `engine_check_victory` (фасад `checkVictory`)
  сейчас возвращает `victory: false` всегда — с заготовленного состава из 4 бойцов условие
  «живых ≤ 1» было финалом боя, а с подключением по одному игроку оно сработало бы в первую
  же секунду. Возвращаемый контракт прежний (снапшот отдаёт поле как есть, клиентский оверлей ждёт
  его). TODO(лобби): включить проверку `count(alive) ≤ 1` обратно и требовать
  `count(participants) ≥ 2`; winner — id последнего живого.

## 6. HTTP API (контракты клиент↔сервер)

| Эндпоинт | Метод | Вход | Ответ |
|---|---|---|---|
| `php/init.php` | GET | — | `{success, message, room, rules, abilities, autonomous_objects, passives, effects}`; сайд-эффект: только пересчёт THREAT_ZONE (мир не трогается); 503, если мир не собран |
| `php/init.php` | GET `?reset=1` / POST `{"reset":true}` | — | то же + поле `world_reset` (сводка сборки); сайд-эффект: полный rebuild Redis из изначального состояния; ошибки данных → 500 и мир НЕ изменён |
| `php/poll.php` | ★ удалён | доставка снапшотов — Centrifugo push (см. §4.3 и «Шина доставки» ниже) | |
| `php/action.php` | POST | JSON `{action: move\|attack\|ability\|special, player, x, y, ability?}` | `{success, message, ...}`; ошибки валидации — HTTP 400 + JSON (не fatal 500); CORS preflight обрабатывается; успех → `battle_publish_world` (мгновенный push всем) |
| `php/join.php` | POST | JSON-карточка `{uid (1..4), nickname, stats{HITPOINTS/MAX_HITPOINTS/ATTACK/RANGE: 1..10, SHIELD/MAX_SHIELD: 0..10 (0 = щита нет), RESISTANCE: 0..9 (0 = сопротивления нет), ACTIONS: 1..3}, abilities[3], passives[0..5]}` | `{success, player_id, slot, ws:{url, channel, token(JWT HS256), expires_in}}` (`rejoin:true` при повторном входе того же uid — тот же боец, новый токен); невалидная карточка → 400, занятые слоты (5-й игрок) → 409, мир не собран → 503; запись бойца — CAS по версии мира; успех/rejoin → публикация мира |
| `php/dump_state.php` | GET | — | `{success, keys{battle:*}}` — диагностика, **только чтение** (используют diag.html и OVER-HTTP режим интеграционного теста) |
| ~~`php/poll.php`~~ | — | ★ УДАЛЁН (переход на Centrifugo): доставка снапшотов — push в канал `battle:<PLAYER_ID>`; контракт снапшота сохранён 1-в-1 (§4.3) | |
| `php/debug.php` | GET | — | HTML-дамп диагностики движка, состава мира и живости тикера/Centrifugo (не JSON!), только чтение |

Все ответы — `application/json; charset=utf-8`, ошибки — `jsonError` (`{success:false,message}` +
HTTP code). `error_reporting(0)` в config.php — **ошибки PHP в stdout убивают JSON**, поэтому
вывод только через `jsonResponse` (он чистит буферы). Поле `rules` в ответах init и в each-снапшоте —
канон серверных констант (`rules_client_payload()`); клиент пока использует собственный `CONFIG`
(миграция на серверные правила — открытая задача, см. §7).

### Шина доставки Centrifugo (engine/publish.php)

- **Каналы**: персональные `battle:<PLAYER_ID>` (namespace `battle`, JWT-claim `channels` —
  боец подписан только на свой). Снапшот строится отдельно на каждого (§4.3) — клиентский
  `render(data)` не знает ничего о транспорте.
- **Токены**: `centrifugo_user_token(player_id)` — HS256 (`sub`, `exp`, `channels`), секрет общий
  с Centrifugo (`CENTRIFUGO_TOKEN_SECRET`); выдаёт join.php, клиент обновляет через идемпотентный
  rejoin (callback `token` centrifuge-js → `refreshRealtimeToken()`).
- **Публикации**: HTTP API (`CENTRIFUGO_API_URL`, заголовок `x-api-key`), stream context (без curl —
  работает под `php -n`), fail-soft. Точка вызова — `battle_publish_world`: тикер (каждая смена
  версии + heartbeat 1 с), join.php, action.php.
- **Константы транспорта** — в `engine/publish.php` (guard + env-переопределение `CENTRIFUGO_*`),
  НЕ в rules.php (это не игровые правила).
- **Тесты**: env `CENTRIFUGO_SINK_FILE=файл.jsonl` — публикации пишутся в JSONL вместо сети
  (integration шаг 4 проверяет содержимое снапшотов).

## 7. Константы и их дублирование

Сервер: **единственный владелец игровых правил — `engine/rules.php`** (guard-определения `if (!defined(...))`):
GRID 16×12, `MAX_MOVE_DISTANCE=5`, `MOVE_SPEED=3`, `TURN_DURATION=5`, `SHIELD_REGEN_PER_TURN=1`
(`LONG_POLL_TIMEOUT=25` удалён вместе с poll.php); префикс `battle:` живёт в `engine/world.php`.
Транспортные константы Centrifugo — `engine/publish.php` (guard + env `CENTRIFUGO_*`). В `config.php` игровых
констант больше нет (исторический конфликт двойного определения → fatal 500, не возвращать).
Клиент: `CONFIG` в `js/config.js` (GRID_*, CELL_SIZE) + `css/game.css` (размер клетки) —
**при изменении сетки/скоростей синхронизировать вручную**; сервер отдаёт те же значения в поле
`rules` ответов, но js/*.js их пока не читает (открытая задача миграции).
Также дублируется формула TURN (`previewTurn()` ↔ `rules_turn_formula()`) — менять одновременно.
Характеристики автономных объектов (турели/мины/бочки) живут только в реестре шаблонов
`getObjectTemplates()` (`systems/objects.php`) — клиент получает их проекцию через
`getAutonomousObjectDefinitions()`, дублировать числа в JS или в данных карт нельзя.

## 8. Точки расширения (проверенные рецепты)

- **Новая способность** → запись в `getAbilityDefinitions()` (`systems/abilities.php`) + строка
  `ИМЯ[range]` в ABILITIES игрока (сегодня — выбор в селекторе формы настройки `game.html`,
  приходит через `join.php`; резервно — `data/initial_snapshot.json`). Новый механизм — отдельный `case` в
  `applyAbility`; урон/эффекты — только через сервисы `combat_apply_damage` / `effects_apply`.
- **Новый статус-эффект (DOT/CC/modifier)** → только реестр в `systems/effects.php`
  (`kind`, длительность, модификаторы); блокировки движения берутся из `effects_blocks()`,
  DoT — из `effects_tick()`. Парсить `NEGATIVES` строками в сторонних файлах запрещено.
- **Новая пассивка** → `getPassiveDefinitions()` (`systems/passives.php`) + слушатель шины:
  `turn.start` (REGENERATION-подобные), `damage.query_mitigate` (ARMORED/ARMOR_CRUSH-подобные),
  `damage.dealt` (THORNS-подобные), `attack.resolved` (SPLASH-подобные),
  `movement.query_distance` (SWIFT/SLOWED-подобные). Регистрация — в `passives_register_hooks()`.
- **Новый автономный объект (турель/мина/spawn-объект)** → только `systems/objects.php`:
  шаблон типа в `getObjectTemplates()` (`category` + дефолтные характеристики + `image`) — он
  автоматически питает и `getAutonomousObjectDefinitions()` для клиента; расстановка — запись
  `{ID, IMAGE, POSITION_XY, ...}` в секцию карты комнаты; поле `ALLY` обязательно; урон — через
  `combat_apply_damage`; реакция на ход — слушатель `turn.start`. Появление объекта в бою —
  `objects_spawn_entity()` (сохранение состояния остаётся на вызывающем).
- **Новая комната** → `data/ROOM_ID.json` по образцу существующих (grid/exits/stairs/
  exit_positions/spawn_cells/objects/traps/npcs) + записи `exits` в картах соседних комнат; код
  править не нужно — карты подхватываются лениво (`room_get`), двери работают через exit-триггер,
  спавн-кольцо строится из `exit_positions`. Если в комнате стартует боец — его спавн выделяется
  `join.php` (слот uid → комната + клетка из `spawn_cells`); для резервного снимка — добавить
  `ROOM_ID`/`SPAWN_ROOM` в него и клетку в `spawn_cells`, затем пересобрать мир.
- **Подключение игрока / карточка персонажа** → клиент собирает карточку (форма `game.html`) и
  шлёт POST `join.php`; серверная половина валидации — там же (`JOIN_STATS_MIN/MAX`,
  `JOIN_MAX_PLAYERS`). Расширение состава карточки (экипировка, классы) = синхронная правка
  формы + `world_build_player_from_payload()`; переход на данные аккаунта из БД заменит только
  источник карточки, контракт эндпоинта сохранится.
- **Новое действие** → функция в нужной системе `systems/*` + case-роутинг + валидация в
  `action.php` + кнопка/отправка в `js/input.js`/`js/net.js`. Правила никогда не считать на клиенте.
- Любая мутация состояния → сохранение через `world_save()` (incr версии делает сам).

## 9. Инварианты и риски (чек-лист перед рефакторингом)

1. Не менять стек (см. §1) и не переносить логику на клиент.
2. Не разрывать граф зависимостей (§3): эндпоинты знают только про `engine/bootstrap.php`
   (+config как инфраструктуру); новые связи между системами — только шиной событий, порядок
   регистрации слушателей в bootstrap фиксирован.
3. Любой mutate состояния обязан заканчиваться `world_save()` (§4) — иначе публикация не уйдёт
   и подписчики зависнут на старом мире (инвариант «рост версии ⇔ доставка» теперь держит тикер).
4. `emitReduce`-слушатели обязаны принимать payload **по ссылке**; значение-копия = молчаливый
   no-op (исторические баги: залипшие STUNNED, неработающие зоны угрозы турели).
5. Тик мира идёт НЕ от запросов клиентов, а от демона `tools/ticker.php` (`world_tick_once`
   каждый виток, независимо от подключений) — не заводить «ленивый тик» в action/join: эффекты/ход
   обязаны двигаться и при нулевых клиентах. Тикер обязан быть запущен (init.php предупреждает
   по `battle:ticker_beat`); дублировать его несколькими копиями можно (CAS безопасен), но
   это лишние публикации.
6. Не ломать двухфазность ловушек/NPC (§5 «движение»): предсказание в processMove работает **только
   на копии** (`makeTrapPreviewState` + dry_run для NPC) — если перенести реальные эффекты туда,
   анимация клиента разъедется с уроном и NPC будут тратить ACTIONS в фазе предсказания.
7. Константы мира определяются только в `engine/rules.php` (guard-define); повторное `define`
   в любом другом файле = fatal 500 (уже случалось с config.php).
8. Координаты — строки `"x,y"` везде; целые числа в позициях могут стать дробными во время MOVING —
   парсить только через `parseXY/formatXY` (geometry.php).
9. Поля сущностей в CAPS — стиль данных, ожидаемый и движком, и js/render.js (`renderGrid`,
   `updatePlayerEntity`). Переименование поля = синхронная правка PHP + JS + initial_snapshot.json
   (бойцы) / карт комнат (сущности).
10. `status`-цепочки (`IDLE/MOVING/ATTACK/ABILITY/SPECIAL/STUNNED/DEATH/DECAY/REMOVED`, NPC
    `ATTACK/IDLE`, трапы `INTACT/TRIGGERED`, объекты `INTACT/DESTROYED`) читаются и сервером, и
    клиентом (CSS-классы по статусам) — новые статусы добавлять в оба конца. Инвариант
    STATUS↔NEGATIVES поддерживается сам в `turn_tick` — не чинить статусы вручную в системах.
11. Мёртвые/оглушённые не могут действовать (валидация в action.php) и не являются целями
    (фильтры DEATH/DECAY в objects/combat/targeting).
12. Не «чинить» раньше времени: вертикальные переходы (лестницы/этажи), интерактивные объекты и
    экипировка отсутствуют сознательно — это консервы, а не баги (подробно в ABOUT.md §7).
    Межкомнатные переходы и респаун в SPAWN_ROOM уже реализованы — не считать их заглушками.
13. После правок сервер не перезапускают, но **правки изначального состояния (карты комнат,
    `getObjectTemplates()`, снимок бойцов) применяются только после пересборки мира**:
    `php tools/reset_world.php` или `php/init.php?reset=1` — обычный заход через init.php Redis
    не перезаписывает.
14. Фасад `battle.php` — только делегаты; новый код туда не писать, но и удалять legacy-имена
    нельзя (их ожидает часть пути диагностики/debug).
15. С Этапа 1 состав бойцов в мире определяется подключениями: `join.php` — единственный
    HTTP-путь создания игрока (0 игроков = 0 персонажей, максимум `JOIN_MAX_PLAYERS` = 4 слота;
    пятый игрок получает 409). Запись мира из join.php идёт только через `world_save_cas`
    (CAS по версии + rebase, до 3 попыток) — прямая `world_save()` здесь конкурировала бы с
    тиком тикера и затирала бы чужие подключения. Повторный вход того же uid идемпотентен
    (`rejoin:true`), дубликат никнейма — 409. Сервер не доверяет клиенту: карточка проходит
    мировую валидацию (`world_builder_player_errors` / `world_build_player_from_payload`).
16. Открытые задачи: миграция `js/*.js` на серверное поле `rules`; включение экрана победы
    после появления лобби (§5 «Победа»); смоук-тест на стенде с реальным Redis (CLI-харнесс
    `tests/harness_engine.php` и HTTP-интеграл `tests/integration_join.php` работают on
    in-memory фейке Redis; `tests/run_integration_join.sh` гоняет тот же сценарий поверх
    `php -S`).
17. Тест-стенд: несколько вкладок `game.html` с разными uid (1..4 — слоты спавна, карточка
    заполняется на экране настройки); `php/debug.php` — диагностика загрузки движка
    (только чтение); проверка живости PHP/Redis; сброс боя —
    `php tools/reset_world.php` (CLI) или `init.php?reset=1` (HTTP, после reset состав
    бойцов пуст — игроков нужно подключать заново).

---
*Версия описания соответствует состоянию репозитория на момент последнего коммита.
При любом изменении архитектуры (модули, зависимости, протокол, ключи, контракты) — обновить
этот файл вместе с README.md/ABOUT.md.*
