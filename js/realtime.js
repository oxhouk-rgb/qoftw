// realtime.js — транспорт снапшотов мира: Centrifugo (WebSocket push).
// Заменил удалённый long polling (переход ноябрь 2026, см. ARCH.md §1/§4).
// Часть модульного клиента: см. AGENTS.md §5.
//
// Контракт: сервер (tools/ticker.php + публикация из join/action) шлёт в
// ПЕРСОНАЛЬНЫЙ канал battle:<playerId> тот же flat-снапшот, что раньше
// возвращал poll.php — render(data)/syncClock(server_now) не менялись.
// Клиент по-прежнему выполняет ровно три роли: приём снапшотов (вместо
// поллинга), рендер, ввод; вся логика — на сервере.

/**
 * Запуск realtime-подписки после успешного join+init.
 * Параметры канала (url/token) выдаёт php/join.php в поле ws.
 */
function startRealtime() {
    if (state.realtimeActive) return;
    if (typeof Centrifuge === 'undefined') {
        console.log('Centrifuge (js/vendor/centrifuge.min.js) не загружен');
        setupStatus('Ошибка: модуль Centrifugo не загружен', '#ff4444');
        return;
    }
    if (!state.ws || !state.ws.token) {
        console.log('Нет ws-параметров: join.php не отдал поле ws');
        setupStatus('Ошибка: сервер не выдал Centrifugo-токен (переподключитесь)', '#ff4444');
        return;
    }

    const url = state.ws.url || CONFIG.CENTRIFUGO_WS_URL;
    const client = new Centrifuge(url, { token: state.ws.token });
    state.wsClient = client;

    client.on('connecting', () => console.log('WS connecting...'));
    client.on('connected', () => console.log('WS connected, user=' + state.playerId));
    client.on('disconnected', (ctx) => {
        state.realtimeActive = false;
        console.log('WS disconnected:', ctx && ctx.reason);
    });
    client.on('error', (err) => console.log('WS error:', err));

    // Истёк срок токена — центрифуга просит новый; берём его идемпотентным
    // повторным join.php (тот же uid → rejoin:true, персонаж не дублируется).
    client.on('token', async () => {
        const token = await refreshRealtimeToken();
        if (!token) throw new Error('не удалось обновить токен');
        return token;
    });

    const sub = client.newSubscription(state.ws.channel, {
        // recovery включён на канале сервером не явно — при переподключении
        // первый же heartbeat тикера (≤1 с) донесёт актуальный снапшот.
        joinLeave: false,
    });
    state.wsSub = sub;

    sub.on('subscribed', () => {
        state.realtimeActive = true;
        console.log('Subscribed: ' + state.ws.channel);
    });
    sub.on('unsubscribed', (ctx) => {
        state.realtimeActive = false;
        console.log('Unsubscribed:', ctx && ctx.code, ctx && ctx.reason);
    });
    sub.on('publication', (ctx) => handleRealtimeSnapshot(ctx.data));
    sub.on('error', (err) => console.log('Sub error:', err));

    client.connect();
    sub.subscribe();

    // RTT по WS (аналог замера performance.now вокруг poll-запроса): нужен,
    // чтобы syncClock корректно центрировал server_now при push-доставке.
    state.wsRttMs = 0;
    setInterval(() => {
        if (state.wsClient && state.realtimeActive) {
            const t0 = performance.now();
            state.wsClient.ping().then(() => {
                state.wsRttMs = performance.now() - t0;
            }).catch(() => {});
        }
    }, 2000);
}

/**
 * Обработка входящего снапшота. Контракт flat-данных совпадает с прежним
 * ответом poll.php (version, server_now, players, ...).
 *
 * ★ Версия не изменилась (heartbeat тикера) — полный render() НЕ делаем,
 * только сверка часов и таймера хода: перерисовка неизменного мира на каждом
 * heartbeat создавала бы лишние churn-кадры анимациям.
 */
function handleRealtimeSnapshot(data) {
    if (!data || typeof data.version !== 'number') return;

    syncClock(data.server_now, state.wsRttMs || 0);

    if (data.version === state.version && state.data) {
        renderTimer(data);
        return;
    }

    state.version = data.version;
    state.data = data;
    render(data);
}

/**
 * Обновление истёкшего/продлённого токена: повторный POST join.php с той же
 * карточкой (сервер отвечает rejoin:true + новый ws-блок; персонаж не создаётся).
 * @return {Promise<string|null>} новый токен или null
 */
async function refreshRealtimeToken() {
    if (!state.lastCard) return null;
    try {
        const resp = await fetch(CONFIG.PHP_URL + 'join.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(state.lastCard),
        });
        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || !data.success || !data.ws) return null;
        state.ws = data.ws;
        return data.ws.token;
    } catch (err) {
        console.log('Token refresh error:', err);
        return null;
    }
}
