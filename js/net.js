// net.js — long polling, подключение игрока и отправка действий
// (initGame, joinGame, poll, sendAction).
// Часть модульного клиента: см. AGENTS.md §5.

/**
 * Подключение к бою по карточке персонажа (шаг 2 порядка вызовов, см. main.js):
 * POST php/join.php. Сервер валидирует карточку, выделяет слот спавна (uid 1..4)
 * и CAS-записью добавляет бойца в мир. Повторный вход того же uid идемпотентен
 * (перезагрузка страницы не создаёт второго персонажа).
 * @return {Promise<boolean>} true — можно входить на игровой экран
 */
async function joinGame(card) {
    const statusEl = document.getElementById('init-status');
    try {
        const resp = await fetch(CONFIG.PHP_URL + 'join.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(card),
        });
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { data = null; }

        if (!resp.ok || !data || !data.success) {
            // 409 — все спавн-места заняты / ник занят; 400 — карточка отклонена.
            const detail = data && data.message ? data.message : text.substring(0, 200);
            statusEl.textContent = 'Подключение отклонено (' + resp.status + '): ' + detail;
            statusEl.style.color = '#ff4444';
            return false;
        }
        if (data.rejoin) {
            statusEl.textContent = 'Соединение восстановлено: ' + data.message;
        }
        // Шаг 3: явный init.php перед боем (см. шапку main.js).
        return await initGame(data.player_id);
    } catch (err) {
        statusEl.textContent = 'Ошибка подключения: ' + err.message;
        statusEl.style.color = '#ff4444';
        return false;
    }
}

/**
 * Инициализация игры: вызов init.php (шаг 3 после успешного join) и старт polling.
 * @param {string|null} playerId id бойца из ответа join.php; null — бойцов в
 *        мире пока нет (poll найдёт состояние мира как есть).
 * @return {Promise<boolean>} true — бой загружен, polling запущен
 */
async function initGame(playerId) {
    if (playerId) state.playerId = playerId;
    
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
            return false;
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
            return false;
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
            return true;
        } else {
            statusEl.textContent = 'Ошибка: ' + initData.message;
            statusEl.style.color = '#ff4444';
            return false;
        }
    } catch (err) {
        statusEl.textContent = 'Ошибка подключения: ' + err.message;
        statusEl.style.color = '#ff4444';
        console.log('Init error:', err);
        return false;
    }
}

// === LONG POLLING ===

/**
 * ★ Синхронизация часов клиента с серверными (лечит дёрганье/телепорт движения).
 *
 * Причина бага: MOVE_START_TIME/MOVE_END_TIME — серверные microtime(true), а
 * старая анимация считала прогресс от момента ПОЛУЧЕНИЯ ответа (performance.now
 * при startMoveAnimation). Клиент всегда отстаёт от серверных часов примерно на
 * задержку доставки ответа: движение начиналось с опозданием («стартовая пауза»),
 * а к концу пути сервер уже переводил бойца в IDLE и сбрасывал локальную
 * анимацию — спрайт «допрыгивал» финишную клетку рывком. Для хода в 1 клетку
 * (MOVE_SPEED=3 → 0.33 с пути) эта лага ≈ мгновенному телепорту.
 *
 * Решение: хранить смещение clockOffsetMs (serverNow ≈ performance.now() + offset)
 * и интерполировать движение по СЕРВЕРНЫМ часам (см. animation.js). Поле
 * server_now фиксируется сервером в момент сборки ответа, поэтому offset
 * автоматически содержит сетевую задержку.
 *
 * Фильтр выбросов: единичные TCP-лаги дают скачки raw-offset; они глушатся
 * экспоненциальным сглаживанием в пределах окна 3×RTT (минимум 150 мс), а
 * большие скачки принимаются мягко (50%), чтобы спрайты не телепортировались.
 */
function syncClock(serverNowSec, rttMs) {
    if (typeof serverNowSec !== 'number' || !isFinite(serverNowSec)) return;
    // server_now зафиксирован между отправкой и получением; центр окна ≈ сейчас − RTT/2
    const rawOffset = serverNowSec * 1000 - (performance.now() - rttMs / 2);
    state.lastServerNow = serverNowSec;

    if (!state.clockSet) {
        state.clockOffsetMs = rawOffset;
        state.clockLastRttMs = rttMs;
        state.clockSet = true;
        return;
    }

    const delta = rawOffset - state.clockOffsetMs;
    const jitterWindow = Math.max(150, 3 * (state.clockLastRttMs || 0));
    if (Math.abs(delta) <= jitterWindow) {
        state.clockOffsetMs += delta * 0.25; // плавная фильтрация дрожащего значения
    } else {
        state.clockOffsetMs += delta * 0.5;  // резкий скачок — принимаем мягко
    }
    state.clockLastRttMs = rttMs;
}

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
    const pollStart = performance.now();
    
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
            // ★ Часы движения: сверяемся до render(), чтобы анимации этого
            // snapshot уже интерполировались по серверному времени.
            syncClock(data.server_now, performance.now() - pollStart);
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

// Очередь отправки действий (сериализатор «потери пакета»). Нужна, чтобы
// повтор НЕ накладывался на ещё «висящий» оригинальный запрос: если бы retry
// пришёл на сервер раньше оригинала, он был бы отвергнут валидацией, а
// оригинал применился бы позже — игрок увидел бы «действие не прошло».
//
// ★ ИСПРАВЛЕНИЕ РЕГРЕССИИ («пакеты теряются снова»): раньше здесь висел
// единственный промис actionInFlight, который каждая попытка разрешала в
// finally, НО рекурсивный вызов sendAction(...) из тела try выполнялся ДО
// этого finally. Рекурсия становилась новым владельцем очереди и ждала... тот
// же самый промис, который её предок собирался разрешить только ПОСЛЕ того,
// как рекурсия завершится. Возникало взаимное ожидание (deadlock): первая же
// сетевая ошибка/таймаут/5xx навечно вешали actionInFlight незавершённым
// промисом, и ЛЮБОЕ следующее действие клиента навсегда замирало на await —
// именно так выглядел баг: уведомление приходило (или не приходило), а по
// факту действие больше никогда не отправлялось, пока не перезагрузишь страницу.
//
// Теперь очередь — это цикл while c явным chained-resolve: хвост очереди
// обновляется ДО ожидания предыдущей попытки, рекурсии нет, взаймной блокировки
// нет. Действия одного клиента отправляются строго последовательно.
let actionQueueTail = Promise.resolve();

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
async function sendAction(action, params) {
    const MAX_RETRIES = 3;
    const actionId = nextActionId(); // новое действие — новый идемпотентный id

    const body = {
        action: action,
        player: state.playerId,
        ...params,
        action_id: actionId
    };

    // Ставим попытку в хвост очереди: prev — предыдущий незавершённый хвост.
    const prev = actionQueueTail;
    let releaseTail;
    actionQueueTail = new Promise(resolve => { releaseTail = resolve; });

    // Ждём предыдущую попытку вне try/finally: если бы ожидание было внутри,
    // его разрешение зависело бы от finally ТЕКУЩЕЙ попытки — классический
    // самодедлок, съевший все действия после первого ретрая.
    await prev.catch(() => {});

    try {
        let retryCount = 0;
        while (true) {
            let retryNeeded = false;
            let finalResult = null;

            try {
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
                        retryNeeded = true;
                    } else {
                        // Штатные отказые ответы сервера (4xx, например «Вы обездвижены»
                        // при PARALYZED) приходят с HTTP 400 и JSON-телом {success:false,
                        // message}. Показываем сообщение сервера как внутриигровое
                        // предупреждение, а не техническую ошибку. «Ошибка сервера» —
                        // только для тел без читаемого message (и для 5xx после ретраев).
                        let serverMessage = null;
                        try {
                            const errData = JSON.parse(errorText);
                            if (errData && typeof errData.message === 'string' && errData.message) {
                                serverMessage = errData.message;
                            }
                        } catch (e) { /* тело не JSON — покажем generic-ошибку */ }

                        if (serverMessage) {
                            showNotification(serverMessage, 'warning');
                            finalResult = { success: false, message: serverMessage };
                        } else {
                            showNotification('Ошибка сервера: ' + resp.status, 'error');
                            finalResult = { success: false, message: 'HTTP error' };
                        }
                    }
                } else {
                    const responseText = await resp.text();

                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch (e) {
                        console.log('Action JSON parse error. Raw response:', responseText.substring(0, 200));

                        // Retry при ошибке парсинга: обрванный/грязный ответ =
                        // возможная «потеря пакета»; повтор с тем же action_id
                        // не удвоит действие.
                        if (retryCount < MAX_RETRIES) {
                            console.log(`Retry ${retryCount + 1}/${MAX_RETRIES}`);
                            retryNeeded = true;
                        } else {
                            showNotification('Ошибка ответа сервера', 'error');
                            finalResult = { success: false, message: 'JSON parse error' };
                        }
                    }

                    if (result) {
                        if (!result.success) {
                            console.log('Action failed:', result.message);
                            showNotification(result.message, 'warning');
                        } else {
                            // Успешное действие (duplicate:true — это подтверждение
                            // действия, применённого ранее, чей ответ был потерян;
                            // для игрока тот же успех)
                            showNotification('Действие выполнено', 'success');
                        }
                        finalResult = result;
                    }
                }
            } catch (err) {
                console.log('Action error:', err);

                // Retry при ошибке подключения (fetch/AbortSignal.timeout)
                if (retryCount < MAX_RETRIES) {
                    console.log(`Retry ${retryCount + 1}/${MAX_RETRIES}`);
                    retryNeeded = true;
                } else {
                    showNotification('Ошибка подключения: ' + err.message, 'error');
                    finalResult = { success: false, message: err.message };
                }
            }

            if (!retryNeeded) return finalResult;

            retryCount++;
            await new Promise(resolve => setTimeout(resolve, 1000)); // Ждём 1 секунду
        }
    } finally {
        // Разрешаем хвост ВСЕГДА, даже если выше бросило исключение, — иначе
        // вся очередь действий клиента зависнет навсегда («пропавшие пакеты»).
        releaseTail();
    }
}

