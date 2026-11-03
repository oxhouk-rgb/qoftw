// utils.js — уведомления и мелкие парсеры (parseXY и др.).
// Часть модульного клиента: см. AGENTS.md §5.

/**
 * Показать ненавязчивое уведомление
 */
function showNotification(message, type = 'info') {
    // Создаём контейнер если его нет
    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        container.style.cssText = `
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        `;
        document.body.appendChild(container);
    }
    
    // Создаём уведомление
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    
    const colors = {
        info: { bg: '#16213e', border: '#4ecca3', icon: 'ℹ️' },
        success: { bg: '#1b4332', border: '#4ecca3', icon: '✅' },
        warning: { bg: '#4a3b00', border: '#ffd93d', icon: '⚠️' },
        error: { bg: '#4a1c1c', border: '#e94560', icon: '❌' }
    };
    
    const color = colors[type] || colors.info;
    
    notification.style.cssText = `
        background: ${color.bg};
        border: 2px solid ${color.border};
        border-radius: 8px;
        padding: 12px 20px;
        color: #eee;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        animation: slideIn 0.3s ease-out;
        pointer-events: auto;
        min-width: 200px;
        max-width: 400px;
    `;
    
    notification.innerHTML = `<span style="margin-right: 8px;">${color.icon}</span>${message}`;
    
    container.appendChild(notification);
    
    // Автоматическое удаление через 3 секунды
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// === УТИЛИТЫ ===

/**
 * Парсить координаты "X,Y" -> {x, y}
 */
function parseXY(xy) {
    const parts = xy.split(',');
    return { x: parseInt(parts[0]), y: parseInt(parts[1]) };
}

/**
 * Получить название способности из строки "FIRE WAVE[3]"
 */
function parseAbilityName(abilityStr) {
    return abilityStr.replace(/\[\d+\]/, '');
}

/**
 * Получить дальность способности из строки "FIRE WAVE[3]"
 */
function parseAbilityRange(abilityStr) {
    const match = abilityStr.match(/\[(\d+)\]/);
    return match ? parseInt(match[1]) : 0;
}
