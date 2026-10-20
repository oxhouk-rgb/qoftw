<?php
/**
 * engine/rules.php — Единый источник истины для констант мира.
 *
 * ПРАВИЛО: любые числовые правила сетки/движения/хода живут ТОЛЬКО здесь.
 * Клиент (js/game.js) получает их из init.php в поле "rules" и больше не
 * дублирует значения (GRID_*, MOVE_SPEED, TURN_DURATION, MAX_MOVE_DISTANCE).
 * Формула хода TURN (зеркалирование спрайта) тоже канонизирована здесь —
 * см. rules_turn_formula().
 */

// КАЖДАЯ константа определяется через defined()-guard. Это защищает от
// фатального «Constant already defined»: config.php (legacy-слой Redis)
// historically содержит те же игровые константы и может быть загружен
// раньше или позже этого файла — порядок подключения не должен ломать процесс.
$rules_define = function (string $name, $value): void {
    if (!defined($name)) {
        define($name, $value);
    }
};

// Сетка
$rules_define('GRID_WIDTH', 16);
$rules_define('GRID_HEIGHT', 12);

// Движение
$rules_define('MAX_MOVE_DISTANCE', 5);   // базовая дальность хода (клеток)
$rules_define('MOVE_SPEED', 3);          // клеток в секунду (плавная анимация)

// Ход мира
$rules_define('TURN_DURATION', 5);       // секунд между processTurnStart
$rules_define('LONG_POLL_TIMEOUT', 25);  // секунд таймаут long polling

// Анимации
$rules_define('DEATH_ANIM_SECONDS', 1);  // DEATH -> DECAY
$rules_define('DECAY_ANIM_SECONDS', 1);  // DECAY -> respawn/removal
$rules_define('ATTACK_RESET_SECONDS', 1);// ATTACK/ABILITY -> IDLE
$rules_define('PROJECTILE_CAST_SECONDS', 1);  // cast -> hit
$rules_define('PROJECTILE_HIT_SECONDS', 2);   // hit -> done

// Сопротивление: каждое очко = 10% снижения урона, максимум 90%
$rules_define('RESISTANCE_PERCENT_PER_POINT', 10);
$rules_define('RESISTANCE_CAP_PERCENT', 90);

// Базовое правило мира: 1 ед. щита за ход. Исторически «непробиваемый щит»
// у PLAYER2/PLAYER4 под огнём турели был следствием пассивки ARMORED, а не
// этого значения; после переработки ARMORED/ARMOR CRUSH (см. systems/passives.php)
// реген остаётся 1.
$rules_define('SHIELD_REGEN_PER_TURN', 1);

unset($rules_define);

/**
 * Серверная формула направления спрайта TURN (только лево/право):
 * горизонтальное действие переписывает TURN, вертикальное — сохраняет.
 * Возвращает новое значение TURN или null если менять не нужно.
 * КЛИЕНТ (game.js mirrorTurn) обязан повторять эту же логику.
 */
function rules_turn_formula($cur_x, $target_x, $current_turn) {
    if ($target_x > $cur_x) return 'RIGHT';
    if ($target_x < $cur_x) return 'LEFT';
    return null; // не менять
}

/**
 * Пакет правил для отправки клиенту (поле "rules" в ответах init/poll).
 */
function rules_client_payload() {
    return [
        'GRID_WIDTH' => GRID_WIDTH,
        'GRID_HEIGHT' => GRID_HEIGHT,
        'MAX_MOVE_DISTANCE' => MAX_MOVE_DISTANCE,
        'MOVE_SPEED' => MOVE_SPEED,
        'TURN_DURATION' => TURN_DURATION,
    ];
}
