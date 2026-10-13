<?php
/**
 * init.php - Инициализация боевой сессии (упрощённая версия для отладки)
 */

// Включаем все ошибки
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Буферизация вывода
ob_start();

try {
    // Загружаем конфиг
    require_once __DIR__ . '/config.php';
    
    // Устанавливаем заголовки
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    
    // Очищаем буфер (на случай если PHP что-то вывел)
    ob_clean();
    
    // Проверяем Redis
    $redis = getRedis();
    
    // Очищаем предыдущее состояние
    $redis->flushDB();
    
    // Загружаем данные игроков
    $data_file = __DIR__ . '/../data/battledata.json';
    if (!file_exists($data_file)) {
        throw new Exception('Файл не найден: ' . $data_file);
    }
    
    $json_content = file_get_contents($data_file);
    $battle_data = json_decode($json_content, true);
    if (!$battle_data) {
        throw new Exception('Ошибка парсинга battledata.json: ' . json_last_error_msg());
    }
    
    // Загружаем карту комнаты
    $room_file = __DIR__ . '/../data/B1.json';
    if (!file_exists($room_file)) {
        throw new Exception('Файл не найден: ' . $room_file);
    }
    
    $room_json = file_get_contents($room_file);
    $room_data = json_decode($room_json, true);
    if (!$room_data) {
        throw new Exception('Ошибка парсинга B1.json: ' . json_last_error_msg());
    }
    
    // Инициализируем состояние
    $state = [
        'players' => $battle_data['players'] ?? [],
        'objects' => $battle_data['objects'] ?? [],
        'traps' => $battle_data['traps'] ?? [],
        'npcs' => $battle_data['npcs'] ?? [],
        'room' => $room_data,
        'turn_time' => time(),
        'projectiles' => [],
        'version' => 1,
        'log' => [[
            'time' => time(),
            'message' => 'Бой инициализирован. Игроков: ' . count($battle_data['players'])
        ]]
    ];
    
    // Подключаем items.php для функции calculateThreatZone
    require_once __DIR__ . '/items.php';
    
    // Вычисляем зоны угрозы для всех NPC
    foreach ($state['npcs'] as $npc_id => &$npc) {
        if ($npc['STATUS'] !== 'DESTROYED' && $npc['STATUS'] !== 'DEATH') {
            $npc['THREAT_ZONE'] = calculateThreatZone($state, $npc_id);
            $state = addLog($state, "NPC {$npc_id}: зона угрозы вычислена (" . count($npc['THREAT_ZONE']) . " клеток)");
        }
    }
    
    // Сохраняем в Redis
    saveBattleState($state);
    
    // Выводим результат
    echo json_encode([
        'success' => true,
        'message' => 'Бой инициализирован',
        'players' => array_keys($state['players']),
        'room' => $room_data['room_id']
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Очищаем буфер
    ob_clean();
    
    // Выводим ошибку
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Очищаем буфер
    ob_clean();
    
    // Выводим ошибку
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Критическая ошибка: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
