// path.js — UX-проверки пути/занятости/видимости (финальная валидация всегда серверная!).
// Часть модульного клиента: см. AGENTS.md §5.

// === ОБРАБОТКА КЛИКОВ ===

/**
 * Проверка прямой линии между двумя точками (нет ли препятствий)
 */
function isPathClear(x1, y1, x2, y2) {
    // Должно быть по прямой (только по X или только по Y)
    if (x1 !== x2 && y1 !== y2) return false;
    
    const room = state.data.room;
    if (!room || !room.grid) return false;
    
    // Определяем направление
    const dx = x2 > x1 ? 1 : (x2 < x1 ? -1 : 0);
    const dy = y2 > y1 ? 1 : (y2 < y1 ? -1 : 0);
    
    // Проверяем все клетки по пути (включая конечную)
    let cx = x1 + dx;
    let cy = y1 + dy;
    
    while (true) {
        // Проверяем стену
        if (room.grid[cy] && room.grid[cy][cx] === 0) {
            return false;
        }
        
        // Проверяем объекты (блокируют путь) - только на промежуточных клетках
        if (cx !== x2 || cy !== y2) {
            if (state.data.objects) {
                for (const oid in state.data.objects) {
                    const obj = state.data.objects[oid];
                    if (obj.STATUS === 'DESTROYED') continue;
                    if (!inCurrentRoom(obj)) continue; // ★ комнатная изоляция
                    const pos = parseXY(obj.POSITION_XY);
                    if (pos.x === cx && pos.y === cy) {
                        return false;
                    }
                }
            }
            
            // Проверяем других игроков (блокируют путь) - только на промежуточных клетках
            if (state.data.players) {
                for (const pid in state.data.players) {
                    if (pid === state.playerId) continue;
                    const player = state.data.players[pid];
                    if (player.STATUS === 'DEATH' || player.STATUS === 'DECAY' || player.STATUS === 'REMOVED') continue;
                    if (!inCurrentRoom(player)) continue; // ★ комнатная изоляция
                    const pos = parseXY(player.CUR_POSITION_XY);
                    if (pos.x === cx && pos.y === cy) {
                        return false;
                    }
                }
            }

            // Проверяем живых NPC (TURRET и т.п. — непроходимы как препятствие)
            if (state.data.npcs) {
                for (const nid in state.data.npcs) {
                    const npc = state.data.npcs[nid];
                    if (npc.STATUS === 'DESTROYED' || npc.STATUS === 'DEATH' || npc.STATUS === 'REMOVED') continue;
                    if (!inCurrentRoom(npc)) continue; // ★ комнатная изоляция
                    const pos = parseXY(npc.POSITION_XY);
                    if (pos.x === cx && pos.y === cy) {
                        return false;
                    }
                }
            }
        }
        
        // Если достигли цели - выходим
        if (cx === x2 && cy === y2) {
            break;
        }
        
        cx += dx;
        cy += dy;
    }
    
    return true;
}

/**
 * Проверка свободна ли клетка для перемещения
 */
function isCellFreeForMove(x, y) {
    const room = state.data.room;
    if (!room || !room.grid) return false;
    
    // Проверяем стену
    if (room.grid[y] && room.grid[y][x] === 0) {
        return false;
    }
    
    // Проверяем неразрушаемые объекты
    if (state.data.objects) {
        for (const oid in state.data.objects) {
            const obj = state.data.objects[oid];
            if (obj.STATUS === 'DESTROYED') continue;
            if (!inCurrentRoom(obj)) continue; // ★ комнатная изоляция
            const pos = parseXY(obj.POSITION_XY);
            if (pos.x === x && pos.y === y) {
                return false;
            }
        }
    }
    
    // Проверяем других игроков
    if (state.data.players) {
        for (const pid in state.data.players) {
            if (pid === state.playerId) continue;
            const player = state.data.players[pid];
            if (player.STATUS === 'DEATH' || player.STATUS === 'DECAY' || player.STATUS === 'REMOVED') continue;
            if (!inCurrentRoom(player)) continue; // ★ комнатная изоляция
            const pos = parseXY(player.CUR_POSITION_XY);
            if (pos.x === x && pos.y === y) {
                return false;
            }
        }
    }

    // Проверяем живых NPC (TURRET и т.п. — клетку занять нельзя, пока NPC жив)
    if (state.data.npcs) {
        for (const nid in state.data.npcs) {
            const npc = state.data.npcs[nid];
            if (npc.STATUS === 'DESTROYED' || npc.STATUS === 'DEATH' || npc.STATUS === 'REMOVED') continue;
            if (!inCurrentRoom(npc)) continue; // ★ комнатная изоляция
            const pos = parseXY(npc.POSITION_XY);
            if (pos.x === x && pos.y === y) {
                return false;
            }
        }
    }
    
    return true;
}

/**
 * Проверка линии видимости для способностей (может проходить через пустые клетки)
 */
function hasLineOfSight(x1, y1, x2, y2) {
    // Должно быть по прямой
    if (x1 !== x2 && y1 !== y2) return false;
    
    const room = state.data.room;
    if (!room || !room.grid) return false;
    
    const dx = x2 > x1 ? 1 : (x2 < x1 ? -1 : 0);
    const dy = y2 > y1 ? 1 : (y2 < y1 ? -1 : 0);
    
    let cx = x1 + dx;
    let cy = y1 + dy;
    
    while (cx !== x2 || cy !== y2) {
        // Проверяем стену (стены блокируют линию видимости)
        if (room.grid[cy] && room.grid[cy][cx] === 0) {
            return false;
        }
        
        // Объекты не блокируют способности (они проходят сквозь)
        // Игроки не блокируют способности
        
        cx += dx;
        cy += dy;
    }
    
    return true;
}

