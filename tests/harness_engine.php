<?php
/**
 * ТЕСТОВЫЙ HARNESS (не часть рантайма). Подменяет только сетевой клиент Redis
 * фейковым in-memory классом ДО подключения config.php — весь остальной код
 * (config/engine/systems) настоящий. Используется для прогона инициализации
 * без запущенного redis-server.
 */
class Redis {
    public $data = [];
    const PIPELINE = 1;
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
    public function flushDB() { $this->data = []; return true; }

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
                public function exec() {
                    $r = [];
                    foreach ($this->ops as $op) {
                        $this->store->resetPipeline(); // каждый вызов — как к реальному серверу
                        if ($op[0] === 'get')  $r[] = $this->store->get($op[1]);
                        elseif ($op[0] === 'set') $r[] = $this->store->set($op[1], $op[2]);
                        elseif ($op[0] === 'incr') $r[] = $this->store->incr($op[1]);
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

require_once getenv('HARNESS_NO_BOOTSTRAP')
    ? __DIR__ . '/../php/config.php'
    : __DIR__ . '/../php/engine/bootstrap.php';

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

/** Загрузить мир как init.php: battledata.json + ленивые карты комнат. */
function harness_build_state() {
    $base = __DIR__ . '/../data/';
    $battle = json_decode(file_get_contents($base . 'battledata.json'), true);
    // Комната с сущностями = legacy-карта state['room'] (та же логика, что init.php)
    $entity_room = null;
    foreach (['objects', 'traps', 'npcs'] as $sec) {
        foreach (($battle[$sec] ?? []) as $edata) {
            if (!empty($edata['ROOM_ID'])) { $entity_room = $edata['ROOM_ID']; break 2; }
        }
    }
    $first_player = reset($battle['players']);
    $legacy_id = $entity_room ?: ($first_player['ROOM_ID'] ?? '');
    $legacy = json_decode(file_get_contents($base . $legacy_id . '.json'), true);

    // state — живой экземпляр теста: world_* принимают массив по значению,
    // поэтому мутации движка (log, rooms) возвращаем обратно в $state.
    $state = [
        'players'      => $battle['players'],
        'objects'      => $battle['objects'] ?? [],
        'traps'        => $battle['traps'] ?? [],
        'npcs'         => $battle['npcs'] ?? [],
        'room'         => $legacy,
        'rooms'        => [$legacy_id => $legacy],
        'turn_time'    => time() + 30,
        'projectiles'  => [],
        'version'      => 1,
        'log'          => [],
        'WORLD_MOVE_NOW' => microtime(true),
    ];
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
    t_assert(($p['STATUS'] ?? '') === 'IDLE', 'статус после перехода — IDLE');
    // Движение обрывается навсегда
    t_assert(!isset($p['MOVE_START_TIME']) && !isset($p['MOVE_END_TIME']), 'анимация движения сброшена при переходе');
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
    t_assert(($p['STATUS'] ?? '') === 'IDLE', 'после респауна статус IDLE');
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

    // Неизвестный ?player= → legacy-карта (обратная совместимость debug-клиентов)
    $resp_b1 = \TestPollNs\build_poll_response($state, 1, 'NPC_TEST_ABSENT');
    t_assert(($resp_b1['room']['room_id'] ?? '') === 'B1', 'poll без игрока: legacy-карта B1');
}

test_exit_transition();
test_respawn_via_teleport();
test_poll_room_filtering();

echo "\n=== ИТОГ: {$GLOBALS['__assertions']} проверок, отказов: {$GLOBALS['__failures']} ===\n";
exit($GLOBALS['__failures'] > 0 ? 1 : 0);
