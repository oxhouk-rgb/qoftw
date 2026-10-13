<?php
/**
 * action.php - Обработка действий игроков
 * 
 * Принимает POST-запросы с действиями:
 * - move: перемещение
 * - attack: базовая атака
 * - ability: применение способности
 */

// Буферизация вывода
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/battle.php';
require_once __DIR__ . '/abilities.php';
require_once __DIR__ . '/items.php';

setCorsHeaders();

// Обрабатываем OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Получаем данные запроса
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
    
    // Получаем текущее состояние
    $state = getBattleState();
    
    // Проверяем существование игрока
    if (!isset($state['players'][$player_id])) {
        jsonError('Игрок не найден: ' . $player_id . '. Сначала инициализируйте бой через init.php');
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
            
            $result = processMove($state, $player_id, $target_x, $target_y);
            break;
            
        case 'attack':
            $target_x = (int)($input['x'] ?? -1);
            $target_y = (int)($input['y'] ?? -1);
            
            if (!isValidPosition($target_x, $target_y)) {
                $result = ['success' => false, 'message' => 'Неверные координаты'];
                break;
            }
            
            $result = processAttack($state, $player_id, $target_x, $target_y);
            break;
            
        case 'ability':
            $ability_name = $input['ability'] ?? null;
            $target_x = (int)($input['x'] ?? -1);
            $target_y = (int)($input['y'] ?? -1);
            
            if (!$ability_name) {
                $result = ['success' => false, 'message' => 'Не указана способность'];
                break;
            }
            
            // Проверяем что у игрока есть эта способность
            $has_ability = false;
            foreach ($state['players'][$player_id]['ABILITIES'] as $ab) {
                if (strpos($ab, $ability_name) === 0) {
                    $has_ability = true;
                    break;
                }
            }
            
            if (!$has_ability) {
                $result = ['success' => false, 'message' => 'Способность не доступна'];
                break;
            }
            
            // Проверяем очки действий
            if ($state['players'][$player_id]['ACTIONS'] <= 0) {
                $result = ['success' => false, 'message' => 'Нет очков действий'];
                break;
            }
            
            // Проверяем статус
            if ($state['players'][$player_id]['STATUS'] === 'MOVING') {
                $result = ['success' => false, 'message' => 'Нельзя использовать способность во время движения'];
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
                $result = $ability_result;
            } else {
                $result = $ability_result;
            }
            break;
            
        default:
            $result = ['success' => false, 'message' => 'Неизвестное действие: ' . $action];
    }
    
    // Если действие успешно - добавляем в лог и сохраняем
    if ($result['success']) {
        $state = addLog($state, $result['message']);
        
        // Сохраняем состояние с retry логикой
        $saveSuccess = false;
        $saveAttempts = 0;
        $maxSaveAttempts = 3;
        
        while (!$saveSuccess && $saveAttempts < $maxSaveAttempts) {
            try {
                saveBattleState($state);
                
                // Увеличиваем версию для уведомления клиентов
                $redis = getRedis();
                $redis->incr(REDIS_VERSION);
                
                $saveSuccess = true;
            } catch (Exception $e) {
                $saveAttempts++;
                if ($saveAttempts >= $maxSaveAttempts) {
                    throw new Exception('Не удалось сохранить состояние после ' . $maxSaveAttempts . ' попыток: ' . $e->getMessage());
                }
                // Ждём перед повторной попыткой
                usleep(100000); // 100мс
                // Переподключаемся к Redis
                closeRedis();
            }
        }
    }
    
    // Закрываем Redis соединение
    closeRedis();
    
    jsonResponse($result);
    
} catch (Exception $e) {
    closeRedis();
    jsonError('Action ошибка: ' . $e->getMessage());
}
