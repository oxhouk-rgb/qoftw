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

```
Клиент (браузер, вкладка = игрок)              Сервер (PHP-FPM/Apache/Nginx)         Хранилище
┌───────────────────────────────┐   GET  ┌─────────────────────────────────┐
│ game.html  — точка входа       │ ─────▶ │ init.php   — сборка состояния    │ ──flushDB──┐
│ js/game.js — poll/render/input │        │            из data/*.json        │           ▼
│ css/game.css — визуал/анимации │   POST ├─────────────────────────────────┤      ┌──────────┐
│ diag.html  — диагностика       │ ─────▶ │ action.php — move/attack/ability │      │  Redis   │
└───────────────────────────────┘        │            → battle.php          │      │ battle:* │
        ▲                                ├─────────────────────────────────┤      └──────────┘
        │ GET poll.php?v=N&player=ID     │ poll.php   — long-poll + тики    │           ▲
        └─────────────────────────────── │            → battle.php          │           │
                                         ├─────────────────────────────────┤           │
                                         │ battle.php — ЯДРО (см. §4)       │───────────┘
                                         │  ├─ abilities.php (урон/эффекты) │
                                         │  ├─ items.php     (пассивки/ход) │
                                         │  └─ objects.php   (NPC/ловушки)  │
                                         ├─────────────────────────────────┤
                                         │ config.php  — общий слой (§3)    │
                                         │ debug.php   — дамп окружения/сост.│
                                         └─────────────────────────────────┘
Данные (статические): data/battledata.json (игроки/объекты/ловушки/NPC), data/B1.json (карта 16×12)
```

### PHP-модули (`php/`)

| Файл | Роль | Ключевые функции |
|------|------|------------------|
| `config.php` | Общий слой: константы мира, подключение Redis, сериализация состояния, JSON-ответы, логи | `getRedis()`, `getBattleState()`, `saveBattleState()` (**incr version!**), `addLog()`, `parseXY()/formatXY()`, `isValidPosition()`, `jsonResponse()/jsonError()`, `setCorsHeaders()` |
| `init.php` | Точка входа жизненного цикла боя: `flushDB` → читает `data/battledata.json` + `data/B1.json` → вычисляет `THREAT_ZONE` NPC → пишет состояние в Redis | вызывается клиентом при выборе персонажа |
| `poll.php` | Long-polling endpoint + «серверные часы»: цикл тиков до изменения версии или таймаута | цикл: `processTurnStart` → `processNPCs` → `updateMovements` → `updateDeathAnimations` → `updateProjectiles` → `resetAttackStatus` → `updateNpcStatuses`; отдаёт полный snapshot |
| `action.php` | HTTP-вход всех действий игрока (POST JSON `{action, player, x, y[, ability]}`) | роутинг: `move→processMove`, `attack→processAttack`, `ability→applyAbility` (+ проверка наличия способности, ACTIONS, STATUS); сохранение с retry ×3 |
| `battle.php` | **Ядро боевой логики**; подключает abilities/items/objects | `processMove`, `calculatePath`, `isPlayerAtPosition`, `processAttack`, `updateMovements`, `makeTrapPreviewState`, `updateDeathAnimations`, `updateProjectiles`, `resetAttackStatus`, `checkVictory` |
| `abilities.php` | Реестр способностей + 4 механизма применения + урон/эффекты/выбор целей | `getAbilityDefinitions`, `applyAbility`, `applyInstant/Beam/Line/AreaAbility`, `calculateDamage`, `applyDamageToTarget`, `applyNegativeEffect`, `findTargetAtPosition`, `getTargetsOnLine`, `getTargetsInArea`, `isCellPassable` |
| `items.php` | **ТОЛЬКО пассивки игроков** + оркестрация начала хода (реген щита/HP, негативы, DoT; Actions NPC; вызов зон угрозы) | `getPassiveDefinitions`, `processTurnStart`, `processNegatives`, `applyPassiveDefense`, `applySplashDamage`, `hasPassive`, `getPassiveValue` |
| `objects.php` | Автономные объекты: NPC (зоны угрозы, LOS, ответный огонь) и ловушки. Задел под spawn-объекты от способностей | `getAutonomousObjectDefinitions`, `calculateThreatZone`, `hasLineOfSightNPC`, `checkThreatZoneIntersection`, `updateNpcStatuses`, `processNPCs` (stub, совместимость), `processTraps` |
| `debug.php` | Диагностика: версия PHP, расширение redis, соединение, сырой дамп состояния | standalone-страница, не часть игрового цикла |

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

## 3. Зависимости между PHP-модулями (граф require_once)

```
config.php ◀──────────────┬─────────┬──────────┬─────────┬──────────┐
                          │         │          │         │          │
                       init.php  poll.php  action.php  debug.php    │
                          │         │          │                     │
                          ▼         ▼          ▼                     │
                       battle.php ◀═══════════╝                     │
                       ╱   │   ╲                                    │
              abilities.php │  objects.php ◀─── (использует calcDamage│
                  ▲         │        ▲             /applyNegative из  │
                  │      items.php ──┘              abilities.php)   │
                  └─────────┘  (items вызывает checkThreatZoneIntersection)
```

- `battle.php` единственный подключает `abilities.php` + `items.php` + `objects.php`
  (`require_once` в шапке). Поэтому `poll.php`/`action.php` требуют только `battle.php` —
  **не добавляйте прямые `require_once` этих файлов в эндпоинты**.
- Порядок внутри battle.php: abilities → items → objects. `items.php` вызывает
  `checkThreatZoneIntersection()` из objects.php (оркестрация начала хода); `objects.php`
  использует `calculateDamage`/`applyNegativeEffect` из abilities.php. Это допустимая
  «мягкая» циклическая связность — держится на require_once через battle.php.
- Разделение ответственности (жесткое правило): **логика автономных объектов — только в
  `objects.php`**; `items.php` — только пассивки игроков. Новые типы spawn-объектов добавлять
  в `getAutonomousObjectDefinitions()` (objects.php), а не в items.php.

## 4. Поток данных и протокол синхронизации

1. **init**: клиент → `GET php/init.php` → flushDB → загрузка JSON → расчёт THREAT_ZONE →
   `saveBattleState()` (version=1). Каждая новая вкладка перезапускает бой с нуля (осознанно).
2. **poll-loop** (`poll.php?v=<clientVersion>&player=<ID>`): сервер держит соединение ≤25 с
   (`LONG_POLL_TIMEOUT`, `set_time_limit` снят), каждые 0.1 с: читает состояние → поцикловые
   тики движка (`turn_tick`: movement_update → death chain → projectiles → attack reset →
   npc statuses) → при `now-turn_time ≥ TURN_DURATION(5)` новый ход (`turn_check_advance` →
   `turn_start` + emit `turn.start`: passives/effects/combat/objects-слушатели) → world_save
   (incr version). Если version изменилась, отдаёт **полный flat snapshot**
   (`version, rules, room, grid, objects, traps, npcs, players, projectiles, log[-30],
   turn_time, turn_duration, time_to_next_turn, victory`) — КЛЮЧИ ОТВЕТА НА ВЕРХНЕМ УРОВНЕ,
   без обёртки `data` (клиентский `render(data)` читает `data.players` и т.д. напрямую;
   исторический контракт). Иначе по таймауту отдаёт тот же snapshot с `timeout:true`.
3. **action**: клиент → `POST php/action.php` (JSON) → валидация → функция battle.php мутирует
   `$state` (by-ref) → при успехе `addLog` + `saveBattleState` (retry ×3) + `incr REDIS_VERSION` →
   все зависшие poll мгновенно отдают новый мир.
4. **Контракты**: любой mutate состояния **обязан** закончиться инкрементом версии
   (`saveBattleState` делает `incr` сам; `processTurnStart`-цикл в poll — тоже). Забыли incr →
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
- **Формула урона** (`calculateDamage`): `ceil(max(ATTACK,1) × (1 − RESISTANCE×10%))`, RES≤90%,
  минимум 1; далее ARMORED −1; щит гасит первым; THORNS/SPLASH — контур и разброс (items.php).
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
| `php/init.php` | GET | — | `{success, message, players[], room}`; сайд-эффект: полный rebuild Redis |
| `php/poll.php` | GET | `v` (int версия клиента), `player` (PLAYER*) | snapshot (§4.2), опционально `timeout:true` |
| `php/action.php` | POST | JSON `{action: move\|attack\|ability, player, x, y, ability?}` | `{success, message, ...}`; CORS preflight обрабатывается |
| `php/debug.php` | GET | — | HTML-дамп (не JSON!) |

Все ответы — `application/json; charset=utf-8`, ошибки — `jsonError` (`{success:false,message}` +
HTTP code). `error_reporting(0)` в config.php — **ошибки PHP в stdout убивают JSON**, поэтому
вывод только через `jsonResponse` (он чистит буферы).

## 7. Константы и их дублирование

Сервер: `config.php` — GRID 16×12, `MAX_MOVE_DISTANCE=5`, `MOVE_SPEED=3`, `TURN_DURATION=5`,
`LONG_POLL_TIMEOUT=25`, префикс `battle:`.
Клиент: `CONFIG` в `js/game.js` (GRID_*, CELL_SIZE) + `css/game.css` (размер клетки) —
**при изменении сетки/скоростей синхронизировать вручную** (общего конфига нет, это осознанно).
Также дублируется формула TURN (`previewTurn()` ↔ battle.php/action.php) — менять одновременно.

## 8. Точки расширения (проверенные рецепты)

- **Новая способность** → запись в `getAbilityDefinitions()` (abilities.php) + строка `ИМЯ[range]`
  в ABILITIES игрока в battledata.json. Новый механизм — отдельный `case` в `applyAbility`.
- **Новая пассивка** → `getPassiveDefinitions()` + хук в нужную точку: `processTurnStart`
  (on_turn_start), `applyPassiveDefense` (on_damage_taken), `applySplashDamage` (on_attack),
  `processMove` (SWIFT/SLOWED-подобные модификаторы движения).
- **Новый автономный объект (турель/мина/spawn-объект)** → только `objects.php`:
  `getAutonomousObjectDefinitions()` + данные в battledata.json + поле `ALLY`.
- **Новая комната** → `data/ROOM_ID.json` по образцу B1.json + `exits` текущей + обработка перехода
  в `updateMovements()` (сейчас там заглушка-лог).
- **Новое действие** → функция в `battle.php` + case в `action.php` + кнопка/отправка в `game.js`.
  Правила никогда не считать на клиенте.
- Любая мутация состояния → `saveBattleState()` (или явный `incr REDIS_VERSION`).

## 9. Инварианты и риски (чек-лист перед рефакторингом)

1. Не менять стек (см. §1) и не переносить логику на клиент.
2. Не разрывать граф require_once (§3): эндпоинты знают только про config+battle.
3. Не забыть `incr version` после мутации (§4) — иначе зависшие poll не проснутся.
4. Не ломать двухфазность ловушек/NPC (§5 «движение»): предсказание в processMove работает **только
   на копии** — если перенести реальные эффекты туда, анимация клиента разъедется с уроном.
5. Координаты — строки `"x,y"` везде; целые числа в позициях могут стать дробными во время MOVING —
   парсить только через `parseXY/formatXY`.
6. Поля сущностей в CAPS — стиль данных, ожидаемый и battle.php, и js/game.js (`renderGrid`,
   `updatePlayerEntity`). Переименование поля = синхронная правка PHP + JS + battledata.json.
7. `status`-цепочки (`IDLE/MOVING/ATTACK/ABILITY/STUNNED/DEATH/DECAY/REMOVED`, NPC
   `ATTACK/IDLE`, трапы `INTACT/TRIGGERED`, объекты `INTACT/DESTROYED`) читаются и сервером, и
   клиентом (CSS-классы по статусам) — новые статусы добавлять в оба конца.
8. Заглушки — не баги: межкомнатные переходы, комната SPAWN, интерактивные объекты, отсутствие
   экипировки (подробно в ABOUT.md §7). «Чинить» их без задачи — вне объёма.
9. После правок сервер не перезапускают: достаточно нового захода через init.php (пересобирает Redis).
10. Тест-стенд: несколько вкладок `game.html` с разными PLAYER*; `diag.html`/`php/debug.php` —
    проверка живости PHP/Redis.

---
*Версия описания соответствует состоянию репозитория на момент последнего коммита.
При любом изменении архитектуры (модули, зависимости, протокол, ключи, контракты) — обновить
этот файл вместе с README.md/ABOUT.md.*
