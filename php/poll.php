<?php
/**
 * poll.php - Long Polling endpoint
 * 
 * Клиент делает запрос и ждет появления новых данных.
 * Сервер держит соединение открытым до:
 * - Появления новой версии состояния
 * - Истечения таймаута (LONG_POLL_TIMEOUT секунд)
 */

// Буферизация вывода
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/battle.php';
// items.php и objects.php уже подключены через battle.php

setCorsHeaders();

// Получаем последнюю известную версию от клиента
$client_version = isset($_GET['v']) ? (int)$_GET['v'] : 0;
$player_id = isset($_GET['player']) ? $_GET['player'] : null;

// Устанавливаем таймаут выполнения
set_time_limit(LONG_POLL_TIMEOUT + 5);

try {
    $start_time = time();
    
while (true) {
    try {
        // Получаем Redis подключение
        $redis = getRedis();
        
        // Получаем текущее состояние
        $state = getBattleState();
        
        // Проверяем нужно ли обновить ход (каждые TURN_DURATION секунд)
        $now = time();
        if ($now - $state['turn_time'] >= TURN_DURATION) {
            // Новый ход!
            $state['turn_time'] = $now;
            
            // Обрабатываем начало хода (регенерация, негативные эффекты, восстановление очков)
            processTurnStart($state);
            
            // Обрабатываем NPC
            processNPCs($state);
            
            // Сохраняем
            saveBattleState($state);
            
            // Увеличиваем версию чтобы клиенты получили обновление
            $redis->incr(REDIS_VERSION);
        }
        
        // Обновляем перемещения и анимации
        updateMovements($state);
        updateDeathAnimations($state);
        updateProjectiles($state);
        resetAttackStatus($state);
        saveBattleState($state);
        
        // Проверяем появилась ли новая версия
        $current_version = (int)$redis->get(REDIS_VERSION);
        
        if ($current_version > $client_version) {
            // Есть новые данные - отправляем клиенту
            $response = [
                'version' => $current_version,
                'turn_time' => $state['turn_time'],
                'turn_duration' => TURN_DURATION,
                'time_to_next_turn' => max(0, TURN_DURATION - ($now - $state['turn_time'])),
                'players' => $state['players'],
                'objects' => $state['objects'],
                'traps' => $state['traps'],
                'npcs' => $state['npcs'],
                'room' => $state['room'],
                'projectiles' => $state['projectiles'],
                'log' => array_slice($state['log'], -10),
                'victory' => checkVictory($state)
            ];
            
            // Закрываем Redis соединение перед отправкой
            closeRedis();
            
            jsonResponse($response);
        }
        
        // Закрываем Redis соединение чтобы освободить его для других запросов
        closeRedis();
        
        // Проверяем таймаут
        if (time() - $start_time >= LONG_POLL_TIMEOUT) {
            // Таймаут - отправляем текущее состояние (возможно без изменений)
            $redis = getRedis();
            $current_version = (int)$redis->get(REDIS_VERSION);
            
            $response = [
                'version' => $current_version,
                'turn_time' => $state['turn_time'],
                'turn_duration' => TURN_DURATION,
                'time_to_next_turn' => max(0, TURN_DURATION - ($now - $state['turn_time'])),
                'players' => $state['players'],
                'objects' => $state['objects'],
                'traps' => $state['traps'],
                'npcs' => $state['npcs'],
                'room' => $state['room'],
                'projectiles' => $state['projectiles'],
                'log' => array_slice($state['log'], -10),
                'victory' => checkVictory($state),
                'timeout' => true
            ];
            
            closeRedis();
            jsonResponse($response);
        }
        
        // Ждем немного перед следующей проверкой
        usleep(500000); // 0.5 секунды
        
    } catch (Exception $e) {
        // При ошибке закрываем соединение и пробуем снова
        closeRedis();
        usleep(500000); // 0.5 секунды
    }
}    
} catch (Exception $e) {
    jsonError('Poll ошибка: ' . $e->getMessage());
}
