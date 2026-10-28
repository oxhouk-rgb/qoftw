// net.js — long polling и отправка действий (initGame, poll, sendAction).
// Часть модульного клиента: см. AGENTS.md §5.

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

