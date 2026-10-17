<?php
/**
 * action.php — HTTP-эндпоинт действий игроков (тонкий слой).
 *
 * Принимает POST JSON {action, player, ...} и маршрутизирует в движок:
 *   move    → systems/movement
 *   attack  → systems/combat
 *   ability → systems/abilities (+ трата действия/статус/TURN здесь, т.к.
 *             это «обёртка глагола», а не боевая механика)
 * Сохранение — всегда через world_save() (единая точка инкремента версии).
 */

// Буферизация вывода
ob_start();

require_once __DIR__ . '/engine/bootstrap.php';

setCorsHeaders();

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);

    if (!$input) {
        jsonError('Неверный формат данных. Ожидается JSON. Получено: ' . substr($raw_input, 0, 100));
    }

    $action = $input['action'] ?? null;
    $player_id = $input['player'] ?? null;

    if (!$action || !$player_id) {
        jsonError('Не указан action или player');
    }

    $state = world_load();

    if (!isset($state['players'][$player_id])) {
        // 404-подобная ситуация: раньше здесь бросался необработанный случай → HTTP 500
        closeRedis();
        jsonError('Игрок не найден: ' . $player_id . '. Сначала инициализируйте бой через init.php', 400);
    }

    // Единая валидация глагола ДО любой мутации состояния (dead/stunned/moving/no-actions).
    // Ловит баги рефакторинга: «мёртвый применяет действия», «залипший STUNNED».
    $known_actions = ['move', 'attack', 'ability'];
    if (!in_array($action, $known_actions, true)) {
        closeRedis();
        jsonError('Неизвестное действие: ' . $action, 400);
    }
    $verb = $action; // move|attack|ability — тот же словарь, что у effects_blocks
    $v = action_validate($state, $player_id, $verb);
    if (!$v['success']) {
        closeRedis();
        // Отклонение действия — это штатная ситуация, а не серверная ошибка: 400 + JSON
        jsonError($v['message'], 400);
    }

    $result = ['success' => false, 'message' => 'Неизвестное действие'];

    switch ($action) {
        case 'move':
            $target_x = (int)($input['x'] ?? -1);
            $target_y = (int)($input['y'] ?? -1);
            if (!isValidPosition($target_x, $target_y)) {
                $result = ['success' => false, 'message' => 'Неверные координаты'];
                break;
            }
            $result = movement_process_move($state, $player_id, $target_x, $target_y);
            break;

        case 'attack':
            $target_x = (int)($input['x'] ?? -1);
            $target_y = (int)($input['y'] ?? -1);
            if (!isValidPosition($target_x, $target_y)) {
                $result = ['success' => false, 'message' => 'Неверные координаты'];
                break;
            }
            $result = combat_process_attack($state, $player_id, $target_x, $target_y);
            break;

        case 'ability':
            $ability_name = $input['ability'] ?? null;
            $target_x = (int)($input['x'] ?? -1);
            $target_y = (int)($input['y'] ?? -1);

            if (!$ability_name) {
                $result = ['success' => false, 'message' => 'Не указана способность'];
                break;
            }

            // Способность должна быть у игрока (строка "NAME[range]")
            $has_ability = false;
            foreach ($state['players'][$player_id]['ABILITIES'] as $ab) {
                if (strpos($ab, $ability_name) === 0) { $has_ability = true; break; }
            }
            if (!$has_ability) {
                $result = ['success' => false, 'message' => 'Способность не доступна'];
                break;
            }

            if ($state['players'][$player_id]['ACTIONS'] <= 0) {
                $result = ['success' => false, 'message' => 'Нет очков действий'];
                break;
            }
            if ($state['players'][$player_id]['STATUS'] === 'MOVING') {
                $result = ['success' => false, 'message' => 'Нельзя использовать способность во время движения'];
                break;
            }
            // CC-блокировка способности — спрашиваем effects (не парсим NEGATIVES)
            $block = effects_blocks($state['players'][$player_id], 'ability');
            if ($block['blocked']) {
                $result = ['success' => false, 'message' => 'Игрок оглушен'];
                break;
            }
            if ($state['players'][$player_id]['STATUS'] === 'STUNNED') {
                $result = ['success' => false, 'message' => 'Игрок оглушен'];
                break;
            }

            $ability_result = applyAbility($state, $player_id, $ability_name, $target_x, $target_y);

            if ($ability_result['success']) {
                $state['players'][$player_id]['ACTIONS']--;
                $state['players'][$player_id]['STATUS'] = 'ABILITY';
                $state['players'][$player_id]['ATTACK_TIME'] = time();
                // TURN — каноническая формула из engine/rules (только лево/право)
                $ab_pos = parseXY($state['players'][$player_id]['CUR_POSITION_XY']);
                $new_turn = rules_turn_formula($ab_pos['x'], $target_x, $state['players'][$player_id]['TURN']);
                if ($new_turn !== null) $state['players'][$player_id]['TURN'] = $new_turn;
            }
            $result = $ability_result;
            break;

        default:
            $result = ['success' => false, 'message' => 'Неизвестное действие: ' . $action];
    }

    if ($result['success']) {
        $state = world_log($state, $result['message']);

        // Финализация начала движения: фиксируем виртуальные часы мира, чтобы
        // catch-up-тик в poll прошёл весь путь клетка-за-клеткой (ловушки и
        // зоны угрозы на промежуточных клетках обязаны сработать даже если
        // клиент вернётся к серверу уже после анимации хода).
        if ($action === 'move') {
            movement_finalize_start($state, $player_id);
        }

        // Retry-логика сохранения (3 попытки), версия инкрементит world_save
        $saveSuccess = false;
        $saveAttempts = 0;
        while (!$saveSuccess && $saveAttempts < 3) {
            try {
                world_save($state);
                $saveSuccess = true;
            } catch (Exception $e) {
                $saveAttempts++;
                if ($saveAttempts >= 3) {
                    closeRedis();
                    jsonError('Не удалось сохранить состояние после 3 попыток: ' . $e->getMessage());
                }
                usleep(100000);
            }
        }

        closeRedis();
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'version' => (int)getRedis()->get(REDIS_VERSION),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        closeRedis();
        jsonError($result['message']);
    }

} catch (Exception $e) {
    jsonError('Ошибка обработки действия: ' . $e->getMessage());
} catch (Throwable $e) {
    jsonError('Критическая ошибка: ' . $e->getMessage());
}
