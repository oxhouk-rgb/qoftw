# AGENTS.md — Протокол работы для LLM-ассистентов (читать ПЕРВЫМ)

> Это единственная инструкция, которую ассистент обязан прочитать перед любой работой в репозитории.
> Она говорит **что читать дальше**, **чего не читать вообще** и **как экономить вызовы инструментов**.

---

## 1. Порядок чтения контекста (по типам задач)

| Задача | Что открыть | Чего НЕ трогать |
|---|---|---|
| Любая правка серверной логики | `docs/LLF.md` → нужные функции из `php/` | README целиком, ABOUT целиком |
| Быть в курсе идеологии/табу | `ARCH.md` §1 + `ABOUT.md` §2 «Табу» (только эти блоки, см. команды ниже) | остальные разделы ABOUT |
| Правка клиента (js/) | `docs/LLF.md` (раздел js) → нужный файл `js/*.js` | game.js целиком (если уже разбит — см. §4) |
| Новый эндпоинт / ключ Redis / протокол | `ARCH.md` §3–§7 | — |
| Запуск/установка/эндпоинты с нуля | `README.md` «Быстрый старт» (первые ~60 строк) | остальной README |

Команды точечного чтения вместо просмотра файлов целиком:

```bash
grep -n "^#\+ " ARCH.md            # карта разделов
sed -n '13,23p' ARCH.md            # только «Табу и ограничения»
sed -n '51,59p' ABOUT.md           # только идеологические табу
grep -rn "function имя_функции" php/ js/   # найти функцию без чтения файла
```

## 2. Карта проекта в одну строку на файл

```
game.html / diag.html             — страницы клиента (вход: game.html)
css/game.css                        — все стили клиента
js/                                 — КЛИЕНТ, разбит на модули (см. §5): config, state, utils,
                                      grid, path, animation, render, input, net, main
data/initial_snapshot.json          — ИЗНАЧАЛЬНЫЕ бойцы; с Этапа 1 секция players пуста ({"players": []}) —
                                      персонажей «без владельца» в мире нет, боец создаётся при подключении
php/join.php                        — ★ подключение игрока (Этап 1): POST-карточка (uid/nickname/stats/
                                      abilities/passives) из формы game.html → спавн бойца (CAS), лимит 4 слота
data/{A1,A5,B1,E1,E5}.json          — карты комнат (grid: 1=проходимо, 0=стена; exits/exit_rooms,
                                      spawn_cells, секции objects/traps/npcs — расстановка мира)
php/config.php                      — Redis-коннект, ключи battle:*, JSON-хелперы, лог
php/engine/world_builder.php        — ★ сборщик ИЗНАЧАЛЬНОГО состояния мира (код+карты+снимок)
php/engine/world_reset.php          — ★ перезапуск сессии: build + write (flushDB, version=1)
tools/reset_world.php               — CLI сброс мира (тот же код, что init.php?reset=1)
php/init.php                        — GET: проверка готовности мира; ?reset=1 — явный перезапуск
php/poll.php                        — GET long-poll + серверный тик + snapshot комнаты игрока
php/action.php                      — POST: валидация + роутинг move/attack/ability/special
php/debug.php                       — диагностика окружения (GET)
php/battle.php                      — фасад обратной совместимости (require всего движка)
php/engine/rules.php                — чистые формулы (ход, урон, поворот)
php/engine/geometry.php             — расстояния, LOS, линии
php/engine/events.php               — событийная шина (ядро ↔ системы)
php/engine/world.php                — все операции с Redis (battle:* ключи)
php/engine/turn.php                 — машинерия хода: тайминги, фазы, server clock
php/engine/bootstrap.php            — сборка движка: порядок require + подписки на события
php/systems/effects.php             — статус-эффекты/DOT/CC
php/systems/combat.php              — атака, урон, щиты, смерть
php/systems/movement.php            — перемещение, effective range, триггер выхода
php/systems/passives.php            — пассивные способности
php/systems/targeting.php           — выбор целей, дальности способностей
php/systems/abilities.php           — реализация способностей
php/systems/objects.php             — бочки/мина/турель
php/systems/rooms.php               — комнаты, двери, телепорт, респаун
tests/harness_engine.php            — CLI-харнесс сценарных тестов (in-memory фейк Redis)
docs/LLF.md                         — ★ индекс всех функций (имя → файл:строка → назначение)
ARCH.md / ABOUT.md / README.md      — справочники; читать ВЫБОРОЧНО по таблице §1, не целиком
```

## 3. Железные правила экономии вызовов

1. **Никогда не читай md-файлы целиком.** Только нужный раздел через `grep -n "^#" файл` + `sed -n 'A,Bp'`.
2. **Не просматривай файл целиком, если знаешь имя функции** — делай `grep -rn "function <имя>" php/ js/`, затем читай ±40 строк вокруг (`sed -n 'N,Mp'`).
3. **Правки — только через точечный str_replace** со скопированными из файла строками. Никаких перезаписей файлов целиком.
4. **Один инструмент на вопрос.** Не делай «на всякий случай» дополнительных чтений; если данных достаточно — действуй.
5. **Проверяй тестами, а не перечитыванием:** после правки — `php tests/harness_engine.php` (и `php -l изменённого_файла`).
   Если PHP недоступен в окружении — минимум: `node --check js/*.js`, `python3 tests/verify_llf.py`.
6. **Обновляй `docs/LLF.md` и `ARCH.md` в том же коммите**, что и изменение архитектуры/функций (правило поддержки ARCH.md).
   После любой смены состава/строк функций: `bash docs/gen_llf.sh && python3 tests/verify_llf.py`
   (верификатор сверяет индекс LLF.md с реальным кодом: имена, номера строк; exit 0 = индекс честный).

## 4. Табу стека (короткая версия; полная — ARCH.md §1)

❌ React/Vue/Angular, ❌ Node.js/npm/сборщики, ❌ игровая логика на клиенте,
❌ WebSocket/SSE вместо long polling, ❌ файловое состояние вместо Redis, ❌ PHP-фреймворки.
Клиент выполняет ровно три роли: **polling, рендер, ввод**. Единственная допустимая «копия серверной
формулы» на клиенте — `previewTurn()`; локальные проверки пути (`isPathClear` и т.п.) — только UX.

## 5. Клиентская архитектура (game.js РАЗБИТ на модули — выполнено)

`game.html` грузит модули в порядке (обычные `<script>`, без npm/сборщиков — идеология не нарушается):

```
js/config.js       — CONFIG (пути к php/, размеры сетки, CELL_SIZE)
js/state.js        — глобальный state + effectiveMoveRange()
js/net.js          — initGame, joinGame (POST join.php с карточкой персонажа), startPolling, poll,
                     nextActionId, sendAction (retry-логика)
js/grid.js         — buildGrid/rebuildGrid, findEntity, inCurrentRoom, purgeOutOfViewRoomEntities
js/path.js         — isPathClear, isCellFreeForMove, hasLineOfSight (UX-проверки)
js/input.js        — onCellClick, checkAvailableActions, showActionDialog, closeDialog
js/render.js       — render, highlightPlayerZones, renderGrid, create*Entity, render*UI, checkVictory
js/animation.js  — previewTurn, applyTurnClass, startMoveAnimation, animateMove
js/utils.js        — parseXY, parseAbilityName/Range, showNotification
js/main.js         — точка входа: экран настройки персонажа (Этап 1) — validateCharacterForm /
                     collectCharacterCard → joinGame; после подключения — initGame + цикл боя
```

Правило: новый код клиента кладётся в соответствующий модуль; если файл растёт > ~800 строк —
выносить подобласть в отдельный модуль по той же схеме, а не наращивать гиганта.

## 6. Порядок подключения игрока (Этап 1) — не путать с legacy-флоу

Раньше: game.html выбирал одного из 4 заготовленных бойцов и сразу звал `init.php?player=PLAYERn`.
Сейчас (актуальный флоу, см. `js/main.js` / `js/net.js`):

1. Экран настройки `game.html`: `validateCharacterForm` → `collectCharacterCard` (uid 1..4,
   никнейм, stats 1..10 / ACTIONS 1..3, ровно 3 способности, 0–5 пассивок).
2. `joinGame(card)` → POST `php/join.php`: сервер валидирует карточку, выделяет слот спавна
   (uid → комната A1/A5/E1/E5), пишет бойца через CAS (`world_save_cas`). Ответы:
   400 — битая карточка; 409 — ник занят / все 4 слота заняты (пятый игрок не пройдёт);
   повторный вход того же uid идемпотентен (`rejoin:true`).
3. Только после успешного join: `initGame(player_id)` → `init.php` (без player-параметра
   состава — состав уже в мире) → цикл `poll.php?v=N&player=PLAYER_ID`.

Последствия для правок: состав боя в Redis меняется подключениями, а не снимком
(`data/initial_snapshot.json` изначально пуст); после `reset` мира бойцов нет — игроков
нужно подключать заново. Никаких персонажей «без владельца» создавать нельзя
(0 игроков = 0 персонажей; NPC/объекты/ловушки — исключение, владельца не требуют).
