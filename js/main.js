// main.js — точка входа: навешивает обработчики кнопок выбора игрока.
// Часть модульного клиента: см. AGENTS.md §5. Загружается ПОСЛЕДНИМ.

// === ИНИЦИАЛИЗАЦИЯ ===

document.addEventListener('DOMContentLoaded', function() {
    // Обработчики кнопок выбора игрока
    document.querySelectorAll('.player-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            initGame(this.dataset.player);
        });
    });
});

/**
 * Инициализация игры - вызов init.php и начало polling
 */
