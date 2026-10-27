/**
 * game.js - Минимальный JavaScript для боевой системы
 * 
 * Функции:
 * - Long polling для получения обновлений
 * - Отправка действий на сервер
 * - Отрисовка игрового поля
 * - Обработка кликов
 */

// Конфигурация
const CONFIG = {
    PHP_URL: 'php/',  // Путь к PHP файлам
    GRID_WIDTH: 16,
    GRID_HEIGHT: 12,
    CELL_SIZE: 40
};

// Состояние клиента
let state = {
    playerId: null,
    version: 0,
    data: null,
    selectedCell: null,
    polling: false,
    lastRoomId: null,   // ROOM_ID последнего poll-ответа (для перестройки поля при смене комнаты)
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
async function initGame(playerId) {
    state.playerId = playerId;
    
    const statusEl = document.getElementById('init-status');
    statusEl.textContent = 'Инициализация боя...';
    statusEl.style.color = '#4ecca3';
    
    try {
        // Инициализируем бой
        const initResp = await fetch(CONFIG.PHP_URL + 'init.php');
        
        // Проверяем статус ответа
        if (!initResp.ok) {
            const errorText = await initResp.text();
            statusEl.textContent = 'HTTP ошибка ' + initResp.status + ': ' + errorText;
            statusEl.style.color = '#ff4444';
            return;
        }
        
        // Получаем текст ответа
        const responseText = await initResp.text();
        
        // Пытаемся распарсить JSON
        let initData;
        try {
            initData = JSON.parse(responseText);
        } catch (e) {
            statusEl.textContent = 'Ошибка парсинга JSON от сервера. Ответ: ' + responseText.substring(0, 200);
            statusEl.style.color = '#ff4444';
            console.log('Raw response:', responseText);
            return;
        }
        
        if (initData.success) {
            statusEl.textContent = 'Бой начат! Загрузка...';
            
            // Переключаем на игровой экран
            document.getElementById('login-screen').style.display = 'none';
            document.getElementById('game-screen').style.display = 'grid';
            
            // Строим сетку
            buildGrid();
            
            // Начинаем long polling
            startPolling();
        } else {
            statusEl.textContent = 'Ошибка: ' + initData.message;
            statusEl.style.color = '#ff4444';
        }
    } catch (err) {
        statusEl.textContent = 'Ошибка подключения: ' + err.message;
        statusEl.style.color = '#ff4444';
        console.log('Init error:', err);
    }
}

// === LONG POLLING ===

/**
 * Запуск цикла long polling
 */
function startPolling() {
    if (state.polling) return;
    state.polling = true;
    poll();
}

/**
 * Один запрос long polling
 */
async function poll() {
    if (!state.polling) return;
    
    try {
        const url = CONFIG.PHP_URL + 'poll.php?v=' + state.version + '&player=' + state.playerId;
        const resp = await fetch(url);
        
        if (!resp.ok) {
            console.log('Poll HTTP error:', resp.status);
            // Ждем перед повторной попыткой
            setTimeout(poll, 2000);
            return;
        }
        
        const responseText = await resp.text();
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.log('Poll JSON parse error. Raw response:', responseText.substring(0, 200));
            // Ждем перед повторной попыткой
            setTimeout(poll, 2000);
            return;
        }
        
        if (data && data.version) {
            state.version = data.version;
            state.data = data;
            render(data);
        }
    } catch (err) {
        console.log('Poll error:', err);
    }
    
    // Следующий запрос
    if (state.polling) {
        setTimeout(poll, 100);
    }
}

// === ОТПРАВКА ДЕЙСТВИЙ ===

/**
 * Счётчик action_id: уникален в пределах клиента/сессии. Каждое отправляемое
 * действие получает свой id, который НЕ меняется при ретраях — на нём сервер
 * строит идемпотентность (см. world_action_ack_get/set в php/engine/world.php).
 */
let actionIdCounter = 0;
function nextActionId() {
    actionIdCounter++;
    return 'a' + Date.now().toString(36) + '_' + actionIdCounter;
}

// Промис последней незавершённой попытки отправки действия. Нужен, чтобы
// повтор НЕ накладывался на ещё «висящий» оригинальный запрос: если бы retry
// пришёл на сервер раньше оригинала, он был бы отвергнут валидацией, а
// оригинал применился бы позже — игрок увидел бы «действие не прошло».
let actionInFlight = Promise.resolve();

/**
 * Отправить действие на сервер с retry логикой.
 *
 * ★ Защита от «потери пакета» (критично для прототипа онлайн-игры):
 * повтор запускается при сетевой ошибке, таймауте, HTTP 5xx или битом JSON —
 * во всех этих случаях непонятно, дошло ли действие до мира:
 *   - запрос потерялся в сети → повтор доставит его, действие применится;
 *   - ответ потерян, но действие уже применено → сервер увидит знакомый
 *     action_id и НЕ применит его повторно (ответит сохранённое
 *     подтверждение, поле duplicate:true).
 * Валидные отказы правил (HTTP 400: вне радиуса, путь заблокирован, нет AP)
 * не ретраятся — они детерминированы.
 */
async function sendAction(action, params, retryCount = 0, actionId = null) {
    const MAX_RETRIES = 3;

    if (actionId === null) {
        actionId = nextActionId(); // новое действие — новый идемпотентный id
    }

    const body = {
        action: action,
        player: state.playerId,
        ...params,
        action_id: actionId
    };

    let finish;
    const prev = actionInFlight;
    actionInFlight = new Promise(resolve => { finish = resolve; });

    try {
        await prev; // ждём завершения предыдущей попытки этого же действия
        const resp = await fetch(CONFIG.PHP_URL + 'action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: AbortSignal.timeout(10000) // Таймаут 10 секунд
        });

        if (!resp.ok) {
            const errorText = await resp.text();
            console.log('Action HTTP error:', resp.status, errorText);

            // Retry при ошибке сервера (действие могло НЕ примениться —
            // повтор безопасен благодаря идемпотентности по action_id)
            if (resp.status >= 500 && retryCount < MAX_RETRIES) {
                console.log(`Retry ${retryCount + 1}/${MAX_RETRIES}`);
                await new Promise(resolve => setTimeout(resolve, 1000)); // Ждём 1 секунду
                return sendAction(action, params, retryCount + 1, actionId);
            }

            showNotification('Ошибка сервера: ' + resp.status, 'error');
            return { success: false, message: 'HTTP error' };
        }

        const responseText = await resp.text();

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            console.log('Action JSON parse error. Raw response:', responseText.substring(0, 200));

            // Retry при ошибке парсинга: обрванный/грязный ответ = возможная
            // «потеря пакета»; повтор с тем же action_id не удвоит действие.
            if (retryCount < MAX_RETRIES) {
                console.log(`Retry ${retryCount + 1}/${MAX_RETRIES}`);
                await new Promise(resolve => setTimeout(resolve, 1000));
                return sendAction(action, params, retryCount + 1, actionId);
            }

            showNotification('Ошибка ответа сервера', 'error');
            return { success: false, message: 'JSON parse error' };
        }

        if (!result.success) {
            console.log('Action failed:', result.message);
            showNotification(result.message, 'warning');
        } else {
            // Успешное действие (duplicate:true — это подтверждение действия,
            // применённого ранее, чей ответ был потерян; для игрока тот же успех)
            showNotification('Действие выполнено', 'success');
        }

        return result;
    } catch (err) {
        console.log('Action error:', err);

        // Retry при ошибке подключения (fetch/AbortSignal.timeout)
        if (retryCount < MAX_RETRIES) {
            console.log(`Retry ${retryCount + 1}/${MAX_RETRIES}`);
            await new Promise(resolve => setTimeout(resolve, 1000));
            return sendAction(action, params, retryCount + 1, actionId);
        }

        showNotification('Ошибка подключения: ' + err.message, 'error');
        return { success: false, message: err.message };
    } finally {
        finish();
    }
}

// === ПОСТРОЕНИЕ СЕТКИ ===

/**
 * Построить HTML сетку игрового поля
 */
function buildGrid() {
    const container = document.getElementById('grid-container');
    container.innerHTML = '';
    
    for (let y = 0; y < CONFIG.GRID_HEIGHT; y++) {
        for (let x = 0; x < CONFIG.GRID_WIDTH; x++) {
            const cell = document.createElement('div');
            cell.className = 'cell';
            cell.dataset.x = x;
            cell.dataset.y = y;
            cell.id = 'cell-' + x + '-' + y;
            
            cell.addEventListener('click', function() {
                onCellClick(x, y);
            });
            
            container.appendChild(cell);
        }
    }
}

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

/**
 * Отрисовка состояния
 */
function render(data) {
    if (!data) return;
    
    renderGrid(data);
    renderPlayerInfo(data);
    renderLog(data);
    renderAbilities(data);
    renderProjectiles(data);
    renderTimer(data);
    checkVictory(data);
}

/**
 * Подсветка зон вокруг игрока
 */
function highlightPlayerZones(data) {
    const player = data.players[state.playerId];
    if (!player || player.STATUS === 'MOVING' || player.STATUS === 'DEATH' || player.STATUS === 'REMOVED') {
        return;
    }
    
    const playerPos = parseXY(player.CUR_POSITION_XY);
    const maxMove = effectiveMoveRange(player); // SWIFT/SLOWED учтены сервером (MOVE_RANGE)
    
    // Подсветка зоны перемещения (зелёная)
    for (let dx = -maxMove; dx <= maxMove; dx++) {
        for (let dy = -maxMove; dy <= maxMove; dy++) {
            const x = playerPos.x + dx;
            const y = playerPos.y + dy;
            const distance = Math.abs(dx) + Math.abs(dy);
            
            // Проверяем границы и дистанцию
            if (x < 0 || x >= CONFIG.GRID_WIDTH || y < 0 || y >= CONFIG.GRID_HEIGHT) continue;
            if (distance === 0 || distance > maxMove) continue;
            
            // Должно быть по прямой
            if (dx !== 0 && dy !== 0) continue;
            
            // Проверяем путь и что конечная клетка свободна
            if (isPathClear(playerPos.x, playerPos.y, x, y) && isCellFreeForMove(x, y)) {
                const cell = document.getElementById(`cell-${x}-${y}`);
                if (cell) {
                    cell.classList.add('highlight-move');
                }
            }
        }
    }
    
    // Подсветка зоны атаки/способностей (красная)
    const maxRange = player.RANGE;
    for (let dx = -maxRange; dx <= maxRange; dx++) {
        for (let dy = -maxRange; dy <= maxRange; dy++) {
            const x = playerPos.x + dx;
            const y = playerPos.y + dy;
            const distance = Math.abs(dx) + Math.abs(dy);
            
            // Проверяем границы и дистанцию
            if (x < 0 || x >= CONFIG.GRID_WIDTH || y < 0 || y >= CONFIG.GRID_HEIGHT) continue;
            if (distance === 0 || distance > maxRange) continue;
            
            // Должно быть по прямой
            if (dx !== 0 && dy !== 0) continue;
            
            // Проверяем линию видимости
            if (hasLineOfSight(playerPos.x, playerPos.y, x, y)) {
                const cell = document.getElementById(`cell-${x}-${y}`);
                if (cell && !cell.classList.contains('highlight-move')) {
                    cell.classList.add('highlight-attack');
                }
            }
        }
    }
}

/**
 * Отрисовка сетки с сущностями
 */
function renderGrid(data) {
    // Очищаем только снаряды (они временные)
    document.querySelectorAll('.projectile').forEach(e => e.remove());
    
    // Сбрасываем подсветку
    document.querySelectorAll('.cell').forEach(cell => {
        // Снимаем и прошлые метки карты (wall/stair): при смене комнаты стены
        // и двери полностью перерисовываются по новому grid ниже.
        cell.classList.remove('highlight-move', 'highlight-attack', 'highlight-ability', 'wall', 'stair');
    });
    
    const room = data.room;

    // ★ Мультикомнатный мир: при смене комнаты (переход в дверь/респаун в
    // SPAWN_ROOM) сбрасываем локальные анимации движения — иначе «призрачный»
    // rAF interpolation тащит спрайт от клетки старой комнаты к координатам
    // новой поверх серверной позиции.
    if (state.lastRoomId !== null && data.room_id && data.room_id !== state.lastRoomId) {
        state.animations.clear();
    }
    if (data.room_id) state.lastRoomId = data.room_id;
    
    // Отрисовка стен
    if (room && room.grid) {
        for (let y = 0; y < CONFIG.GRID_HEIGHT; y++) {
            for (let x = 0; x < CONFIG.GRID_WIDTH; x++) {
                const cell = document.getElementById('cell-' + x + '-' + y);
                if (room.grid[y] && room.grid[y][x] === 0) {
                    cell.classList.add('wall');
                } else {
                    cell.classList.remove('wall');
                }
            }
        }
        
        // Лестницы/двери между комнатами. Подписываем дверь названием комнаты
        // назначения (exits["x,y"] = КУДА; exit_rooms["x,y"] = ОТКУДА — обратная
        // дверь), чтобы игрок видел, куда ведёт переход.
        if (room.exits) {
            Object.keys(room.exits).forEach(key => {
                const [x, y] = key.split(',').map(Number);
                const cell = document.getElementById('cell-' + x + '-' + y);
                if (cell) {
                    cell.classList.add('stair');
                    cell.title = '-> ' + room.exits[key];
                }
            });
        }
        if (room.exit_rooms) {
            Object.keys(room.exit_rooms).forEach(key => {
                const [x, y] = key.split(',').map(Number);
                const cell = document.getElementById('cell-' + x + '-' + y);
                if (cell) {
                    cell.classList.add('stair');
                    cell.title = 'из ' + room.exit_rooms[key];
                }
            });
        }
    }
    
    // Отрисовка/обновление игроков
    if (data.players) {
        Object.keys(data.players).forEach(pid => {
            const player = data.players[pid];
            
            // Удаляем элемент только когда игрок полностью убран из боя сервером (REMOVED).
            // Стадии DEATH/DECAY НЕ удаляем — div должен оставаться на поле всё время
            // проигрывания обеих анимаций (по 1 секунде на стадию), респавн/удаление
            // происходит на сервере после DECAY.
            if (player.STATUS === 'REMOVED') {
                const existing = document.querySelector(`[data-player-id="${pid}"]`);
                if (existing) existing.remove();
                return;
            }
            
            // Ищем существующий элемент игрока
            let entity = document.querySelector(`[data-player-id="${pid}"]`);

            // Если сервер снял статус MOVING (например, игрок мгновенно
            // остановлен ловушкой на клетке мины), досрочно завершаем
            // локальную анимацию движения — иначе «призрачный» rAF будет
            // тащить спрайт к прежней конечной точке поверх серверной позиции.
            if (entity && player.STATUS !== 'MOVING') {
                state.animations.forEach((anim, key) => {
                    if (key.startsWith(`${pid}_`)) {
                        state.animations.delete(key);
                    }
                });
            }

            if (!entity) {
                // Создаём новый элемент
                entity = createPlayerEntity(player, pid);
                if (!entity) return;
                entity.dataset.playerId = pid;
                
                // Определяем начальную позицию
                const pos = parseXY(player.CUR_POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                if (cell) {
                    cell.appendChild(entity);
                }
            } else {
                // Обновляем существующий элемент
                updatePlayerEntity(entity, player);
            }
            
            // Обрабатываем перемещение
            if (player.STATUS === 'MOVING' && player.MOVE_START_TIME && player.MOVE_END_TIME && player.END_POSITION_XY !== player.CUR_POSITION_XY) {
                // Игрок двигается - запускаем клиентскую анимацию
                const startPos = parseXY(player.START_POSITION_XY);
                const endPos = parseXY(player.END_POSITION_XY);
                
                // Уникальный ключ для этой анимации (на основе времени начала)
                const animKey = `${pid}_${Math.floor(player.MOVE_START_TIME)}`;
                
                // Проверяем не запущена ли уже эта анимация
                if (!state.animations.has(animKey)) {
                    // Удаляем старые анимации для этого игрока
                    state.animations.forEach((anim, key) => {
                        if (key.startsWith(`${pid}_`)) {
                            state.animations.delete(key);
                        }
                    });
                    
                    // Мгновенно доворачиваем спрайт по серверному TURN (не дожидаясь следующего поллинга)
                    applyTurnClass(entity, player.TURN);
                    
                    // Запускаем новую анимацию
                    startMoveAnimation(pid, startPos, endPos, player.MOVE_START_TIME, player.MOVE_END_TIME, animKey);
                }
            } else {
                // Игрок не двигается - показываем серверную позицию
                const pos = parseXY(player.CUR_POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                
                // Проверяем нет ли активной анимации для этого игрока
                const hasAnimation = Array.from(state.animations.keys()).some(key => 
                    key.startsWith(`${pid}_`)
                );
                
                if (!hasAnimation) {
                    // Перемещаем без анимации на серверную позицию
                    entity.style.transform = '';
                    if (cell && entity.parentElement !== cell) {
                        entity.style.transition = 'none';
                        cell.appendChild(entity);
                    }
                }
            }
        });
    }
    
    // Отрисовка/обновление объектов
    if (data.objects) {
        Object.keys(data.objects).forEach(oid => {
            const obj = data.objects[oid];
            
            // Удаляем если уничтожен
            if (obj.STATUS === 'DESTROYED') {
                const existing = document.querySelector(`[data-object-id="${oid}"]`);
                if (existing) existing.remove();
                return;
            }
            
            let entity = document.querySelector(`[data-object-id="${oid}"]`);
            
            if (!entity) {
                entity = createObjectEntity(obj);
                entity.dataset.objectId = oid;
                const pos = parseXY(obj.POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                if (cell) cell.appendChild(entity);
            }
        });
    }
    
    // Отрисовка/обновление ловушек (видны только своему владельцу)
    if (data.traps) {
        Object.keys(data.traps).forEach(tid => {
            const trap = data.traps[tid];
            
            // Удаляем если не INTACT или не своя
            if (trap.STATUS !== 'INTACT' || (trap.ALLY !== state.playerId && trap.ALLY !== 'NONE')) {
                const existing = document.querySelector(`[data-trap-id="${tid}"]`);
                if (existing) existing.remove();
                return;
            }
            
            let entity = document.querySelector(`[data-trap-id="${tid}"]`);
            
            if (!entity) {
                entity = createTrapEntity(trap);
                entity.dataset.trapId = tid;
                const pos = parseXY(trap.POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                if (cell) cell.appendChild(entity);
            }
        });
    }
    
    // Отрисовка/обновление NPC
    if (data.npcs) {
        Object.keys(data.npcs).forEach(nid => {
            const npc = data.npcs[nid];
            
            // Удаляем если уничтожен
            if (npc.STATUS === 'DESTROYED') {
                const existing = document.querySelector(`[data-npc-id="${nid}"]`);
                if (existing) existing.remove();
                return;
            }
            
            let entity = document.querySelector(`[data-npc-id="${nid}"]`);
            
            if (!entity) {
                entity = createNpcEntity(npc);
                entity.dataset.npcId = nid;
                const pos = parseXY(npc.POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                if (cell) cell.appendChild(entity);
            } else {
                // Обновляем статус NPC
                const statusEl = entity.querySelector('.entity-status');
                if (statusEl) {
                    statusEl.textContent = npc.STATUS.toLowerCase();
                }
                entity.className = 'entity npc-entity status-' + npc.STATUS.toLowerCase();
            }
        });
    }
    
    // Подсветка зон вокруг игрока
    highlightPlayerZones(data);
}

/**
 * Создать элемент игрока
 */
function createPlayerEntity(player, pid) {
    const el = document.createElement('div');
    const status = player.STATUS.toLowerCase();
    
    // Не рисуем удалённых игроков
    if (status === 'removed') return null;
    
    el.className = 'entity player-entity status-' + status;
    // Отражение спрайта при первичной отрисовке (TURN из battledata.json / сервера)
    applyTurnClass(el, player.TURN);
    el.style.backgroundColor = player.COLOR || '#888';
    el.style.borderColor = pid === state.playerId ? '#ffffff' : (player.COLOR || '#888');
    
    const nameEl = document.createElement('div');
    nameEl.className = 'entity-name';
    nameEl.textContent = player.NICKNAME;
    el.appendChild(nameEl);
    
    const statusEl = document.createElement('div');
    statusEl.className = 'entity-status';
    statusEl.textContent = status;
    el.appendChild(statusEl);
    
    // HP bar
    if (player.MAX_HITPOINTS > 0) {
        const hpBar = document.createElement('div');
        hpBar.className = 'entity-hp-bar';
        const hpFill = document.createElement('div');
        hpFill.className = 'entity-hp-fill';
        hpFill.style.width = (player.HITPOINTS / player.MAX_HITPOINTS * 100) + '%';
        hpBar.appendChild(hpFill);
        el.appendChild(hpBar);
    }
    
    // Shield bar
    if (player.MAX_SHIELD > 0) {
        const shBar = document.createElement('div');
        shBar.className = 'entity-shield-bar';
        const shFill = document.createElement('div');
        shFill.className = 'entity-shield-fill';
        shFill.style.width = (player.MAX_SHIELD > 0 ? player.SHIELD / player.MAX_SHIELD * 100 : 0) + '%';
        shBar.appendChild(shFill);
        el.appendChild(shBar);
    }
    
    // LIFE indicator
    if (player.LIFE !== undefined) {
        const lifeEl = document.createElement('div');
        lifeEl.style.cssText = 'position:absolute;top:-10px;right:-5px;font-size:0.6em;color:#ffd93d;';
        lifeEl.textContent = '♥' + player.LIFE;
        el.appendChild(lifeEl);
    }
    
    return el;
}

/**
 * Создать элемент объекта
 */
function createObjectEntity(obj) {
    const el = document.createElement('div');
    el.className = 'entity object-entity';
    el.textContent = '📦';
    return el;
}

/**
 * Создать элемент ловушки
 */
function createTrapEntity(trap) {
    const el = document.createElement('div');
    el.className = 'entity trap-entity';
    el.textContent = '💣';
    return el;
}

/**
 * Создать элемент NPC
 */
function createNpcEntity(npc) {
    const el = document.createElement('div');
    el.className = 'entity npc-entity';
    
    const nameEl = document.createElement('div');
    nameEl.className = 'entity-name';
    nameEl.textContent = 'NPC';
    el.appendChild(nameEl);
    
    const statusEl = document.createElement('div');
    statusEl.className = 'entity-status';
    statusEl.textContent = npc.STATUS.toLowerCase();
    el.appendChild(statusEl);
    
    return el;
}

/**
 * Отрисовка информации о текущем игроке
 */
function renderPlayerInfo(data) {
    const player = data.players[state.playerId];
    if (!player) return;
    
    document.getElementById('current-player-name').textContent = 
        player.NICKNAME + ' (' + player.CHAR_ID + ')';
    document.getElementById('current-player-hp').textContent = 
        '❤️ ' + player.HITPOINTS + '/' + player.MAX_HITPOINTS;
    document.getElementById('current-player-shield').textContent = 
        '🛡️ ' + player.SHIELD + '/' + player.MAX_SHIELD;
    document.getElementById('current-player-actions').textContent = 
        '⚡ ' + player.ACTIONS + '/' + player.MAX_ACTIONS;
    
    // Негативные эффекты
    const negContainer = document.getElementById('negatives-display');
    negContainer.innerHTML = '';
    if (player.NEGATIVES) {
        player.NEGATIVES.forEach(neg => {
            const badge = document.createElement('span');
            badge.className = 'negative-badge';
            badge.textContent = neg;
            negContainer.appendChild(badge);
        });
    }
}

/**
 * Отрисовка лога
 */
function renderLog(data) {
    const logEl = document.getElementById('log-content');
    logEl.innerHTML = '';
    
    if (data.log) {
        data.log.forEach(entry => {
            const div = document.createElement('div');
            div.className = 'log-entry';
            const time = new Date(entry.time * 1000).toLocaleTimeString();
            div.textContent = '[' + time + '] ' + entry.message;
            logEl.appendChild(div);
        });
        
        // Прокрутка вниз
        logEl.scrollTop = logEl.scrollHeight;
    }
}

/**
 * Отрисовка списка способностей
 */
function renderAbilities(data) {
    const player = data.players[state.playerId];
    if (!player) return;
    
    const list = document.getElementById('abilities-list');
    list.innerHTML = '';
    
    if (player.ABILITIES) {
        player.ABILITIES.forEach(ab => {
            const div = document.createElement('div');
            div.className = 'ability-item';
            const name = parseAbilityName(ab);
            const range = parseAbilityRange(ab);
            div.innerHTML = name + ' <span class="ability-range">[дальность: ' + range + ']</span>';
            list.appendChild(div);
        });
    }
    
    // Пассивки
    if (player.PASSIVES && player.PASSIVES.length > 0) {
        const passiveHeader = document.createElement('div');
        passiveHeader.style.marginTop = '8px';
        passiveHeader.style.color = '#4ecca3';
        passiveHeader.style.fontSize = '0.8em';
        passiveHeader.textContent = 'Пассивки:';
        list.appendChild(passiveHeader);
        
        player.PASSIVES.forEach(p => {
            const div = document.createElement('div');
            div.className = 'ability-item';
            div.style.borderLeftColor = '#4ecca3';
            div.textContent = p;
            list.appendChild(div);
        });
    }
}

/**
 * Отрисовка снарядов
 */
function renderProjectiles(data) {
    if (!data.projectiles) return;
    
    data.projectiles.forEach(proj => {
        const cell = document.getElementById('cell-' + proj.to_x + '-' + proj.to_y);
        if (!cell) return;
        
        const el = document.createElement('div');
        el.className = 'projectile projectile-' + proj.status;
        
        // Определяем тип по названию способности
        if (proj.ability.includes('FIRE') || proj.ability.includes('NAPALM')) {
            el.classList.add('type-fire');
        } else if (proj.ability.includes('ICE') || proj.ability.includes('FROST') || proj.ability.includes('BLIZZARD')) {
            el.classList.add('type-ice');
        } else if (proj.ability.includes('POISON')) {
            el.classList.add('type-poison');
        } else {
            el.classList.add('type-physical');
        }
        
        cell.appendChild(el);
    });
}

/**
 * Отрисовка таймера хода
 */
function renderTimer(data) {
    const timerEl = document.getElementById('timer-value');
    if (data.time_to_next_turn !== undefined) {
        timerEl.textContent = data.time_to_next_turn;
    }
}

/**
 * Проверка победы
 */
function checkVictory(data) {
    if (data.victory && data.victory.victory) {
        // Показываем оверлей победы
        let overlay = document.getElementById('victory-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'victory-overlay';
            overlay.innerHTML = '<h1>🏆 ПОБЕДА! 🏆<br><small>' + data.victory.winners.join(', ') + '</small></h1>';
            document.body.appendChild(overlay);
        }
    }
}

// === СИСТЕМА УВЕДОМЛЕНИЙ ===

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
