# REFACTOR_PROPOSAL.md — Проект рефакторинга архитектуры (движок → масштабируемость)

> **СТАТУС: ПРИНЯТ И РЕАЛИЗОВАН (кроме отмеченного ниже).** Рефакторинг выполнен: код переведён
> на целевую двухслойную архитектуру `engine/ + systems/` с событийной шиной. Актуальное описание
> состояния — в `ARCH.md` (§2–§3); этот документ сохранён как история решения и чек-лист остатков.
> Отличия реализации от первоначального плана:
> - HTTP-эндпоинты оставлены на прежних путях (`php/init.php`, `php/poll.php`, `php/action.php`) —
>   переезд в `public/` сознательно отложен, чтобы не ломать клиентский `CONFIG.PHP_URL`.
> - `systems/projectiles.php` не выделен — снаряды/анимации смерти живут в `engine/turn.php` +
>   `systems/movement.php`.
> - Данные способа загрузки: `world_init()` в `engine/world.php` вместо отдельного `data/loader.php`.
> - **Не выполнено**: Шаг 0 (smoke-тест `tests/smoke.php`) и миграция клиента на серверные `rules`
>   (правила уже отдаются в ответах init/poll — читать их в game.js предстоит).
> - Бонус сверх плана: пассивки ARMOR_CRUSH (пробитие сопротивления, взаимоисключение с ARMORED)
>   и переработанная ARMORED (÷2 урона только от ARMOR_CRUSH-атак) — реализованы слушателями шины.
> Читать вместе с `ARCH.md` (там описание текущего состояния).
> Правило поддержки: при дальнейших изменениях архитектуры обновлять и `ARCH.md`, и статусы здесь.

---

## 0. Вердикт: является ли текущая архитектура «нагромождением»?

**Нет, это не нагромождение.** Разделение config / endpoints / core / modules уже существует,
все ~35 функций имеют документацию, инварианты (version++, приоритеты статусов, две фазы движения)
зафиксированы в комментариях. Это аккуратный прототип.

**Но как «будущий движок» у него 6 структурных проблем** (подтверждены чтением кода):

| # | Проблема | Где видно в коде |
|---|----------|-----------------|
| P1 | **Логика эффектов размазана по 4 файлам.** Формат `NAME[duration]` парсится отдельно в `abilities.php::applyNegativeEffect` и `items.php::processNegatives`; семантика STUNNED живёт в 6 местах: `abilities.php:479` (немедленная активация), `items.php:144` (ход), `battle.php:31,40,54` (блокировки движения), `battle.php:414-420,477-482` (восстановление после финала MOVING), `action.php:112`. Каждый новый эффект (огнестойкость, оглушение-без-прерывания-движения) придётся пропихивать switch'ами во все эти точки. | grep `STUNNED\|NEGATIVES` |
| P2 | **Дублирование механик.** Щит→HP→DEATH написан дважды (`abilities.php::applyDamageToTarget` и `objects.php::processTraps`); реген щита есть и в `processTurnStart`, и внутри пассивки `REGENERATION_SHIELD`; лог выбора целей (`findTargetAtPosition/getTargetsOnLine/getTargetsInArea`) завязан на поле `ALLY` игрока и не работает для npc/object-целей. | abilities.php:383-440 vs objects.php:344-378 |
| P3 | **battle.php = ядро + тикер + HTTP-оркестратор одновременно.** 684 строки: правила движения, серверные часы (`updateMovements/updateDeathAnimations/updateProjectiles/resetAttackStatus`) и бизнес-логика эндпоинта (валидация входа, retry ×3) в одном namespace. poll.php/action.php вызывают функции из battle.php напрямую — нет единой точки входа в «такт мира». | battle.php целиком |
| P4 | **items.php — misnomer + свалка оркестрации.** Файл «только про пассивки» содержит `processTurnStart` (общий ход мира: ACTIONS, регены, негативы, NPC-экшены, зоны угрозы) и 200 строк `applySplashDamage` со **стади-логированием каждого шага цикла** («DEBUG: проверяем игрока X» на каждую клетку/игрока) — при полном логе это десятки записей на один удар. | items.php:69-393 |
| P5 | **Коммуникация модулей — через глобальное состояние и обратные вызовы.** abilities вызывает `applyPassiveDefense` (из items) и та требует abilities «через battle.php» — граф зависимостей реально циклический, спасает только то, что require_once идёт из battle.php по фиксированному порядку. Нет событий/хуков: любое новое взаимодействие (пассивка на крити, триггер ловушки на смерть) = правка чужого файла. | комментарии в objects.php:25-26 |
| P6 | **Магия форматов и констант в строках.** `ABILITIES => 'DASH[3]'` (range внутри имени!), `NEGATIVES => 'STUNNED[2]'`, `strpos($neg,'FROZEN[')===0` вместо типизированных структур; `TURN`-формула и `maxMove=5` дублированы сервер↔клиент (game.js:486,764 с TODO «учесть SWIFT/SLOWED»). | abilities.php:180-186, game.js |

Отдельно: **нет ни одного теста**, отладка — только `debug.php` + игровой лог. Для движка это главный
риск при рефакторинге: ломать будет нечем проверить.

---

## 1. Целевая архитектура (тот же стек!)

Ключевая идея: **развязать по слоям и ввести единственный канал взаимодействия между системами —
события (hooks)**. Ни одна система не знает о существовании другой; боевая система знает только,
что «нанесла damage», эффекты знают только, что «произошёл tick», и т.п.

```
php/
├── public/                  ← HTTP-слой (тонкие контроллеры, только ввод/вывод)
│   ├── init.php  poll.php  action.php  debug.php
├── engine/                  ← ЯДРО (не знает про HTTP и Redis!)
│   ├── world.php            — структура состояния, версии, доступ (единственный владелец формата)
│   ├── events.php           — шина: hook(event, callable), emit(state, event, payload)
│   ├── turn.php             — оркестрация хода: очередь фаз + тайминги (бывш. processTurnStart+poll-цикл)
│   ├── geometry.php         — parseXY/formatXY/линии/LOS/области (чистые функции, без состояния)
│   └── rules.php            — константы мира (GRID_*, MOVE_SPEED, TURN-формула — источник истины)
├── systems/                 ← СИСТЕМЫ (модули правил, общаются ТОЛЬКО через события + state)
│   ├── movement.php         — processMove, calculatePath, updateMovements (две фазы)
│   ├── combat.php           — attack, applyDamage (единая функция щит→HP→death→interrupt-move)
│   ├── targeting.php        — findTargetAtPosition/getTargetsOnLine/getTargetsInArea (универсальные, любой тип цели)
│   ├── effects.php          — ★ реестр эффектов + apply/duration-tick/clear (см. §2)
│   ├── abilities.php        — реестр способностей + projectile-механизмы (none/beam/line/area)
│   ├── passives.php         — реестр пассивок (бывш. items.php, минус processTurnStart)
│   ├── objects.php          — автономные объекты: traps, npcs (+ будущие spawns)
│   └── projectiles.php      — снаряды/анимации/death-цепочка (TURN→DEATH→DECAY→respawn)
├── data/                    ← загрузка battledata.json/B1.json → initial state (бывш. кусок init.php)
└── config.php               ← только Redis-коннект + окружение (остальное расползлось по engine/)
```

Порядок подключения: каждый файл сам объявляет зависимости
(`require_once __DIR__.'/../engine/events.php'`) — жёсткий цепочечный require из battle.php исчезает,
случайный порядок загрузки больше не ломает код.

### Событийная модель (главный инструмент развязки)

Канонический список событий тика (фиксируется в `engine/events.php` комментарием-таблицей):

```
turn.start                → phase: regen/resets (combat.resetAttackStatus, objects.npc-actions)
effects.tick              → phase: DoT/cc (effects.php обрабатывает NEGATIVES всех сущностей)
actions.grant             → phase: восстановление ACTIONS
player.action.move        → movement.validate+queue     (эмитит: movement.blocked / move.interrupted)
player.action.attack      → combat.resolve              (эмитит: damage.dealt, death, thorns.splash...)
player.action.ability     → abilities.resolve           (эмитит: effect.applied, damage.dealt)
tick.server               → movement.update, projectiles.update, animations.update
trap.trigger / threat.enter → combat.trap-damage        (эмитит: effect.applied, damage.dealt, death)
entity.died               → projectiles.spawn-death-chain
turn.end / victory.check
```

Примеры устранения боковых связок:
- `movement.canMove()` больше не содержит `strpos('FROZEN[')` — спрашивает шину:
  `emit('movement.query_block', ...)` → effects.php отвечает «заблокировано STUNNED/FROZEN».
- `applyDamageToTarget` не вызывает `applyPassiveDefense` напрямую — эмитит `damage.query_mitigate`,
  passives.php (ARMORED) и effects.php (если появится «берсерк») отвечают независимо.
- `THORNS/SPLASH/PUNCTURE` переезжают из «встроенных if» в слушателей `damage.dealt` / `attack.resolved`.
- Ловушка применяет урон **той же** `combat.applyDamage`, что и атака (устраняет P2-дубль).

---

## 2. Ответ на прямой вопрос: выносить ли STUNNED/BLEED… в effects.php?

**Да, безусловно — это самое ценное изменение в плане.** Сейчас эффект — это «строка + switch в двух
файлах + знания о нём в четырёх». В effects.php эффект становится **объектом данных с декларацией
поведения**, ровно как способности и пассивки уже сделаны реестрами:

```php
function getEffectDefinitions() {
    return [
        // DoT: тикует уроном на effects.tick
        'BLEED'    => ['kind'=>'dot',     'ticks_damage'=>1, 'stack'=>'refresh_max'],
        'POISONED' => ['kind'=>'dot',     'ticks_damage'=>1, 'stack'=>'refresh_max'],
        // CC: декларируют, ЧТО блокируют; носители статуса сами опрашивают effects
        'STUNNED'  => ['kind'=>'cc', 'blocks'=>['move','attack','ability','actions'],
                       'immediate'=>true],   // применять STATUS сразу при наложении (кроме MOVING/DEATH/DECAY)
        'FROZEN'   => ['kind'=>'cc', 'blocks'=>['move'], 'actions_penalty'=>1],
        'SLOWED'   => ['kind'=>'modifier', 'stat'=>'move_distance', 'value'=>-2],
        'BLINDED'  => ['kind'=>'modifier', 'stat'=>'attack_range', 'value'=>'/2'],
    ];
}
```

API модуля (единственные точки входа для остального кода):
- `effects.apply(&$state,$id,$effect_str)` — парсинг `NAME[n]`, продление до max (сейчас — в abilities),
  немедленная активация cc по флагу `immediate` (сейчас — хардкод STUNNED в abilities.php:479);
- `effects.tick(&$state,$id)` — generic-прогон NEGATIVES по kind: dot→урон через `combat.applyDamage`,
  cc→блокировка actions/status, expiry→снятие статуса **только если статус принадлежит этому эффекту**
  (сейчас хрупкое `if name==='STUNNED' && STATUS==='STUNNED'`);
- `effects.blocks($player,$verb)` — ответ на запрос movement/combat/action («можно ли двигаться/атаковать?»),
  заменяет все `strpos($neg,'X[')===0` в battle.php:39-56, battle.php:414-420/477-482, action.php:112;
- `effects.getModifier($player,$stat)` — SLOWED/BLINDED без special-case в processMove.

Выигрыш: новый эффект = **одна запись в реестре effects.php** (+ его обработчик там же). Ни одна правка
в abilities/battle/items/objects/action. Именно это и означает «категории не цепляют друг друга».

Мелкие замечания по пути:
- Парсить формат лучше одной функцией `effects.parse("STUNNED[2]")` (сейчас preg_match продублирован дважды).
- Долгосрочно (фаза 3): перейти от строкового `NAME[n]` к структуре `{name,duration,source}` —
  тогда можно хранить «кто наложил» (для dispel, имьюнити-тегов). Формат NEGATIVES владеет только effects.php.

---

## 3. Остальные целевые изменения по пунктам

1. **Единый `combat.applyDamage(&$state,$target,$amount,$source)`** — щит→HP→смерть→прерывание
   движения→`emit(damage.dealt)` в одном месте. Вызовы: attack, ability, trap, NPC-retaliation, DoT.
   Убирает два разошедшихся клока (в trap-версии сейчас нет прерывания MOVING и THORNS — скрытый баг-несоответствие).
2. **targeting.php независим от типа цели**: `resolveEntity($state,$type,$id)` даёт позицию/ALLY/живость
   для player/npc/object/trap — тогда AoE-способность сможет бить и NPC (сейчас `applyNegativeEffect`
   делает `if ($target_type !== 'player') return;` — эффекты на NPC невозможны архитектурно).
3. **turn.php = единственный владелец «такта мира»**; цикл из poll.php переезжает туда
   (`world_tick($state, $now)`), poll.php становится тонким: ждать-или-отдать snapshot. Тогда тот же
   такт смогут вызывать другие триггеры (будущий cron-турнир, перемотка, replay) без HTTP.
4. **passives.php чистим**: processTurnStart уходит в turn.php; applySplashDamage переписывается на
   слушателя `attack.resolved` c **одной** итоговой записью в лог вместо per-cell DEBUG-спама
   (P4) — лог боя должен быть читаемым «журналом событий», а не трассировкой.
5. **rules.php + endpoint `/api/config`**: клиент получает GRID_*, MOVE_SPEED, формулу TURN,
   дальности из ответов сервера; в game.js остаются только значения по умолчанию. Закрывает §7 ARCH.md
   и TODO в game.js:486/764.
6. **Снимок протокола**: poll отдаёт `EFFECT_DEFS`/`ABILITY_DEFS` одним справочником при init —
   клиент рисует иконки тултипов по данным, а не по захардкоженным именам.

---

## 4. Поэтапный план (каждый шаг — зелёным остаётся; поведение не меняется)

Т.к. тестов нет, сначала страховка, потом нарезка:

- **Шаг 0 (страховка).** `tests/smoke.php` — CLI-харнесс: собрать состояние из data/*.json,
  прогнать детерминированный скрипт (move→attack→ability→trap→stun-expiry) и сравнить ключевые поля
  состояния с эталоном (golden file). Redis на базе `battle:test:*`. Это единственный способ безопасно
  резать прототип без фреймворка.
- **Шаг 1 (безрисковая нарезка, без изменений логики).** Создать `engine/geometry.php` (перенос
  parseXY/formatXY/isValidPosition из config), `engine/rules.php` (константы), `systems/effects.php`
  (перенос applyNegativeEffect+processNegatives+все strpos-проверки под капот новых API, старые имена
  оставляем тонкими обёртками). Старые файлы лишь меняют тело функций на вызовы новых. Прогон шага 0.
- **Шаг 2 (шина).** `engine/events.php` + перевести существующие вызовы на эмиты:
  combat↔passives (mitigate/thorns/splash), abilities→effects (effect.applied), movement→objects
  (trap/threat). Порядок require перестаёт иметь значение. Прогон шага 0.
- **Шаг 3 (разделка battle.php/items.php).** Выделить turn.php, systems/movement.php, systems/targeting.php;
  unified applyDamage; passives.php без processTurnStart; убрать DEBUG-спам. Прогон шага 0.
- **Шаг 4 (HTTP-слой).** public/* + data/loader.php; config.php = только Redis. Клиентские константы —
  из rules (/api/config).
- **Шаг 5 (движковые улучшения, уже новые фичи):** структуры NEGATIVES с source, эффекты на npc/object,
  buff-эффекты (kind=>'buff'), dispel-механика, spawn-объекты от способностей (задел в objects.php).

Каждый шаг — отдельный коммит + обновление `ARCH.md` (правило из шапки ARCH.md).

---

## 5. Что сознательно НЕ делаем

- Не вводим классы/авто loader'ы Composer/PSR-пакеты — стек «чистый PHP», function-style остаётся
  (namespace-функции по файлам достаточно; при росте — можно обернуть в статические классы, но это косметика).
- Не трогаем протокол wire-format (poll snapshot, version++, long-poll) — клиенты совместимы всегда.
- Не добавляем WebSocket/Node/фреймворки (табу из ARCH.md §1).
- Не переписываем UI game.js рендер-слой — только источники констант.
- `index.html`/`assets/` (React-relics) — удалить отдельной домашней работой, к архитектуре не относятся.

## 6. Итоговая оценка трудозатрат

Шаги 0–1 ≈ 1 день (механический перенос + smoke-тест), шаги 2–3 ≈ 2–3 дня (самое содержательное),
шаги 4–5 ≈ 1–2 дня. После этого добавление новой способности/эффекта/пассивки/объекта = правка
**одного** реестра + (опционально) одного слушателя события, без касания соседних файлов —
целевое свойство «движка».
