// state.js — глобальное состояние клиента + эффективная дальность хода.
// Часть модульного клиента: см. AGENTS.md §5.

// Состояние клиента
let state = {
    playerId: null,
    version: 0,
    data: null,
    selectedCell: null,
    polling: false,
    lastRoomId: null,   // ROOM_ID последнего poll-ответа (для перестройки поля при смене комнаты)
    currentRoomId: null, // ★ Комната, которую РИСУЕМ сейчас (grid этой сетки).
                         // Все клиентские проверки путей/занятости клеток обязаны
                         // учитывать только сущности ЭТОЙ комнаты — координаты в
                         // разных комнатах пересекаются (баг «урон по пустой
                         // клетке через стену»: сервер не задевает, а клиент
                         // подсвечивает/разрешает действие по позиции чужой комнаты).
    animations: new Map() // Храним анимации для каждого игрока
};

/**
 * ★ Пункт плана №4: эффективная дальность хода со всеми модификаторами.
 * Сервер считает её ОДНОЙ формулой (php/systems/movement.php →
 * movement_effective_range: MAX_MOVE_DISTANCE + SWIFT(passive) − SLOWED,
 * floor 1) и отдаёт в поле players[].MOVE_RANGE каждого poll-ответа.
 * Клиент НЕ дублирует формулу — использует готовое значение; fallback на
 * базовые 5 только если сервер вернул ответ без MOVE_RANGE (старая версия).
 */
function effectiveMoveRange(player) {
    if (!player) return 5;
    if (typeof player.MOVE_RANGE === 'number' && player.MOVE_RANGE > 0) {
        return player.MOVE_RANGE;
    }
    return 5;
}

