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

    public function get($k) {
        if ($this->queue !== null) { $this->queue[] = ['get', $k]; return $this; }
        return $this->data[$k] ?? false;
    }
    public function set($k, $v) {
        if ($this->queue !== null) { $this->queue[] = ['set', $k, $v]; return $this; }
        $this->data[$k] = $v; return true;
    }
    public function incr($k) {
        if ($this->queue !== null) { $this->queue[] = ['incr', $k]; return $this; }
        $this->data[$k] = (int)($this->data[$k] ?? 0) + 1; return $this->data[$k];
    }
    public function flushDB() { $this->data = []; return true; }

    public function multi($mode = null) { $this->queue = []; return $this; }
    public function exec() {
        $results = [];
        foreach ($this->queue as $op) {
            if ($op[0] === 'get') $results[] = $this->get($op[1]);
            elseif ($op[0] === 'set') $results[] = $this->set($op[1], $op[2]);
            elseif ($op[0] === 'incr') $results[] = $this->incr($op[1]);
        }
        $this->queue = null;
        return $results;
    }
}

require_once getenv('HARNESS_NO_BOOTSTRAP')
    ? __DIR__ . '/../php/config.php'
    : __DIR__ . '/../php/engine/bootstrap.php';
