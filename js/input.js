// input.js — клик по клетке, диалог действий, доступность действий.
// Часть модульного клиента: см. AGENTS.md §5.

/**
 * Обработчик клика по клетке
 */
function onCellClick(x, y) {
    if (!state.data) return;
    
    const player = state.data.players[state.playerId];
    if (!player) return;
    
    // Блокируем меню во время движения
    if (player.STATUS === 'MOVING') {
        showNotification('Нельзя действовать во время движения', 'warning');
        return;
    }
    
    // Проверяем есть ли очки действий
    if (player.ACTIONS <= 0) {
        showNotification('Нет очков действий', 'warning');
        return;
    }
    
    const playerPos = parseXY(player.CUR_POSITION_XY);
    const distance = Math.abs(playerPos.x - x) + Math.abs(playerPos.y - y);
    
    // Проверяем есть ли хоть какие-то доступные действия
    const hasAnyAction = checkAvailableActions(player, playerPos, x, y, distance);
    
    if (!hasAnyAction) {
        showNotification('Нет доступных действий для этой клетки', 'info');
        return;
    }
    
    state.selectedCell = { x, y };
    showActionDialog(x, y);
}

/**
 * Проверка наличия доступных действий для клетки
 */
function checkAvailableActions(player, playerPos, x, y, distance) {
    // Проверяем перемещение
    if (distance > 0 && distance <= 5 && (playerPos.x === x || playerPos.y === y)) {
        if (isPathClear(playerPos.x, playerPos.y, x, y) && isCellFreeForMove(x, y)) {
            return true;
        }
    }
    
    // Проверяем атаку
    const lineOfSight = hasLineOfSight(playerPos.x, playerPos.y, x, y);
    if (distance > 0 && distance <= player.RANGE && lineOfSight) {
        return true;
    }
    
    // Проверяем способности
    const abilities = player.ABILITIES || [];
    for (let i = 0; i < abilities.length; i++) {
        const abRange = parseAbilityRange(abilities[i]);
        if (distance <= abRange && (abRange === 0 || lineOfSight)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Показать диалог действий для клетки
 */
function showActionDialog(x, y) {
    const dialog = document.getElementById('action-dialog');
    const coords = document.getElementById('dialog-coords');
    coords.textContent = '(' + x + ', ' + y + ')';
    
    const player = state.data.players[state.playerId];
    if (!player) return;
    
    const playerPos = parseXY(player.CUR_POSITION_XY);
    const distance = Math.abs(playerPos.x - x) + Math.abs(playerPos.y - y);
    
    // Проверяем доступность пути (для перемещения)
    const pathClear = isPathClear(playerPos.x, playerPos.y, x, y);
    
    // Проверяем что конечная клетка свободна для перемещения
    const cellFree = isCellFreeForMove(x, y);
    
    // Проверяем линию видимости (для атаки и способностей)
    const lineOfSight = hasLineOfSight(playerPos.x, playerPos.y, x, y);
    
    // Кнопка перемещения
    const btnMove = document.getElementById('btn-move');
    const maxMove = effectiveMoveRange(player); // SWIFT/SLOWED учтены сервером (MOVE_RANGE)
    const canMove = distance > 0 && 
                    distance <= maxMove && 
                    player.STATUS !== 'MOVING' && 
                    player.ACTIONS > 0 &&
                    (playerPos.x === x || playerPos.y === y) && // По прямой
                    pathClear && // Путь свободен
                    cellFree; // Конечная клетка свободна
    btnMove.disabled = !canMove;
    
    // Кнопка атаки
    const btnAttack = document.getElementById('btn-attack');
    const canAttack = distance > 0 && 
                      distance <= player.RANGE && 
                      player.STATUS !== 'MOVING' && 
                      player.ACTIONS > 0 &&
                      lineOfSight; // Есть линия видимости
    btnAttack.disabled = !canAttack;
    
    // Кнопки способностей
    const abilities = player.ABILITIES || [];
    const btnAb1 = document.getElementById('btn-ability1');
    const btnAb2 = document.getElementById('btn-ability2');
    const btnAb3 = document.getElementById('btn-ability3');
    
    // Способность 1
    if (abilities[0]) {
        const abName = parseAbilityName(abilities[0]);
        const abRange = parseAbilityRange(abilities[0]);
        btnAb1.textContent = '✨ ' + abName + ' [R:' + abRange + ']';
        const canUse = distance <= abRange && 
                       player.STATUS !== 'MOVING' && 
                       player.ACTIONS > 0 &&
                       (abRange === 0 || lineOfSight); // Для range=0 (самоприменение) не нужна линия видимости
        btnAb1.disabled = !canUse;
        btnAb1.style.display = '';
        btnAb1.onclick = () => { previewTurn(x, y); sendAction('ability', { ability: abName, x: x, y: y }); closeDialog(); };
    } else {
        btnAb1.style.display = 'none';
    }
    
    // Способность 2
    if (abilities[1]) {
        const abName = parseAbilityName(abilities[1]);
        const abRange = parseAbilityRange(abilities[1]);
        btnAb2.textContent = '✨ ' + abName + ' [R:' + abRange + ']';
        const canUse = distance <= abRange && 
                       player.STATUS !== 'MOVING' && 
                       player.ACTIONS > 0 &&
                       (abRange === 0 || lineOfSight);
        btnAb2.disabled = !canUse;
        btnAb2.style.display = '';
        btnAb2.onclick = () => { previewTurn(x, y); sendAction('ability', { ability: abName, x: x, y: y }); closeDialog(); };
    } else {
        btnAb2.style.display = 'none';
    }
    
    // Способность 3
    if (abilities[2]) {
        const abName = parseAbilityName(abilities[2]);
        const abRange = parseAbilityRange(abilities[2]);
        btnAb3.textContent = '✨ ' + abName + ' [R:' + abRange + ']';
        const canUse = distance <= abRange && 
                       player.STATUS !== 'MOVING' && 
                       player.ACTIONS > 0 &&
                       (abRange === 0 || lineOfSight);
        btnAb3.disabled = !canUse;
        btnAb3.style.display = '';
        btnAb3.onclick = () => { previewTurn(x, y); sendAction('ability', { ability: abName, x: x, y: y }); closeDialog(); };
    } else {
        btnAb3.style.display = 'none';
    }
    
    // Обработчики для move и attack
    btnMove.onclick = () => { sendAction('move', { x: x, y: y }); closeDialog(); };
    btnAttack.onclick = () => { previewTurn(x, y); sendAction('attack', { x: x, y: y }); closeDialog(); };
    
    // Позиционируем диалог рядом с кликом
    dialog.style.left = '50%';
    dialog.style.top = '50%';
    dialog.style.transform = 'translate(-50%, -50%)';
    
    dialog.style.display = 'block';
}

/**
 * Закрыть диалог
 */
function closeDialog() {
    document.getElementById('action-dialog').style.display = 'none';
    state.selectedCell = null;
}

document.getElementById('btn-close-dialog').addEventListener('click', closeDialog);

