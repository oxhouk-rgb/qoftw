<?php
/**
 * ТЕСТОВЫЙ HARNESS (не часть рантайма). Подменяет только сетевой клиент Redis
 * фейковым in-memory классом ДО подключения config.php — весь остальной код
 * (config/engine/systems) настоящий. Используется для прогона инициализации
 * без запущенного redis-server.
 *
 * ★ Если расширение phpredis УСТАНОВЛЕНО, класс уже объявлен на уровне C —
 * повторное объявление fatal'ит ("Cannot declare class Redis, because the name
 * is already in use"). В этом случае харнесс запускается через `php -n`
 * (без ini-расширений): фейк ниже становится единственным определением Redis.
 */
if (!class_exists('Redis', false)) {
class Redis {
    public $data = [];
    const PIPELINE = 1;
    const MULTI = 2;          // phpredis: транзакция (exec может быть отменён WATCH'ем)
    const OPT_READ_TIMEOUT = 4;
    private $queue = null;

    public function connect($h, $p, $t = null) { return true; }
    public function ping() { return true; }
    public function select($db) { return true; }
    public function setOption($o, $v) { return true; }
    public function close() { return true; }

    public function get($k) { return $this->data[$k] ?? false; }
    public function set($k, $v) { $this->data[$k] = $v; return true; }
    public function incr($k) { $this->data[$k] = (int)($this->data[$k] ?? 0) + 1; return $this->data[$k]; }
    public function del($k) { unset($this->data[$k]); return 1; }
    public function flushDB() { $this->data = []; return true; }
    // WATCH/UNWATCH in-memory-фейку не нужны: он однопоточный и гонку
    // read-modify-write воспроизвести не может. Методы объявлены, чтобы код
    // CAS-записи (config.php::saveBattleState) исполнялся в тесте без фатала.
    public function watch($keys) { return true; }
    public function unwatch() { return true; }

    // Настоящий phpredis в режиме MULTI возвращает НЕ $this, а отдельный
    // объект-транзакцию. config.php делает именно так:
    //   $pipe = $redis->multi(PIPELINE); $pipe->get(...); ... $pipe->exec();
    // и отдельно дергает getRedis()->multi(...) — если бы пайплайн возвращал
    // тот же самый хендлар, его очередь зациклилась бы (exec записывает
    // операции обратно в queue). Фейк обязан воспроизводить это разделение.
    private $pipeline = null;
    private function queueRef() {
        if ($this->pipeline === null) {
            $outer = $this;
            $this->pipeline = new class($this, $outer) {
                public $ops = [];
                private $store; private $owner;
                public function __construct($store, $owner) { $this->store = $store; $this->owner = $owner; }
                public function get($k)    { $this->ops[] = ['get', $k];  return $this; }
                public function set($k,$v) { $this->ops[] = ['set', $k, $v]; return $this; }
                public function incr($k)   { $this->ops[] = ['incr', $k]; return $this; }
                // flushDB в транзакции — команда сброса мира (engine/world_reset.php):
                // фейк обязан её принимать, иначе тест HTTP-перезапуска сессии
                // упёрся бы в «Call to undefined method» вместо проверки логики.
                public function flushDB()  { $this->ops[] = ['flushDB']; return $this; }
                public function exec() {
                    $r = [];
                    foreach ($this->ops as $op) {
                        $this->store->resetPipeline(); // каждый вызов — как к реальному серверу
                        if ($op[0] === 'get')  $r[] = $this->store->get($op[1]);
                        elseif ($op[0] === 'set') $r[] = $this->store->set($op[1], $op[2]);
                        elseif ($op[0] === 'incr') $r[] = $this->store->incr($op[1]);
                        elseif ($op[0] === 'flushDB') $r[] = $this->store->flushDB();
                    }
                    $this->ops = [];
                    return $r;
                }
            };
        }
        return $this->pipeline;
    }
    public function resetPipeline() { $this->pipeline = null; }

    public function multi($mode = null) { return $this->queueRef(); }
    public function exec() { return $this->queueRef()->exec(); }
}
} // if (!class_exists('Redis'))

require_once getenv('HARNESS_NO_BOOTSTRAP')
    ? __DIR__ . '/../php/config.php'
    : __DIR__ . '/../php/engine/bootstrap.php';

// ★ Мир собирается и пишется КОДОМ РАНТАЙМА, а не тестовой заглушкой:
// engine/world_reset.php — те же функции, что вызывает init.php (?reset=1) и
// tools/reset_world.php. Харнесс подключает их явно (bootstrap их не тянет,
// т.к. сброс сессии — операция вне игрового тика).
require_once __DIR__ . '/../php/engine/world_reset.php';

/* =========================================================================
 * СЦЕНАРНЫЕ ТЕСТЫ МУЛЬТИКОМНАТНОГО МИРА (пункты плана №1 и №5)
 *
 *   T1. Переход через exit-триггер: B1 → A1, выписка из старой комнаты,
 *       появление у обратной двери (exit_rooms), карта A1 в state['rooms'].
 *   T2. Респаун после смерти: цепочка DEATH→DECAY завершается НЕ прямой
 *       записью координат, а rooms_teleport(..., 'respawn') в личную
 *       SPAWN_ROOM (regression-тест бага «воскрес в чужой комнате»).
 *   T3. poll-фильтрация: build_poll_response(?player=) отдаёт карту и
 *       сущности ТОЛЬКО текущей комнаты игрока (regression-тест бага
 *       «все видели карту первой комнаты»).
 *
 * Redis — фейковый in-memory (класс выше), весь остальной код настоящий.
 * ========================================================================= */

$GLOBALS['__assertions'] = 0;
$GLOBALS['__failures']   = 0;

function t_assert($cond, $msg) {
    $GLOBALS['__assertions']++;
    if (!$cond) {
        $GLOBALS['__failures']++;
        echo "FAIL: $msg\n";
    } else {
        echo "ok:   $msg\n";
    }
}

/**
 * ★ Сид мира — тем же кодом, что и настоящий перезапуск сессии.
 *
 * Тест НЕ собирает мир своими руками: он вызывает world_reset_build() +
 * world_reset_write() — ровно те функции, которые дёргает init.php (?reset=1)
 * и tools/reset_world.php. Так проверка «изначальное состояние восстанавливается»
 * неотделима от самого восстановления: если сборщик сломается, тест упадёт, а не
 * разойдётся с рантаймом (именно так раньше и случилось, когда Python-сид писал
 * копию характеристик объектов, жившую отдельно от PHP-движка).
 *
 * Фейковый in-memory Redis наполняется теми же ключами battle:*, что и боевой,
 * поэтому дальнейшие world_load()/poll/action идут по штатному пути.
 * Возвращает [state, warnings] — state готов к сценариям T1–T5.
 */
function harness_seed_world() {
    $warnings = [];

    // 1) Сборка ИЗ НАЧАЛЬНЫХ данных (код-реестры + карты комнат + снимок бойцов).
    // ★ Рантайм-снимок data/initial_snapshot.json пуст (0 игроков = 0 персонажей —
    //   бойцы приходят через join.php). Тестовый мир собирается из изолированного
    //   сида tests/data/test_snapshot.json (4 заготовленных бойца), чтобы сценарии
    //   T1–T5 не зависели от стартовых данных рантайма.
    $built = world_reset_build(__DIR__ . '/data/test_snapshot.json');
    if (!empty($built['errors'])) {
        echo "FATAL: изначальное состояние мира не собирается:\n";
        foreach ($built['errors'] as $e) echo "   - {$e}\n";
        exit(1);
    }
    $state = $built['state'];

    // 2) Запись в фейковый Redis (flushDB + набор battle:* ключей, version=1).
    $res = world_reset_write($state);
    if (!$res['ok']) {
        echo "FATAL: запись мира в (фейковый) Redis не удалась: " . $res['message'] . "\n";
        exit(1);
    }

    // 3) Читаем мир ОБРАТНО через штатный загрузчик (getBattleState/world_load) —
    //    так тест доказывает совместимость формата сида с рантаймом, а не просто
    //    конструирует массив руками.
    $loaded = world_load();
    if (empty($loaded['players'])) {
        echo "FATAL: после сидинга world_load() вернул пустой players\n";
        exit(1);
    }
    // turn_time должен пережить round-trip через Redis (regression: seed писал
    // int-строку, saveBattleState сериализовал поле как JSON-литерал «1234» и
    // round-trip обнулял дедлайн хода → poll каждый тик продвигал ход).
    if ((int)($loaded['turn_time'] ?? 0) <= 0) {
        echo "FATAL: turn_time потерялся при round-trip через Redis\n";
        exit(1);
    }
    // Карты комнат обязаны прийти вместе с миром: расстановка объектов/ловушек/
    // NPC читается именно из них (см. objects_layout_from_room), поэтому пустой
    // rooms = молча потерянный стартовый контент.
    if (empty($loaded['rooms'])) {
        $warnings[] = 'после сидинга state[rooms] пуст — комнаты не осели в мире';
    }
    return [$loaded, $warnings];
}

/**
 * Инициализация мира для сценарных тестов: посейдать в фейковый Redis, прочитать
 * через штатный загрузчик, пересчитать THREAT_ZONE NPC (как это делает init.php
 * поверх уже лежащего в Redis мира).
 */
function harness_build_state() {
    static $seeded = null;
    if ($seeded === null) {
        [$st, $warnings] = harness_seed_world();
        $seeded = [$st, $warnings];
    }
    $state = $seeded[0];
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if ($npc['STATUS'] !== 'DESTROYED' && $npc['STATUS'] !== 'DEATH') {
            $nroom = $npc['ROOM_ID'] ?? null;
            if ($nroom !== null && $nroom !== '' && !isset($state['rooms'][$nroom])) {
                $card = room_get($state, $nroom);
                if ($card !== null) $state['rooms'][$nroom] = $card;
            }
            $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
        }
    }
    unset($npc);
    return $state;
}

/**
 * Проверка «комната оседла в состоянии»: карта комнаты может лежать либо
 * в переданном state['rooms'] (загружена лениво этим вызовом), либо быть
 * сохранена в Redis прошлым world_save — тогда её вернёт getBattleState().
 */
function harness_room_loaded(array $state, $room_id) {
    if (isset($state['rooms'][$room_id])) return true;
    $from_redis = getBattleState();
    return isset($from_redis['rooms'][$room_id]);
}

// ------------------------------------------------------------------ T1
function test_exit_transition() {
    echo "--- T1: переход через exit-триггер ---\n";
    $state = harness_build_state();
    // Игрок стоит на выходной клетке B1 '0,0' → A1
    $state['players']['PLAYER1']['ROOM_ID'] = 'B1';
    $state['players']['PLAYER1']['CUR_POSITION_XY'] = '0,0';
    $state['players']['PLAYER1']['END_POSITION_XY'] = '0,0';

    $triggered = rooms_check_exit_trigger($state, 'PLAYER1', 0, 0);
    t_assert($triggered, 'exit-клетка B1 (0,0) триггернула переход');
    // rooms_teleport мутирует state['rooms'] через &$state — карта должна
    // осесть ПРЯМО в этом экземпляре (ленивая загрузка без world_save).
    $p = $state['players']['PLAYER1'];
    t_assert(($p['ROOM_ID'] ?? '') === 'A1', 'PLAYER1 перенесён в комнату A1');
    t_assert(isset($state['rooms']['A1']), 'карта A1 появилась в state[rooms] (ленивая загрузка)');
    // Появление у обратной двери A1.exit_rooms["0,0"]="B1" — рядом с (0,0)
    $sp = parseXY($p['CUR_POSITION_XY']);
    t_assert(abs($sp['x'] - 0) <= 2 && abs($sp['y'] - 0) <= 2, 'спавн рядом с обратной дверью A1 (0,0)');
    t_assert(($p['STATUS'] ?? '') === 'MOVING', 'статус после перехода — MOVING (виртуальный финал: тик сам снимет его в IDLE)');
    // Движение обрывается навсегда: путь нулевой, MOVE_END_TIME уже наступил —
    // следующий movement_update завершает «ход» без анимации.
    t_assert(isset($p['MOVE_START_TIME']) && isset($p['MOVE_END_TIME']), 'выданы ходовые данные мгновенного перехода');
    t_assert($p['MOVE_END_TIME'] <= microtime(true), 'MOVE_END_TIME наступил — движение финализируется на ближайшем тике');
    t_assert($p['START_POSITION_XY'] === $p['END_POSITION_XY'], 'путь мгновенного перехода нулевой (START == END) — клиент не анимирует рывок');
    // ★ Тик должен корректно завершить виртуальное движение: STATUS → IDLE,
    // флаги сняты, позиция НЕ на клетке двери.
    movement_update($state);
    $p = $state['players']['PLAYER1'];
    t_assert(($p['STATUS'] ?? '') === 'IDLE', 'после тика статус стал IDLE');
    t_assert(!isset($p['MOVE_START_TIME']) && !isset($p['MOVE_END_TIME']), 'после тика флаги движения сняты');
    $door_xy = formatXY($sp['x'], $sp['y']);
    $a1 = $state['rooms']['A1'];
    t_assert(!isset($a1['exits'][$door_xy]) && !isset($a1['exit_rooms'][$door_xy]), 'боец стоит НЕ на клетке двери (нет повторного триггера)');
}

// ------------------------------------------------------------------ T2
function test_respawn_via_teleport() {
    echo "--- T2: респаун через rooms_teleport(respawn) ---\n";
    $state = harness_build_state();
    $pid = 'PLAYER1';
    // Моделируем смерть в ЧУЖОЙ комнате (например, игрок дошёл до B1 и погиб):
    // раньше баг — воскресал на START_POSITION_XY, но ROOM_ID оставался B1.
    $state['players'][$pid]['ROOM_ID'] = 'B1';
    $state['players'][$pid]['CUR_POSITION_XY'] = '7,5';
    $state['players'][$pid]['END_POSITION_XY'] = '7,5';
    $state['players'][$pid]['STATUS'] = 'DECAY';
    $state['players'][$pid]['DECAY_TIME'] = time() - DECAY_ANIM_SECONDS - 1; // пора на выход
    $state['players'][$pid]['LIFE'] = 1; // одна жизнь ещё есть

    $changed = turn_update_death_animations($state);
    t_assert($changed, 'цепочка DECAY сдвинулась на этом тике');
    $p = $state['players'][$pid];
    // Респаун — тоже мгновенный переход: виртуальный финал движения, тик
    // переводит в IDLE (см. T1). Проверка «не на двери» ниже это подтверждает.
    t_assert(in_array(($p['STATUS'] ?? ''), ['IDLE', 'MOVING'], true), 'после респауна статус IDLE или MOVING-до-завершения');
    movement_update($state);
    $p = $state['players'][$pid];
    t_assert(($p['STATUS'] ?? '') === 'IDLE', 'после тика статус стал IDLE');
    t_assert(($p['ROOM_ID'] ?? '') === ($p['SPAWN_ROOM'] ?? '?'), 'игрок вернулся в личную SPAWN_ROOM (A1), а не остался в B1');
    t_assert(isset($state['rooms'][$p['SPAWN_ROOM']]), 'карта SPAWN_ROOM загружена в state[rooms]');
    t_assert($p['CUR_POSITION_XY'] === $p['START_POSITION_XY'], 'встал ровно на личную стартовую клетку (она свободна)');
    t_assert(!isset($p['DEATH_TIME']) && !isset($p['DECAY_TIME']), 'таймеры смерти очищены');
    t_assert((int)$p['LIFE'] === 0, 'жизнь списана при респауне');
}

// ------------------------------------------------------------------ T3
/**
 * poll.php — HTTP-эндпоинт: при require сразу исполняет long-poll цикл и
 * завершает процесс (exit). Для юнит-теста извлекаем текст функции
 * build_poll_response и объявляем её в отдельном namespace, подменяя
 * movement_effective_range заглушкой (шиной пассивок в этом тесте проверяет
 * сам сервер на боевом прогоне).
 */
function test_poll_room_filtering() {
    echo "--- T3: poll отдаёт только текущую комнату игрока ---\n";
    if (!class_exists('TestPollNs\\StubHooks', false)) {
        $src = file_get_contents(__DIR__ . '/../php/poll.php');
        $start = strpos($src, 'function build_poll_response');
        t_assert($start !== false, 'poll.php: функция build_poll_response найдена');
        if ($start === false) return;
        // Вырезаем текст функции: от объявления до строки '}' в нулевом отступе
        $end = strpos($src, "\n}", $start);
        t_assert($end !== false, 'poll.php: конец функции найден');
        if ($end === false) return;
        $fn_src = substr($src, $start, $end - $start + 2);
        eval("namespace TestPollNs; function rules_client_payload(){return [];} function movement_effective_range(\$s,\$p){return 5;} function engine_check_victory(\$s){return ['victory'=>false];} " . $fn_src);
    }
    $state = harness_build_state();
    world_save($state); // состояние — в фейковый Redis (как делает action/poll цикл)

    $resp = \TestPollNs\build_poll_response($state, 1, 'PLAYER1');
    t_assert(($resp['room_id'] ?? '') === 'A1', 'poll(PLAYER1): room_id = A1');
    t_assert(($resp['room']['room_id'] ?? '') === 'A1', 'poll(PLAYER1): карта — A1, а не legacy B1');
    $foreign = array_filter($resp['npcs'] ?? [], function ($e) { return ($e['ROOM_ID'] ?? '') !== 'A1'; });
    t_assert(count($foreign) === 0, 'poll(PLAYER1): ни одного NPC чужой комнаты');
    $foreign_obj = array_filter($resp['objects'] ?? [], function ($e) { return ($e['ROOM_ID'] ?? '') !== 'A1'; });
    t_assert(count($foreign_obj) === 0, 'poll(PLAYER1): ни одного объекта чужой комнаты');
    $with_range = array_filter($resp['players'] ?? [], function ($p) { return isset($p['MOVE_RANGE']); });
    t_assert(count($with_range) === count($resp['players'] ?? []), 'poll: у всех игроков есть MOVE_RANGE');
    $foreign_trap = array_filter($resp['traps'] ?? [], function ($e) { return ($e['ROOM_ID'] ?? '') !== 'A1'; });
    t_assert(count($foreign_trap) === 0, 'poll(PLAYER1): ни одной ловушки чужой комнаты');

    // Неизвестный ?player= → legacy-карта (обратная совместимость debug-клиентов)
    $resp_b1 = \TestPollNs\build_poll_response($state, 1, 'NPC_TEST_ABSENT');
    t_assert(($resp_b1['room']['room_id'] ?? '') === 'B1', 'poll без игрока: legacy-карта B1');
}

/**
 * T4. Комнатная изоляция целей (regression бага «атака пустой клетки задевает
 * бойца в другой комнате с совпадающими координатами»): find_target_at_position
 * и get_targets_on_line обязаны видеть только сущности комнаты атакующего.
 */
function test_crossroom_targeting() {
    echo "--- T4: цели/лучи ограничены комнатой атакующего ---\n";
    $state = harness_build_state();
    // Размещаем PLAYER2 в КООРДИНАТНО совпадающую клетку, но в ДРУГУЮ комнату
    // (позиция игрока из legacy B1-карты).
    $pos2 = parseXY($state['players']['PLAYER2']['CUR_POSITION_XY']);
    $state['players']['PLAYER2']['ROOM_ID'] = 'B1';
    $state['players']['PLAYER1']['ROOM_ID'] = 'A1';
    $state['players']['PLAYER1']['CUR_POSITION_XY'] = '1,1';
    $state['players']['PLAYER1']['STATUS'] = 'IDLE';
    $state['players']['PLAYER2']['CUR_POSITION_XY'] = '5,5';
    $state['players']['PLAYER2']['STATUS'] = 'IDLE';
    unset($state['rooms']['A1']); unset($state['rooms']['B1']); // ленивая загрузка карт

    // Клетка (5,5): в A1 там пусто, боец PLAYER2 — в B1 на тех же координатах
    t_assert(find_target_at_position($state, 5, 5, 'PLAYER1') === null,
        'T4: цель в чужой комнате не находится (атакующий в A1)');
    t_assert(find_target_at_position($state, 5, 5, 'PLAYER2') !== null,
        'T4: та же клетка видна атакующему из комнаты цели (B1)');

    // Луч через всю карту: цели чужой комнаты не задеваются
    $line = get_targets_on_line($state, 1, 1, 15, 5, 'PLAYER1');
    $ids = array_column($line, 'id');
    t_assert(!in_array('PLAYER2', $ids, true),
        'T4: луч из A1 не собирает цели из B1');

    // Проходимость: объект/NPC чужой комнаты НЕ блокирует клетки этой карты
    $obj_foreign = false;
    foreach ($state['objects'] as $o) {
        if (($o['ROOM_ID'] ?? '') !== 'A1') {
            $pp = parseXY($o['POSITION_XY']);
            if (!is_cell_passable($state, $pp['x'], $pp['y'], 'A1')) { $obj_foreign = true; break; }
        }
    }
    t_assert(!$obj_foreign, 'T4: объекты чужих комнат не перекрывают проходы A1/B1');
}

/**
 * T5. ВОСПРОИЗВОДИМОСТЬ ИЗ НАЧАЛЬНОГО СОСТОЯНИЯ (главная регрессия после
 * переезда мира в Redis): живой бой «до» → reset → мир «после» обязан побитово
 * совпадать с первым сидом. Раньше reset-пути не было вовсе: session restart
 * читал тот же снапшот конца прошлого боя, и разрушенные бочки оставались
 * разрушенными, мина — TRIGGERED, игроки — мёртвыми.
 *
 * Отдельно проверяется контракт «перезапуск = новая сессия»: счётчик версии
 * обнуляется до 1 (long-poll клиентов не должен увидеть ложное «мир изменился»
 * поверх старого номера) и флаг battle:seeded присутствует (его ждёт init.php).
 */
function test_world_reset_restores_initial_state() {
    echo "--- T5: сброс возвращает изначальное состояние ---\n";
    $state = harness_build_state();

    // Снимок ДО: сериализация всех секций состояния (то, что лежит в Redis).
    $fingerprint = function ($s) {
        $parts = [];
        foreach (['players', 'objects', 'traps', 'npcs', 'projectiles', 'rooms'] as $sec) {
            $k = is_array($s[$sec] ?? null) ? $s[$sec] : [];
            ksort($k);
            $parts[$sec] = json_encode($k, JSON_UNESCAPED_UNICODE);
        }
        return $parts;
    };
    $before = $fingerprint(world_load());

    // ★ Тестовый сид: после world_reset_session() мир обязан восстановиться к
    //   СОСТОЯНИЮ ЭТОГО СИДА, а не рантайм-снимка. Рантайм data/initial_snapshot.json
    //   пуст (0 игроков = 0 персонажей — бойцы приходят через join.php), поэтому
    //   reset без явного пути собрал бы пустой players и сравнение секции упало бы.
    $reset_expected = world_reset_build(__DIR__ . '/data/test_snapshot.json');
    if (!empty($reset_expected['errors'])) {
        echo "FATAL: тестовый сид не собирается для сверки после сброса:\n";
        foreach ($reset_expected['errors'] as $e) echo "   - {$e}\n";
        exit(1);
    }

    // ── Портим мир так, как это делает живой бой ──
    $state['objects']['BARRELS1']['STATUS']     = 'DESTROYED';   // бочку разбили
    $state['objects']['BARRELS1']['HITPOINTS']  = 0;
    $state['traps']['MINE1']['STATUS']          = 'TRIGGERED';   // мину подорвали
    $state['npcs']['TURRET1']['HITPOINTS']      = 1;             // турель поцарапали
    $state['players']['PLAYER1']['STATUS']      = 'DEATH';       // боец погиб
    $state['players']['PLAYER1']['HITPOINTS']   = 0;
    $state['players']['PLAYER2']['ROOM_ID']     = 'B1';          // ушёл в хаб
    $state['players']['PLAYER2']['CUR_POSITION_XY'] = '7,5';
    $state['projectiles'] = ['P1' => ['id' => 'P1']];            // летит снаряд
    world_save($state);

    $dirty = world_load();
    t_assert(($dirty['objects']['BARRELS1']['STATUS'] ?? '') === 'DESTROYED', 'грязный мир записан в Redis');
    t_assert(($dirty['players']['PLAYER1']['STATUS'] ?? '') === 'DEATH', 'мёртвый боец сохранился');
    t_assert(count($dirty['projectiles'] ?? []) === 1, 'снаряд сохранился');
    $v_dirty = (int)($dirty['version'] ?? 0);
    t_assert($v_dirty > 1, 'версия выросла на записи боя (v=' . $v_dirty . ')');

    // ── Перезапуск сессии: тот же код, что дёргает init.php?reset=1, но со
    //   тестовым сидом (рантайм-снимок пуст — reset по умолчанию собрал бы мир
    //   без бойцов; параметр snapshot_path world_reset_session поддерживает).──
    $res = world_reset_session(__DIR__ . '/data/test_snapshot.json');
    t_assert($res['ok'], 'world_reset_session(): сборка+запись прошли: ' . ($res['message'] ?? ''));
    t_assert(empty($res['errors']), 'при сборке нет ошибок данных');

    $after = world_load();
    // Эталон «после» — свежесобранный из сида мир (тот же round-trip через
    // фейковый Redis, что и у до-сбросового состояния), а не «грязный» $before.
    $expected_after = $fingerprint($reset_expected['state']);
    $t5_fail = 0;
    foreach ($expected_after as $sec => $json) {
        $same = ($fingerprint($after)[$sec] === $json);
        if (!$same) $t5_fail++;
        t_assert($same, "секция '{$sec}' после сброса идентична изначальной");
    }
    t_assert($t5_fail === 0, 'сброс полностью воспроизводит изначальное состояние мира');

    // Точечные инварианты (чтобы падение теста было понятным, а не «не совпал JSON»)
    t_assert(($after['objects']['BARRELS1']['STATUS'] ?? '') === 'INTACT', 'разрушенная бочка снова цела');
    t_assert((int)($after['objects']['BARRELS1']['HITPOINTS'] ?? -1) === 3, 'HP бочки восстановлен из реестра типов');
    t_assert(($after['traps']['MINE1']['STATUS'] ?? '') === 'INTACT', 'мина снова INTACT');
    t_assert((int)($after['npcs']['TURRET1']['HITPOINTS'] ?? -1) === 5, 'турель снова полная');
    t_assert(($after['players']['PLAYER1']['STATUS'] ?? '') === 'IDLE', 'мёртвый боец ожил');
    t_assert(($after['players']['PLAYER2']['ROOM_ID'] ?? '') === 'A5', 'боец вернулся в стартовую комнату');
    t_assert(count($after['projectiles'] ?? []) === 0, 'снаряды сброшены');
    t_assert((int)$after['version'] === 1, 'версия обнулилась до 1 — long-poll не увидит ложного изменения');
    t_assert(getRedis()->get(REDIS_SEEDED) !== false, 'флаг battle:seeded проставлен (его проверяет init.php)');
    t_assert((int)($after['turn_time'] ?? 0) > 0, 'turn_time пережил round-trip сброса');
    // Зоны угрозы NPC пересчитаны при сборке (init без этого шага оставлял бы пустые массивы)
    $tz = $after['npcs']['TURRET1']['THREAT_ZONE'] ?? [];
    t_assert(is_array($tz) && count($tz) > 0, 'THREAT_ZONE турели пересчитана при сбросе (' . count($tz) . ' клеток)');
}

/**
 * T6. PARALYZED (бывш. FROZEN) — обездвиживающий cc-статус: попытка хода
 * отклоняется ЕЩЁ ДО применения движения штатной валидацией глагола, и игрок
 * получает ИГРОВОЕ предупреждение «Вы обездвижены», а не технический текст с
 * именем эффекта. Регрессия на баг: вместо понятного предупреждения игрок
 * получал лишь отказ правила (HTTP 400) без внятного текста.
 */
function test_paralyzed_blocks_move_with_game_message() {
    $state = harness_build_state();
    $pid = 'PLAYER1';
    // Чистый лист: эффект вешаем сами, статус/очки ставим в «можно действовать»
    $state['players'][$pid]['STATUS'] = 'IDLE';
    $state['players'][$pid]['ACTIONS'] = 3;
    $state['players'][$pid]['NEGATIVES'] = array();

    effects_apply($state, 'player', $pid, 'PARALYZED[2]');
    t_assert(effects_has($state['players'][$pid], 'PARALYZED'), 'PARALYZED наложился через effects_apply');

    $mv = effects_blocks($state['players'][$pid], 'move');
    t_assert($mv['blocked'] === true, 'PARALYZED блокирует глагол move');
    t_assert(strpos($mv['reason'], 'PARALYZED') === 0, 'причина блока — строка PARALYZED[n] из NEGATIVES');

    $v = action_validate($state, $pid, 'move');
    t_assert($v['success'] === false, 'ход обездвиженного игрока отклонён валидацией');
    t_assert(($v['message'] ?? '') === 'Вы обездвижены',
        'отказ содержит игровое сообщение «Вы обездвижены», а не текст ошибки эффекта');

    // Тот же путь, что делает action.php для ходa: movement сам зовёт action_validate
    $res = movement_process_move($state, $pid, 3, 3);
    t_assert($res['success'] === false && ($res['message'] ?? '') === 'Вы обездвижены',
        'movement_process_move вернул то же предупреждение (путь action.php → 400 + message)');

    // Атака способностями НЕ заблокирована: PARALYZED блокирует только move
    t_assert(effects_blocks($state['players'][$pid], 'attack')['blocked'] === false,
        'PARALYZED не блокирует атаку');
    t_assert(action_validate($state, $pid, 'attack')['success'] === true,
        'атака обездвиженного игрока разрешена');

    // Сообщение живёт в реестре эффектов — у STUNNED своего нет, остаётся общий текст
    $stun_state = $state;
    $stun_state['players'][$pid]['NEGATIVES'] = ['STUNNED[1]'];
    $sv = action_validate($stun_state, $pid, 'move');
    t_assert($sv['success'] === false && $sv['message'] === 'Игрок оглушен',
        'STUNNED по-прежнему отвечает «Игрок оглушен» (по STATUS)');
}

test_exit_transition();
test_respawn_via_teleport();
test_poll_room_filtering();
test_crossroom_targeting();
test_world_reset_restores_initial_state();
test_paralyzed_blocks_move_with_game_message();

echo "\n=== ИТОГ: {$GLOBALS['__assertions']} проверок, отказов: {$GLOBALS['__failures']} ===\n";
exit($GLOBALS['__failures'] > 0 ? 1 : 0);
