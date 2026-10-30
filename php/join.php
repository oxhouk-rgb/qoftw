<?php
/**
 * join.php — Подключение игрока к бою (Этап 1: имитация данных с аккаунта).
 *
 * ★ ЗАЧЕМ ЭТОТ ЭНДПОИНТ
 * Раньше бой стартовал с 4 заготовленными персонажами из data/initial_snapshot.json,
 * а game.html лишь «выбирал» одного из них. Теперь снимок изначально ПУСТ:
 * в мире не существует персонажей «без владельца». N подключившихся игроков =
 * N персонажей (0 игроков = 0 персонажей). NPC/объекты/ловушки владельца не
 * требуют и расставляются из карт комнат как прежде.
 *
 * Контракт запроса (POST JSON) — карточка персонажа, которую сегодня присылает
 * форма настройки game.html, а в будущем — аккаунт игрока из БД:
 *   {
 *     "uid":        "7",              // id аккаунта из БД (1..4 — слот спавна)
 *     "nickname":   "IVAN",           // ник из БД
 *     "stats":      { HITPOINTS/MAX_HITPOINTS, SHIELD/MAX_SHIELD, ATTACK,
 *                     DEFENCE, RESISTANCE, RANGE — целые 1..10;
 *                     ACTIONS (очки действий) — целое 1..3 },
 *     "abilities":  ["FIRE WAVE", "BLIZZARD", "BACKSTAB"],  // ровно 3 из реестра
 *     "passives":   ["SWIFT", "THORNS"]                      // от 0 до 5 из реестра
 *   }
 * Сервер НЕ доверяет клиенту: состав боя определяет он (мировая валидация
 * карточки → world_build_player_from_payload + собственные проверки ниже).
 *
 * ★ ОГРАНИЧЕНИЕ СЕССИИ (поверхностное решение известной проблемы)
 * Игра рассчитана на одну статичную сессию без лобби подбора: максимум 4 слота
 * (комнаты спавна A1/A5/E1/E5). Пятый подключившийся получает HTTP 409 — все
 * спавн-места заняты. Полноценная мультисессия/лобби заменит этот ограничитель,
 * фундамент (эндпоинт + карточка + CAS-запись) остаётся тем же.
 *
 * ★ КОНКУРЕНТНОСТЬ
 * Запись бойца — CAS по версии мира (world_save_cas): параллельный тик poll.php
 * или чужое подключение не затираются. При гонке запрос перечитывает мир
 * (rebase), заново проверяет дубликаты/слоты и повторяет запись (до 3 попыток).
 * Повторный вход того же uid идемпотентен: боец уже в мире — отдаём его id без
 * создания второго персонажа.
 *
 * ★ АЛЬЯНСЫ
 * ALLY='NONE' для всех (FFA): союзов пока нет — их определит лобби.
 */

ob_start();

require_once __DIR__ . '/engine/bootstrap.php';
require_once __DIR__ . '/engine/world_builder.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Правила валидации карточки (серверная половина формы настройки) ────────
if (!defined('JOIN_MAX_PLAYERS')) {
    define('JOIN_MAX_PLAYERS', 4); // статичная сессия: 4 комнаты-спавна
}
if (!defined('JOIN_STATS_MIN'))  define('JOIN_STATS_MIN', 1);
if (!defined('JOIN_STATS_MAX'))  define('JOIN_STATS_MAX', 10);
// Очки действий (ACTIONS/MAX_ACTIONS) — отдельный диапазон: 1..3 (решение по
// форме настройки: поле обязательно, значения вне 1..3 отклоняются сервером).
if (!defined('JOIN_ACTIONS_MIN')) define('JOIN_ACTIONS_MIN', 1);
if (!defined('JOIN_ACTIONS_MAX')) define('JOIN_ACTIONS_MAX', 3);
if (!defined('JOIN_ABILITY_COUNT')) define('JOIN_ABILITY_COUNT', 3);
if (!defined('JOIN_PASSIVES_MAX'))  define('JOIN_PASSIVES_MAX', 5);

/**
 * Число характеристики: строго целое min..max (диапазон передаёт вызывающий:
 * обычные статы 1..10, очки действий 1..3 — единый код проверки на все поля).
 * @return array [int|null, error|null]
 */
function join_stat_value($raw, $field, $min = JOIN_STATS_MIN, $max = JOIN_STATS_MAX) {
    if ($raw === null || $raw === '' || is_array($raw)) {
        return [null, "характеристика {$field}: пропущено значение"];
    }
    if (!is_numeric($raw) || (string)(int)$raw !== (string)trim((string)$raw)) {
        return [null, "характеристика {$field}: ожидается целое число, получено '" . $raw . "'"];
    }
    $v = (int)$raw;
    if ($v < $min || $v > $max) {
        return [null, "характеристика {$field}: {$v} вне диапазона {$min}..{$max}"];
    }
    return [$v, null];
}

/**
 * Строгий разбор списка способностей: ровно JOIN_ABILITY_COUNT уникальных
 * имён из реестра getAbilityDefinitions(). Возвращает строки канонического
 * формата движка "NAME[range]" (range берётся из реестра — клиенту он
 * недоступен для подделки).
 * @return array [rows[]|null, errors[]]
 */
function join_validate_abilities($raw_list) {
    $errors = [];
    if (!is_array($raw_list)) return [null, ['abilities: ожидается список из '
        . JOIN_ABILITY_COUNT . ' способностей']];
    if (count($raw_list) !== JOIN_ABILITY_COUNT) {
        $errors[] = 'abilities: выбрано ' . count($raw_list)
            . ', нужно ровно ' . JOIN_ABILITY_COUNT;
    }
    $registry = getAbilityDefinitions();
    $rows = [];
    foreach ($raw_list as $name) {
        $name = trim((string)$name);
        if (!isset($registry[$name])) {
            $errors[] = "ability '{$name}': нет такой способности в игре";
            continue;
        }
        if (in_array($name, array_column($rows, 'name'), true)) {
            $errors[] = "ability '{$name}': дубликат";
            continue;
        }
        $rows[] = ['name' => $name, 'row' => $name . '[' . (int)$registry[$name]['range'] . ']'];
    }
    if ($errors) return [null, $errors];
    return [array_column($rows, 'row'), []];
}

/**
 * Строгий разбор пассивок: 0..JOIN_PASSIVES_MAX уникальных имён из реестра.
 * @return array [names[]|null, errors[]]
 */
function join_validate_passives($raw_list) {
    $errors = [];
    if ($raw_list === null) $raw_list = []; // 0 пассивок легально (поле можно не слать)
    if (!is_array($raw_list)) return [null, ['passives: ожидается список']];
    if (count($raw_list) > JOIN_PASSIVES_MAX) {
        $errors[] = 'passives: выбрано ' . count($raw_list)
            . ', максимум ' . JOIN_PASSIVES_MAX;
    }
    $registry = getPassiveDefinitions();
    $names = [];
    foreach ($raw_list as $name) {
        $name = trim((string)$name);
        if (!isset($registry[$name])) {
            $errors[] = "passive '{$name}': нет такой пассивки в игре";
            continue;
        }
        if (in_array($name, $names, true)) {
            $errors[] = "passive '{$name}': дубликат";
            continue;
        }
        $names[] = $name;
    }
    if ($errors) return [null, $errors];
    return [$names, []];
}

try {
    if (!extension_loaded('redis') && !class_exists('Redis')) {
        jsonError('Расширение Redis не установлено. Выполните: pecl install redis');
    }

    $raw_input = requestInput();
    $input = json_decode((string)$raw_input, true);
    if (!is_array($input)) {
        jsonError('Неверный формат данных. Ожидается JSON карточки персонажа.', 400);
    }

    // ── 1) Идентификаторы (имитация данных аккаунта из БД) ──────────────────
    $uid_raw = trim((string)($input['uid'] ?? ''));
    $nickname = trim((string)($input['nickname'] ?? ''));
    $errors = [];
    if ($uid_raw === '' || !ctype_digit($uid_raw)) {
        $errors[] = "uid: ожидается числовый id аккаунта (строка цифр), получено '{$uid_raw}'";
        $slot = null;
    } else {
        $slot = (int)$uid_raw;
        if ($slot < 1 || $slot > JOIN_MAX_PLAYERS) {
            $errors[] = 'uid: игрок ' . $slot . ' вне слотов 1..' . JOIN_MAX_PLAYERS
                . ' (статичная сессия без лобби)';
            $slot = null;
        }
    }
    if ($nickname === '' || mb_strlen($nickname) > 24) {
        $errors[] = 'nickname: требуется 1..24 символа';
    } elseif (!preg_match('/^[\p{L}\p{N}_\- ]+$/u', $nickname)) {
        $errors[] = 'nickname: допустимы буквы, цифры, "_", "-" и пробел';
    }

    // ── 2) Характеристики: статы 1..10 + очки действий 1..3 ────────────────
    $stats_in = $input['stats'] ?? [];
    if (!is_array($stats_in)) $errors[] = 'stats: ожидается объект характеристик';
    $stat_names = ['HITPOINTS', 'SHIELD', 'ATTACK', 'DEFENCE', 'RESISTANCE', 'RANGE'];
    $stats = [];
    foreach ($stat_names as $f) {
        list($v, $e) = join_stat_value(is_array($stats_in) ? ($stats_in[$f] ?? null) : null, $f);
        if ($e !== null) $errors[] = $e; else $stats[$f] = $v;
    }
    // Очки действий (ACTIONS/MAX_ACTIONS) — обязательное поле формы настройки
    // (диапазон 1..3). Название в карточке — ACTIONS; сборщик мира принимает
    // оба имени (ACTIONS/MAX_ACTIONS), синхронизируются парой ниже.
    list($actions_v, $e) = join_stat_value(is_array($stats_in) ? ($stats_in['ACTIONS'] ?? null) : null,
        'ACTIONS (очки действий)', JOIN_ACTIONS_MIN, JOIN_ACTIONS_MAX);
    if ($e !== null) $errors[] = $e; else $stats['ACTIONS'] = $actions_v;

    // ── 3) Способности/пассивки против реестров игры ────────────────────────
    list($ability_rows, $errs) = is_array($input)
        ? join_validate_abilities($input['abilities'] ?? null) : [null, []];
    $errors = array_merge($errors, $errs);
    list($passive_names, $errs) = join_validate_passives($input['passives'] ?? null);
    $errors = array_merge($errors, $errs);

    if ($errors) {
        jsonError('Карточка персонажа отклонена: ' . implode('; ', $errors), 400);
    }

    // ── 4) Слот спавна: uid 1..4 → комнаты A1/A5/E1/E5 (карты со spawn_cells) ─
    $spawn_rooms = ['A1', 'A5', 'E1', 'E5'];
    $room_id = $spawn_rooms[$slot - 1];

    // ── 5) Вхождение в мир: CAS-цикл (параллельные подключения/тики не теряются)
    for ($attempt = 0; $attempt < 3; $attempt++) {
        [$state, $base_version] = world_load_versioned();

        // Идемпотентность повторного входа: боец этого uid уже в мире — ничего
        // не создаём второй раз (перезагрузка страницы ≠ второй персонаж).
        $player_id = 'PLAYER' . $slot;
        if (isset($state['players'][$player_id])) {
            closeRedis();
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'rejoin' => true,
                'player_id' => $player_id,
                'message' => 'Игрок уже в бою: ' . $state['players'][$player_id]['NICKNAME'],
                'version' => (int)$base_version,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Ник занят другим подключённым игроком — дубликаты ключей недопустимы.
        foreach ($state['players'] as $pid => $pdat) {
            if (strcasecmp((string)($pdat['NICKNAME'] ?? ''), $nickname) === 0 && $pid !== $player_id) {
                closeRedis();
                jsonError("Никнейм «{$nickname}» уже занят игроком {$pid}", 409);
            }
        }
        // Все слоты заняты → пятый игрок не помещается (известное ограничение
        // статичной сессии; решается лобби в следующих этапах).
        if (count($state['players']) >= JOIN_MAX_PLAYERS) {
            closeRedis();
            jsonError('Все спавн-места заняты (' . JOIN_MAX_PLAYERS . ' из '
                . JOIN_MAX_PLAYERS . '). Подключение новых игроков невозможно, '
                . 'пока сессия не перезапущена (init.php?reset=1).', 409);
        }

        // Клетка появления: личная spawn_cell карты, иначе ближайшая свободная
        // (страховка от «спавн внутрь турели/другого бойца»).
        $cell = world_builder_find_free_spawn_cell($state, $room_id, $player_id);
        if ($cell === null) {
            closeRedis();
            jsonError("В комнате {$room_id} нет свободных клеток для появления", 409);
        }

        // Карточка → боец: те же дефолты/нормализация/валидация, что у сборки
        // мира (единый код, две точки вызова не расходятся).
        $payload = [
            'ID' => $player_id,
            'NICKNAME' => $nickname,
            'CHAR_ID' => 'CUSTOM',
            'ROOM_ID' => $room_id,
            'SPAWN_ROOM' => $room_id,
            'CUR_POSITION_XY' => $cell,
            'ALLY' => 'NONE', // союзов нет до лобби — каждый сам за себя
            'COLOR' => '#e94560',
            'HITPOINTS' => $stats['HITPOINTS'],
            'MAX_HITPOINTS' => $stats['HITPOINTS'],
            'SHIELD' => $stats['SHIELD'],
            'MAX_SHIELD' => $stats['SHIELD'],
            'ATTACK' => $stats['ATTACK'],
            'DEFENCE' => $stats['DEFENCE'],
            'RESISTANCE' => $stats['RESISTANCE'],
            'RANGE' => $stats['RANGE'],
            'ACTIONS' => $stats['ACTIONS'],
            'MAX_ACTIONS' => $stats['ACTIONS'], // на старте бойца очки полны
            'ABILITIES' => $ability_rows,
            'PASSIVES' => $passive_names,
            'NEGATIVES' => [],
        ];
        $built = world_build_player_from_payload($payload, $state['rooms'] ?? []);
        if ($built['player'] === null) {
            closeRedis();
            jsonError('Сервер отклонил карточку бойца: ' . implode('; ', $built['errors']), 400);
        }
        $player = $built['player'];
        // Сборщик пересчитал клетку по карте — сверяем с выбранной slot-клеткой
        // (normalize использует room_initial_spawn_cell поверх CUR_POSITION_XY).
        $player['UID'] = (string)$uid_raw; // имитация ссылки на аккаунт из БД

        $state['players'][$player_id] = $player;
        $state = world_log($state, "Игрок {$nickname} (uid {$uid_raw}) подключился: "
            . $player_id . ", комната {$room_id}, клетка {$player['CUR_POSITION_XY']}");

        [$saved, $fresh_state] = world_save_cas($state, $base_version);
        if ($saved) {
            $new_version = world_version();
            closeRedis();
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'rejoin' => false,
                'player_id' => $player_id,
                'nickname' => $nickname,
                'room' => $room_id,
                'position' => $player['CUR_POSITION_XY'],
                'message' => 'Персонаж размещён в бою',
                'version' => $new_version,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // ГОНКА: мир изменился параллельным процессом — повторяем на свежем.
        $state = $fresh_state;
    }

    closeRedis();
    jsonError('Мир изменён параллельным процессом, повторите подключение', 503);

} catch (Throwable $e) {
    clearOutputBuffers();
    sendJsonErrorFallback('Критическая ошибка подключения: ' . $e->getMessage()
        . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}
