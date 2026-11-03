// config.js — конфигурация клиента (путь к PHP, размеры сетки).
// Часть модульного клиента: см. AGENTS.md §5. Загружается в game.html ПЕРВЫМ.

/**
 * game.js - Минимальный JavaScript для боевой системы
 * 
 * Функции:
 * - Centrifugo (WebSocket) для получения обновлений (fallback-источник url — join.php)
 * - Отправка действий на сервер
 * - Отрисовка игрового поля
 * - Обработка кликов
 */

// Конфигурация
const CONFIG = {
    PHP_URL: 'php/',  // Путь к PHP файлам
    CENTRIFUGO_WS_URL: 'ws://127.0.0.1:8000/connection/websocket', // fallback: сервер отдаёт url в ответе join.php (state.ws.url)
    GRID_WIDTH: 16,
    GRID_HEIGHT: 12,
    CELL_SIZE: 40
};

