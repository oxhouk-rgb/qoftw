<?php
/**
 * Интеграционный тестовый двойник расширения phpredis (НЕ часть рантайма).
 *
 * Назначение: tests/integration_join.sh прогоняет реальные HTTP-эндпоинты
 * (init.php / join.php / poll.php) через `php -S` на машине, где phpredis не
 * установлен. Двойник объявляет класс Redis ДО загрузки config.php
 * (auto_prepend_file), поэтому весь остальной код — настоящий.
 *
 * ★ КЛЮЧЕВОЕ ОТЛИЧИЕ от харнесса tests/harness_engine.php: хранилище живёт в
 * суперглобале $GLOBALS['_redis_store'], НО php -S (как и FPM) сбрасывает
 * глобальную область на каждый запрос — для режима OVER-HTTP состояние
 * переживает серию запросов только через ФАЙЛОВЫЙ бэкинг FAKEREDIS_STORE_FILE
 * (дамп загружается при первой операции запроса, сохраняется после мутаций).
 * Без переменной поведение прежнее: общее хранилище в рамках одного запроса/
 * процесса (in-process транспорт tests/integration_join.php).
 *
 * Поддерживаются ровно те команды, которые использует боевой код:
 * connect/select/setOption/close/ping/get/set/incr/del/flushdb/watch/unwatch/
 * multi/exec + pipeline. WATCH в фейке — no-op: однопоточный процесс гонку
 * read-modify-write воспроизвести не может (для CAS-логики это означает
 * «конфликта нет», exec всегда проходит).
 */

if (!class_exists('Redis', false)) {

class RedisException extends Exception {}

class Redis {
    const PIPELINE = 1;
    const MULTI = 2;
    const OPT_READ_TIMEOUT = 3;

    public $connected = false;
    private $mode = null;   // null | 'pipeline' | 'tx'
    private $queue = [];    // [[cmd, args], ...]

    // ★ Файловый бэкинг хранилища (режим OVER-HTTP, FAKEREDIS_STORE_FILE):
    // встроенный сервер php -S ПЕРЕЗАПУСКАЕТ область глобальных переменных на
    // каждый запрос (как FPM) — «общее хранилище процесса» через $GLOBALS между
    // запросами НЕ живёт. При заданном файле дамп загружается при первой
    // операции запроса и сохраняется после каждой мутации: состояние боя
    // переживает серию HTTP-запросов сценария init → join×4 → …
    private function file() {
        $f = getenv('FAKEREDIS_STORE_FILE');
        return ($f === false || $f === '') ? null : $f;
    }

    private function sync() {
        $f = $this->file();
        if ($f !== null) {
            file_put_contents($f, serialize($GLOBALS['_redis_store'] ?? []), LOCK_EX);
        }
    }

    private function &store() {
        if (!isset($GLOBALS['_redis_store']) || !is_array($GLOBALS['_redis_store'])) {
            $GLOBALS['_redis_store'] = [];
            $f = $this->file();
            if ($f !== null && is_file($f)) {
                $d = @unserialize((string)file_get_contents($f));
                if (is_array($d)) $GLOBALS['_redis_store'] = $d;
            }
        }
        $ref =& $GLOBALS['_redis_store'];
        return $ref;
    }

    public function connect($h, $p, $t = 0) {
        // Режим shared-хранилища (для интеграционного HTTP-теста): все «соединения»
        // одного процесса php -S видят один и тот же мир через общий суперглобал.
        if (filter_var(getenv('FAKEREDIS_SHARED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->connected = true;
            return true;
        }
        // По умолчанию — честный сетевые попытки: без phpredis/redis-server любой
        // рантайм-скрипт получит понятную ошибку вместо тихой подмены.
        return false;
    }
    public function open($h, $p = 6379) { return $this->connect($h, $p); }
    public function close() { $this->connected = false; return true; }
    public function select($db) { return true; }
    public function setOption($o, $v) { return true; }
    public function ping($m = null) { return $this->connected ? true : false; }

    private function run($cmd, $args) {
        switch ($cmd) {
            case 'get': { $s =& $this->store(); return $s[$args[0]] ?? false; }
            case 'set': { $s =& $this->store(); $s[$args[0]] = (string)$args[1]; $this->sync(); return true; }
            case 'incr': {
                $s =& $this->store();
                $v = (int)($s[$args[0]] ?? 0) + 1;
                $s[$args[0]] = (string)$v;
                $this->sync();
                return $v;
            }
            case 'del': {
                $s =& $this->store();
                $n = 0;
                foreach ($args as $k) {
                    $kk = is_array($k) ? $k : [$k];
                    foreach ($kk as $x) if (isset($s[$x])) { unset($s[$x]); $n++; }
                }
                $this->sync();
                return $n;
            }
            case 'flushdb': { $s =& $this->store(); $s = []; $this->sync(); return true; }
            case 'keys': {
                // Глоб-паттерн (использует php/dump_state.php): поддержан суффикс '*'.
                $s =& $this->store();
                $pat = (string)$args[0];
                if (substr($pat, -1) === '*' && strpos($pat, '*') === strlen($pat) - 1) {
                    $pre = substr($pat, 0, -1);
                    $out = [];
                    foreach (array_keys($s) as $k) {
                        if ($pre === '' || strpos($k, $pre) === 0) $out[] = $k;
                    }
                    return $out;
                }
                throw new RedisException("fakeredis stub: keys поддержан только в форме 'префикс*'");
            }
            default:
                throw new RedisException("fakeredis stub: неподдерживаемая команда '$cmd'");
        }
    }

    public function multi($mode = 2) {
        $this->mode = ($mode === self::PIPELINE) ? 'pipeline' : 'tx';
        $this->queue = [];
        return $this;
    }
    public function watch($keys) { return true; }   // no-op (см. шапку файла)
    public function unwatch() { return true; }
    public function discard() { $this->mode = null; $this->queue = []; return true; }
    public function exec() {
        $out = [];
        foreach ($this->queue as $op) $out[] = $this->run($op[0], $op[1]);
        $this->mode = null; $this->queue = [];
        $this->sync(); // pipeline/tx: гарантируем дамп даже если run() не вызывал sync
        return $out;
    }

    public function __call($name, $args) {
        $cmd = strtolower($name);
        if ($this->mode !== null) { $this->queue[] = [$cmd, $args]; return $this; }
        return $this->run($cmd, $args);
    }
}

// ★ Рантайм-эндпоинты (init/join/poll/action) защищаются проверкой
// extension_loaded('redis') — CLI-двойник её не проходит. В режиме теста
// (FAKEREDIS_SHARED=1) подменяем и эту функцию тоже; без флага поведение
// честное: расширение действительно отсутствует.
// ★ Функция НЕ переобъявляется (PHP запрещает redeclare внутренних функций):
// вместо этого в shared-режиме объявляется заглушка расширения — проверка
// class_exists('Redis') уже пройдена выше, а эндпоинты спрашивают
// extension_loaded('redis'). Обход: регистрируем псевдо-расширение нельзя,
// поэтому тестовые эндпоинты должны спрашивать класс. Чтобы не менять рантайм,
// подменяем поведение через constant-страховку: phpredis при реальном
// расширении тоже объявляет класс Redis — значит «класс есть» и есть «расширение».
// Эндпоинты (init/join/poll/action) используют extension_loaded('redis') —
// проверяется на этапе запуска сервера ниже (см. run flag TEST_SKIP_EXT_CHECK).


} // class_exists guard
