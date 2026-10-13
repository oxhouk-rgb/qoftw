# BATTLE SYSTEM - Browser Game Engine

Браузерная пошаговая боевая система (PvP на сетке) для браузерной игры. Бэкенд — чистый PHP + Redis (состояние боя хранится в Redis, синхронизация клиентов через long polling). Фронтенд — HTML/CSS/JS без фреймворков (React/Node.js не используются и не требуются).

## Быстрый старт

### Требования
- PHP 7.4+ (с расширением phpredis)
- Redis сервер
- Любой веб-сервер с поддержкой PHP (Apache/Nginx или USBWebServer/OpenServer)

React, Node.js, npm и сборка **не нужны** — это статические файлы + PHP.

### Установка

1. **Запустите Redis:**
```bash
redis-server
```

2. **Разместите файлы проекта в корне веб-сервера** (структура уже готова к раздаче, копировать никуда не нужно):
```
/                       ← корень веб-сервера (docroot)
├── game.html           ← игровой клиент (основная страница игры)
├── diag.html           ← страница диагностики окружения
├── index.html          ← заглушка/обёртка (сборка из песочницы, игровая логика не содержит)
├── assets/             ← собранные артефакты index.html (не используются игрой)
├── css/game.css        ← стили клиента
├── js/game.js          ← клиент боевой системы (polling, рендер, ввод)
├── data/
│   ├── battledata.json
│   └── B1.json
└── php/
    ├── config.php
    ├── init.php
    ├── poll.php
    ├── action.php
    ├── battle.php
    ├── abilities.php
    ├── items.php
    └── debug.php
```

3. **Откройте в браузере:**
```
http://localhost/game.html
```

4. **Для мультиплеера:** откройте game.html в нескольких вкладках, выберите разных персонажей.

### Диагностика

Если бой не запускается:
- `diag.html` — проверка доступности PHP-эндпоинтов из браузера;
- `php/debug.php` — проверка версии PHP, наличия расширения redis и подключения к Redis (выводит ошибки напрямую, без JSON).

### Настройка Redis

Отредактируйте `php/config.php`:
```php
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_DB', 0);
```

## Структура проекта

### PHP файлы (`php/`)

| Файл | Назначение |
|------|-----------|
| `config.php` | Подключение Redis, константы игры (GRID 16×12, MOVE_SPEED, TURN_DURATION и др.), ключи `battle:*`, чтение/запись состояния, лог, JSON-ответы |
| `init.php` | Сброс Redis (flushDB) и загрузка `data/battledata.json` + `data/B1.json` в состояние боя |
| `poll.php` | Long polling endpoint + серверный тик (обновление движений, снарядов, хода, NPC, анимаций смерти) |
| `action.php` | POST-обработчик действий: `move` / `attack` / `ability` |
| `battle.php` | Перемещения (плавные, с проверкой стен/игроков), атаки, тики, ловушки при движении, смерть/возрождение, снаряды, проверка победы |
| `abilities.php` | Реестр способностей и их применение (instant/beam/line/area), расчёт урона, негативные эффекты, AoE-мишени |
| `items.php` | Пассивки, негативные эффекты, начало хода, splash/thorns/puncture, ловушки, NPC AI и зоны угрозы |
| `debug.php` | Отладочная страница: проверка PHP, расширения Redis и соединения |

### Фронтенд

- `game.html` + `js/game.js` + `css/game.css` — клиент игры: выбор персонажа, вызов `init.php`, цикл `poll.php?v=N&player=ID`, отправка действий в `action.php`, отрисовка сетки, анимации движения/снарядов/смерти, UI (HP/щит/ACTIONS, способности, лог).
- `diag.html` — диагностика.
- `index.html` + `assets/` — служебная обёртка-заглушка (артефакты готовой сборки), к боевой системе отношения не имеет.

### Данные (`data/`)

- `battledata.json` — 4 тестовых персонажа (PLAYER1–PLAYER4), объекты (бочки), ловушка (мина), NPC (турель)
- `B1.json` — карта комнаты B1: сетка 16×12, вертикальная стена (столбец 7), выходы/лестницы `7,0 → A1` и `7,11 → C1`

## Игровые механики

### Сетка
- 16 столбцов × 12 строк (константы `GRID_WIDTH` / `GRID_HEIGHT`)
- Комната B1: вертикальная стена в столбце 7 (кроме крайних клеток), выходы-лестницы `7,0 → A1` и `7,11 → C1` (переход между комнатами пока в заглушке — только логируется)
- Стены блокируют путь (0 в карте)

### Ход
- Каждые `TURN_DURATION` (5) секунд ACTIONS восстанавливаются до MAX_ACTIONS
- Каждое действие (move/attack/ability) = 1 очко ACTIONS
- Кулдаунов на способности нет — только стоимость в очках действий

### Перемещение
- До `MAX_MOVE_DISTANCE` (5) клеток за 1 действие
- Только по прямой (вверх/вниз/влево/вправо)
- Нельзя проходить сквозь стены/игроков
- Во время движения сервер плавно обновляет позицию (`MOVE_SPEED` = 3 клетки/сек) и проверяет ловушки и зоны угрозы NPC на каждой пройденной клетке

### Атака
- В пределах RANGE (для BLINDED — RANGE/2)
- Формула урона (`calculateDamage`): `ceil(max(ATTACK, 1) × (1 − RESISTANCE×10%))`, сопротивление макс. 90%, минимум 1 урон
- Урон сначала списывает SHIELD, затем HITPOINTS; при смерти — DEATH-анимация и возрождение на `SPAWN_ROOM`

### Способности
- 4 механизма применения: instant (без снаряда, BACKSTAB/SHIELD BASH/FORTIFY), beam (точечный луч), line (линия, задевает всех на пути), area (область по радиусу AoE)
- Типы урона: physical / fire / ice / poison
- Могут накладывать негативные эффекты, а также баффы: self_buff (FORTIFY — +3 щита), ally_buff (WAR CRY — +2 ATK союзникам в области)
- Все снаряды создаются в состоянии и визуализируются клиентом

### Пассивки
| Название | Эффект |
|----------|--------|
| REGENERATION | +1 HP/ход |
| ARMORED | -1 к полученному урону |
| SPLASH | 1 урон соседним врагам при атаке |
| THORNS | 1 урон атакующему при получении урона |
| SWIFT | +1 к дальности перемещения |
| PUNCTURE | атака пробивает всех врагов на пути до цели |

### Негативные эффекты
| Эффект | Действие |
|--------|----------|
| FROZEN[N] | -1 ACTION каждый ход, N ходов |
| POISONED[N] | -1 HP/ход, N ходов |
| BLEED[N] | -1 HP/ход, N ходов |
| STUNNED[N] | 0 ACTIONS, N ходов |
| SLOWED[N] | -2 к дальности перемещения, N ходов |
| BLINDED[N] | дальность атаки/способностей /2, N ходов |

### Объекты, ловушки и NPC
- **Объекты** (бочки, `objects`): имеют HP, разрушаются от урона, блокируют клетки
- **Ловушки** (`traps`): мина MINE1 — при входе на клетку наносит урон и накладывает эффекты (STUNNED[2], BLEED[2]) по радиусу RANGE
- **NPC** (`npcs`): турель TURRET1 — AI в `processNPCs()`, сама выбирает цель и атакует; есть «зоны угрозы» (`calculateThreatZone` / `checkThreatZoneIntersection`) — вход игрока в зону провоцирует NPC

## Тестовые персонажи

### PLAYER1 - NAPALM UNIT 🔴
- HP: 10 | ATK: 5 | RES: 3 | Shield: 5 | Actions: 2 | Range: 5
- Способности: Fire Wave[3], Shock Granade[4], Napalm Granade[5]
- Пассивки: Puncture
- Союзник: PLAYER2

### PLAYER2 - FROST KNIGHT 🔵
- HP: 8 | ATK: 4 | RES: 5 | Shield: 3 | Actions: 3 | Range: 3
- Способности: Ice Shard[3], Frost Nova[2], Blizzard[5]
- Пассивки: Armored, Splash, Puncture
- Союзник: PLAYER1

### PLAYER3 - SHADOW ROGUE 🟢
- HP: 6 | ATK: 7 | RES: 2 | Shield: 0 | Actions: 3 | Range: 2
- Способности: Backstab[1], Smoke Bomb[3], Poison Blade[2]
- Пассивки: Splash
- Союзник: нет (FREE FOR ALL)

### PLAYER4 - IRON GUARD 🟡
- HP: 10 | ATK: 3 | RES: 7 | Shield: 8 | Actions: 1 | Range: 2
- Способности: Shield Bash[1], Fortify[0], War Cry[3]
- Пассивки: Armored, Regeneration, Splash
- Союзник: нет (FREE FOR ALL)

Цифра в скобках `[N]` — стоимость способности в очках ACTIONS.

## Расширение

### Добавить способность
1. Добавьте определение в `abilities.php` → `getAbilityDefinitions()`
2. Добавьте в ABILITIES игрока в `battledata.json`

### Добавить пассивку
1. Добавьте определение в `items.php` → `getPassiveDefinitions()`
2. Добавьте обработку в `processTurnStart()` или `applyPassiveDefense()`
3. Добавьте в PASSIVES игрока в `battledata.json`

### Добавить комнату
1. Создайте `data/ROOM_ID.json` по аналогии с `B1.json`
2. Добавьте выход в `exits` текущей комнаты
3. Добавьте обработку перехода в `battle.php` → `updateMovements()`

### Добавить NPC
1. Добавьте в `npcs` в `battledata.json`
2. AI обрабатывается в `items.php` → `processNPCs()`

## API и синхронизация (Long Polling)

### Эндпоинты

| Эндпоинт | Метод | Описание |
|----------|-------|----------|
| `php/init.php` | GET | Сброс и инициализация боя из `data/*.json` (вызывается при входе первого игрока) |
| `php/poll.php?v=VERSION&player=ID` | GET | Долгое ожидание изменений состояния + серверный тик |
| `php/action.php` | POST (JSON) | Действия: `{"action":"move","player":"PLAYER1","x":3,"y":5}`, аналогично `attack`, для `ability` поле `"ability":"FIRE WAVE"` |
| `php/debug.php` | GET | Диагностика PHP/Redis |

### Как работает polling

Клиент запрашивает `poll.php?v=VERSION&player=PLAYER_ID`, сервер держит соединение до:
- Появления новой версии (любое успешное действие или начало нового хода инкрементируют `battle:version`)
- Истечения таймаута (`LONG_POLL_TIMEOUT` = 25 секунд)

Каждый цикл poll на сервере выполняет тик:
1. Если прошло `TURN_DURATION` (5) сек — новый ход: `processTurnStart()` (регенерация, негативные эффекты, восстановление ACTIONS) → `processNPCs()`, версия инкрементируется;
2. Ежецикловое обновление: `updateMovements()` → `updateDeathAnimations()` → `updateProjectiles()` → `resetAttackStatus()`;
3. Если версия выросла — полное состояние отправляется клиенту.

Все состояние лежит в Redis под префиксом `battle:` (players, objects, traps, npcs, room, turn_time, projectiles, version, log).

## Статус проекта / план развития

Реализовано: боевая система на сетке с полным циклом (ход, движение, атаки, способности, эффекты, пассивки, ловушки, NPC), мультиплеер через long polling, клиентская отрисовка и анимации.

TODO в коде:
- Переход между комнатами по выходам/лестницам — сейчас только логируется (заглушка в `updateMovements()`)
- Комнаты A1/C1 и SPAWN-зона — карты отсутствуют в `data/`
- Возврат способностей PLAYER1 (Regeneration/Armored) и PLAYER3 (Regeneration) — пассивки в бою пересмотрены
