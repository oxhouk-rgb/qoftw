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
    animations: new Map(), // Храним анимации для каждого игрока

    // ★ Синхронизация часов (лечит «дёрганье/телепорт» движения): смещение
    // локальных часов относительно серверных, мс: serverNow ≈ performance.now() + offset.
    // Обновляется каждым poll-ответом по полю server_now (см. syncClock в net.js).
    clockOffsetMs: 0,
    clockSet: false,       // был ли хотя бы один ответ с server_now (до этого — тупая оценка RTT/2)
    clockLastRttMs: 0,     // RTT последнего ответа с server_now (для окна фильтра выбросов)
    lastServerNow: 0       // серверные часы момента последнего poll-ответа (для мгновенной
                           // дозаписи позиции бойца после завершения его анимации)
};

/**
 * ★ Перевод локальных часов (performance.now, мс) в серверные (unix, сек) —
 * шкала MOVE_START_TIME / MOVE_END_TIME / server_now. До первого ответа с
 * server_now offset = 0, и функция вырождается в прежний «локальный отсчёт»
 * (обратная совместимость с серверами без поля server_now).
 */
function serverTimeSec(localMs) {
    return ((localMs !== undefined ? localMs : performance.now()) + state.clockOffsetMs) / 1000;
}

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

