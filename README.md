# BATTLE SYSTEM - Browser Game Engine

Браузерная боевая система для 2D платформера. PHP + Redis бэкенд, HTML/CSS/JS фронтенд.

## Быстрый старт

### Требования
- PHP 7.4+ (с расширением Redis)
- Redis сервер
- Веб-сервер (Apache/Nginx или USBWebServer)

### Установка

1. **Запустите Redis:**
```bash
redis-server
```

2. **Скопируйте файлы** из `public/` в корень веб-сервера:
```
public/
├── game.html          → /game.html
├── css/game.css       → /css/game.css
├── js/game.js         → /js/game.js
├── data/              → /data/
│   ├── battledata.json
│   └── B1.json
└── php/               → /php/
    ├── config.php
    ├── init.php
    ├── poll.php
    ├── action.php
    ├── battle.php
    ├── abilities.php
    └── items.php
```

3. **Откройте в браузере:**
```
http://localhost/game.html
```

4. **Для мультиплеера:** откройте game.html в нескольких вкладках, выберите разных персонажей.

### Настройка Redis

Отредактируйте `php/config.php`:
```php
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
```

## Структура проекта

### PHP файлы

| Файл | Назначение |
|------|-----------|
| `config.php` | Подключение Redis, константы, утилиты |
| `init.php` | Загрузка данных из JSON в Redis |
| `poll.php` | Long polling endpoint |
| `action.php` | Обработка действий (move/attack/ability) |
| `battle.php` | Логика перемещений, атак, обновление тиков |
| `abilities.php` | Реестр и применение способностей |
| `items.php` | Пассивки, негативные эффекты, ловушки, NPC AI |

### Данные

- `data/battledata.json` - 4 тестовых персонажа + объекты/NPC/ловушки
- `data/B1.json` - карта комнаты (сетка 16×12, стены, выходы)

## Игровые механики

### Сетка
- 16 столбцов × 12 строк
- 3 этажа с лестницами (столбец 7)
- Стены блокируют путь (0 в карте)

### Ход
- Каждые 5 секунд ACTIONS восстанавливаются
- Каждое действие = 1 очко
- Нет кулдаунов

### Перемещение
- До 5 клеток за 1 действие
- Только по прямой (вверх/вниз/влево/вправо)
- Нельзя проходить сквозь стены/игроков
- Прерывается при получении урона

### Атака
- В пределах RANGE
- Минимум 1 урон (независимо от сопротивления)
- Сопротивление: 10% за очко (макс 90%)
- Урон округляется вверх

### Способности
- 3 типа: beam (луч), line (линия), area (область)
- Каждая имеет свою дальность
- Создают снаряды для визуализации

### Пассивки
| Название | Эффект |
|----------|--------|
| REGENERATION | +1 HP/ход |
| ARMORED | -1 к полученному урону |
| SPLASH | 1 урон соседним врагам при атаке |
| THORNS | 1 урон атакующему при получении урона |
| SWIFT | +1 к дальности перемещения |

### Негативные эффекты
| Эффект | Действие |
|--------|----------|
| FROZEN[N] | -1 ACTION, N ходов |
| POISONED[N] | -1 HP/ход, N ходов |
| BLEED[N] | -1 HP/ход, N ходов |
| STUNNED[N] | 0 ACTIONS, N ходов |
| SLOWED[N] | -2 к дальности перемещения, N ходов |
| BLINDED[N] | дальность/2, N ходов |

## Тестовые персонажи

### PLAYER1 - NAPALM UNIT 🔴
- HP: 10 | ATK: 5 | DEF: 3 | Shield: 5 | Actions: 2 | Range: 5
- Fire Wave[3], Shock Granade[4], Napalm Granade[5]
- Пассивки: Regeneration, Armored

### PLAYER2 - FROST KNIGHT 🔵
- HP: 8 | ATK: 4 | DEF: 5 | Shield: 3 | Actions: 3 | Range: 3
- Ice Shard[3], Frost Nova[2], Blizzard[5]
- Пассивки: Armored, Splash

### PLAYER3 - SHADOW ROGUE 🟢
- HP: 6 | ATK: 7 | DEF: 2 | Shield: 0 | Actions: 3 | Range: 2
- Backstab[1], Smoke Bomb[3], Poison Blade[2]
- Пассивки: Regeneration

### PLAYER4 - IRON GUARD 🟡
- HP: 10 | ATK: 3 | DEF: 7 | Shield: 8 | Actions: 1 | Range: 2
- Shield Bash[1], Fortify[0], War Cry[3]
- Пассивки: Armored, Regeneration, Splash

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

## Long Polling

Фронтенд делает запрос к `poll.php?v=VERSION&player=PLAYER_ID`.
Сервер держит соединение до:
- Появления новой версии (кто-то совершил действие)
- Истечения таймаута (25 секунд)

При новом действии версия инкрементируется, и все клиенты получают обновление.
