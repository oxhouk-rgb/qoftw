// Регрессия на клиентский дедлок «пропажи пакетов» (js/net.js).
//
// Старый код: рекурсивный вызов sendAction(...) из тела try вешался на await
// prev — того же промиса, который предок разрешал только в finally ПОСЛЕ
// завершения рекурсии → взаимная блокировка. Первая же сетевая ошибка/5xx
// навсегда останавливала очередь: все последующие действия клиента молча
// зависали («уведомление есть — действия нет»).
//
// Тест эмулирует сеть через подставной fetch: первый запрос падает (retry),
// второй succeeds; затем проверяется, что ОЧЕРЕДЬ ЖИВА — следующее действие
// дошло до сервера за конечное время.
//
// Запуск: node tests/test_sendaction_deadlock.mjs

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');

let fetchCalls = [];       // список отправленных тел
let failFirstN = 0;        // сколько первых fetch должны упасть (эмуляция ретрая)
let notifications = [];

// Минимальные стабы окружения, которые использует net.js
globalThis.state = { playerId: 'p1', version: 0, data: null, polling: false };
globalThis.CONFIG = { PHP_URL: 'http://localhost/' };
globalThis.showNotification = (msg, kind) => notifications.push([kind, msg]);
globalThis.buildGrid = () => {};
globalThis.startPolling = () => {};
globalThis.render = () => {};
globalThis.setTimeoutOrig = setTimeout;

globalThis.fetch = async (url, opts) => {
    const body = JSON.parse(opts.body);
    fetchCalls.push(body);
    if (fetchCalls.length <= failFirstN) {
        throw new TypeError('Failed to fetch'); // сетевая ошибка → retry
    }
    return {
        ok: true,
        status: 200,
        text: async () => JSON.stringify({ success: true, message: 'ok', version: 42 }),
    };
};

// Загружаем net.js в общий scope (нет ES-модулей — plain script).
const src = readFileSync(path.join(root, 'js/net.js'), 'utf8');
(0, eval)(src + '\nglobalThis.sendAction = sendAction; globalThis.actionQueueProbe = () => actionQueueTail;');

const results = [];
const check = (name, cond) => { results.push([name, !!cond]); };

// Ускорим «паузы» ретрая: переопределим setTimeout на immediate для нетаймеров?
// Нет — просто проживём 1 сек реального времени (тест быстрый).

failFirstN = 1; // первый запрос падает → retry со вторым
const withTimeout = (p, ms, label) => Promise.race([
    p,
    new Promise((_, rej) => setTimeoutOrig(() => rej(new Error('TIMEOUT ' + label)), ms)),
]);

let firstOk = false, firstErr = null;
try {
    const r = await withTimeout(sendAction('move', { x: 2, y: 3 }), 8000, 'sendAction#1');
    firstOk = r && r.success === true;
} catch (e) { firstErr = e; }

check('действие с ретраем завершилось успешно (без дедлока)', firstOk);
check('ретрай отправлен с тем же action_id', fetchCalls.length >= 2 &&
    fetchCalls[0].action_id === fetchCalls[fetchCalls.length - 1].action_id);

// КЛЮЧЕВАЯ проверка: хвост очереди РАЗРЕШЁН — следующее действие не висит.
let tailResolved = false;
try {
    await withTimeout(globalThis.actionQueueProbe(), 1000, 'queue tail');
    tailResolved = true;
} catch (e) {}
check('хвост очереди разрешён после ретрая', tailResolved);

fetchCalls = [];
failFirstN = 0;
let secondOk = false;
try {
    const r2 = await withTimeout(sendAction('attack', { x: 4, y: 5 }), 5000, 'sendAction#2');
    secondOk = r2 && r2.success === true;
} catch (e) {}
check('следующее действие дошло до сервера (очередь жива)', secondOk && fetchCalls.length === 1);

// Параллельные вызовы не теряются и сериализуются
fetchCalls = [];
const [a, b] = await Promise.all([sendAction('move', { x: 1, y: 1 }), sendAction('move', { x: 2, y: 2 })]);
check('два параллельных sendAction оба завершены', a.success && b.success);
check('оба запроса доставлены (по одному action_id каждый)',
    fetchCalls.length === 2 && fetchCalls[0].action_id !== fetchCalls[1].action_id);

let failed = 0;
for (const [name, ok] of results) {
    console.log((ok ? 'OK   ' : 'FAIL ') + name);
    if (!ok) failed++;
}
process.exit(failed ? 1 : 0);
