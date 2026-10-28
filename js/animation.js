// animation.js — превью поворота спрайта и интерполяция движения.
// Часть модульного клиента: см. AGENTS.md §5.

// === РЕНДЕРИНГ ===

/**
 * Мгновенный «превью» поворота собственного игрока при клике Attack/Ability.
 * Повторяет серверную формулу TURN (battle.php/action.php): горизонтальное
 * действие переписывает направление, вертикальное — сохраняет последнее.
 * Нужен, потому что статус ATTACK/ABILITY приходит с сервера быстрее,
 * чем обновлённый TURN (тот доедет только со следующим long-poll ответом).
 */
function previewTurn(targetX, targetY) {
    if (!state.data) return;
    const player = state.data.players[state.playerId];
    if (!player) return;
    const pos = parseXY(player.CUR_POSITION_XY);
    if (targetX === pos.x) return; // вертикальное действие — направление не меняем
    const entity = document.querySelector(`[data-player-id="${state.playerId}"]`);
    if (entity) applyTurnClass(entity, targetX < pos.x ? 'LEFT' : 'RIGHT');
}

/**
 * Применить отражение спрайта по направлению TURN.
 * Механика: div персонажа зеркалится по горизонтали (scaleX(-1)) когда TURN === 'LEFT'.
 * Значения 'RIGHT'/'UP'/'DOWN' (и любые другие) — без отражения.
 * Класс turn-left живёт на элементе постоянно и переключается только здесь;
 * анимация движения (inline transform в animateMove) обязана сохранять scaleX сам.
 */
function applyTurnClass(entity, turn) {
    if (!entity) return;
    entity.classList.toggle('turn-left', turn === 'LEFT');
}

/**
 * Обновить существующий элемент игрока
 */
function updatePlayerEntity(entity, player) {
    // Обновляем статус
    const statusEl = entity.querySelector('.entity-status');
    if (statusEl) {
        statusEl.textContent = player.STATUS.toLowerCase();
    }
    
    // Обновляем класс статуса.
    // ВАЖНО: turn-left НЕ должен затираться — TURN приходит с сервера отдельно.
    entity.classList.remove('status-idle', 'status-moving', 'status-attack', 'status-ability', 'status-death', 'status-decay', 'status-stunned');
    entity.classList.add('status-' + player.STATUS.toLowerCase());
    
    // Отражение спрайта по направлению TURN (только лево/право)
    applyTurnClass(entity, player.TURN);
    
    // Обновляем HP bar
    const hpFill = entity.querySelector('.entity-hp-fill');
    if (hpFill && player.MAX_HITPOINTS > 0) {
        hpFill.style.width = (player.HITPOINTS / player.MAX_HITPOINTS * 100) + '%';
    }
    
    // Обновляем Shield bar
    const shieldFill = entity.querySelector('.entity-shield-fill');
    if (shieldFill && player.MAX_SHIELD > 0) {
        shieldFill.style.width = (player.SHIELD / player.MAX_SHIELD * 100) + '%';
    }
    
    // Обновляем LIFE indicator
    let lifeEl = entity.querySelector('.life-indicator');
    if (player.LIFE !== undefined) {
        if (!lifeEl) {
            lifeEl = document.createElement('div');
            lifeEl.className = 'life-indicator';
            lifeEl.style.cssText = 'position:absolute;top:-10px;right:-5px;font-size:0.6em;color:#ffd93d;';
            entity.appendChild(lifeEl);
        }
        lifeEl.textContent = '♥' + player.LIFE;
    }
}

/**
 * Запустить анимацию перемещения для игрока
 */
function startMoveAnimation(playerId, startPos, endPos, serverStartTime, serverEndTime, animKey) {
    // Длительность в миллисекундах
    const duration = (serverEndTime - serverStartTime) * 1000;
    
    // Сохраняем анимацию с серверными временами
    state.animations.set(animKey, {
        playerId,
        startPos,
        endPos,
        serverStartTime, // Серверное время начала (в секундах)
        serverEndTime,   // Серверное время окончания (в секундах)
        duration,
        localStartTime: performance.now() // Локальное время для расчёта кадров
    });
    
    // Запускаем анимацию
    requestAnimationFrame(() => animateMove(animKey));
}

/**
 * Анимация перемещения (вызывается каждый кадр)
 */
function animateMove(animKey) {
    const anim = state.animations.get(animKey);
    if (!anim) return;
    
    // Вычисляем прошедшее время с момента начала анимации (в миллисекундах)
    const elapsedMs = performance.now() - anim.localStartTime;
    const progress = Math.min(1, elapsedMs / anim.duration);
    
    // Находим элемент игрока
    const entity = document.querySelector(`[data-player-id="${anim.playerId}"]`);
    if (!entity) {
        state.animations.delete(animKey);
        return;
    }
    
    // Вычисляем текущую позицию (линейная интерполяция)
    const currentX = anim.startPos.x + (anim.endPos.x - anim.startPos.x) * progress;
    const currentY = anim.startPos.y + (anim.endPos.y - anim.startPos.y) * progress;
    
    // Определяем текущую клетку и смещение внутри неё
    const cellX = Math.floor(currentX + 0.5); // Округляем к ближайшей клетке
    const cellY = Math.floor(currentY + 0.5);
    const offsetX = (currentX - cellX) * CONFIG.CELL_SIZE;
    const offsetY = (currentY - cellY) * CONFIG.CELL_SIZE;
    
    // Перемещаем элемент в нужную клетку (без transition чтобы не было рывков)
    const targetCell = document.getElementById(`cell-${cellX}-${cellY}`);
    if (targetCell && entity.parentElement !== targetCell) {
        entity.style.transition = 'none';
        targetCell.appendChild(entity);
    }
    
    // Применяем плавное смещение внутри клетки.
    // ВАЖНО: scaleX должен сохраняться — иначе отражение (turn-left) теряется во время пути.
    const flip = entity.classList.contains('turn-left') ? ' scaleX(-1)' : '';
    entity.style.transform = `translate(${offsetX}px, ${offsetY}px)` + flip;
    
    // Продолжаем анимацию если не завершена
    if (progress < 1) {
        requestAnimationFrame(() => animateMove(animKey));
    } else {
        // Анимация завершена - убираем transform (отражение обеспечивает класс turn-left через CSS)
        entity.style.transform = '';
        state.animations.delete(animKey);
        
        // Синхронизируем позицию и направление с сервером (если есть данные)
        if (state.data && state.data.players && state.data.players[anim.playerId]) {
            const player = state.data.players[anim.playerId];
            applyTurnClass(entity, player.TURN);
            const finalPos = parseXY(player.CUR_POSITION_XY);
            const finalCell = document.getElementById(`cell-${finalPos.x}-${finalPos.y}`);
            if (finalCell && entity.parentElement !== finalCell) {
                entity.style.transition = 'none';
                finalCell.appendChild(entity);
            }
        }
    }
}

