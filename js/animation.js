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
    // ★ Пока бойца ведёт локальная rAF-интерполяция, держим на спрайте класс
    // status-moving: он отключает CSS idle-bounce (animation:none), который
    // иначе перезаписывал бы inline transform движения — спрайт «прыгал» на
    // клетку назад посреди плавного маршрута.
    if (Array.from(state.animations.keys()).some(k => k.startsWith(entity.dataset.playerId + '_'))) {
        entity.classList.add('status-moving');
    }
    
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
 * ★ Запустить анимацию перемещения для игрока (интерполяция по СЕРВЕРНЫМ часам).
 *
 * Серверные MOVE_START_TIME/MOVE_END_TIME приходят в poll-ответе с задержкой
 * доставки d (≈ RTT/2 + очередь long-poll): к моменту получения ответа путь уже
 * «протёк» на d. Старая реализация считала прогресс от localStartTime = момента
 * получения, из-за чего:
 *   1) старт спрайта запаздывал ровно на d («подлагивание в начале пути»);
 *   2) к концу маршрута сервер уже переводил бойца в IDLE и render сбрасывал
 *      локальную анимацию — остаток пути «допрыгивался» рывком; при ходе на
 *      1 клетку (0.33 с пути) это выглядело как телепорт.
 * Теперь хранятся серверные времена, а кадр вычисляет прогресс от serverTimeSec()
 * (см. state.js/serverTimeSec), т.е. спрайт едет синхронно с серверной моделью.
 * Длительность берём строго из серверных меток (не из клиентской оценки), чтобы
 * финиш совпадал с серверным финалом движения.
 */
function startMoveAnimation(playerId, startPos, endPos, serverStartTime, serverEndTime, animKey) {
    // Длительность в миллисекундах (серверная шкала — authoritative)
    const duration = (serverEndTime - serverStartTime) * 1000;

    // Класс status-moving сразу при старте (см. updatePlayerEntity): гасит
    // idle-bounce/keyframes, перекрывающие translate интерполяции.
    const animEntity = document.querySelector(`[data-player-id="${playerId}"]`);
    if (animEntity) animEntity.classList.add('status-moving');

    // Сохраняем анимацию с серверными временами
    state.animations.set(animKey, {
        playerId,
        startPos,
        endPos,
        serverStartTime, // Серверное время начала (в секундах)
        serverEndTime,   // Серверное время окончания (в секундах)
        duration,
        lastServerNow: state.lastServerNow || 0 // серверные часы последнего poll-ответа
    });

    // Запускаем анимацию
    requestAnimationFrame(() => animateMove(animKey));
}

/**
 * Анимация перемещения (вызывается каждый кадр).
 * Позиция = linear(start, end, progress), где progress считается по СЕРВЕРНЫМ
 * часам: serverTimeSec(performance.now()) ↔ шкала microtime(true) сервера.
 */
function animateMove(animKey) {
    const anim = state.animations.get(animKey);
    if (!anim) return;

    // Вычисляем серверный прогресс этого кадра (компенсирует задержку доставки
    // poll-ответа: движение начинается сразу с той точки маршрута, где боец
    // реально находится по серверным часам, без стартовой паузы и финального рывка)
    const nowServer = serverTimeSec();
    let progress = anim.duration > 0
        ? (nowServer - anim.serverStartTime) * 1000 / anim.duration
        : 1;
    progress = Math.max(0, Math.min(1, progress));

    // Находим элемент игрока
    const entity = document.querySelector(`[data-player-id="${anim.playerId}"]`);
    if (!entity) {
        state.animations.delete(animKey);
        return;
    }

    // Вычисляем текущую позицию (линейная интерполяция)
    const currentX = anim.startPos.x + (anim.endPos.x - anim.startPos.x) * progress;
    const currentY = anim.startPos.y + (anim.endPos.y - anim.startPos.y) * progress;

    // ★ Квантование клетки — с гистерезисом (лечит «микродёрганье» на границах):
    // без него спрайт перецепляется на соседнюю клетку ровно в момент, когда
    // интерполяция пересекает середину пути (offset перескакивает +20px → −20px).
    // Любое малое рассинхронизирование часов/дрожание кадра в этот миг даёт
    // видимый двойной скачок туда-сюда. Держим прежнюю клетку, пока реальное
    // смещение от её центра не превысит половины клетки + запас HYST px; при
    // возврате обратно запас симметрично уменьшается (правило фиксации), так что
    // переход между клетками происходит один раз, а offset меняется непрерывно.
    const prevCellX = Number(entity.dataset.animCx);
    const prevCellY = Number(entity.dataset.animCy);
    const HYST = 6; // px запаса по обе стороны середины граничной клетки
    let cellX, cellY;
    if (isFinite(prevCellX) && isFinite(prevCellY)) {
        const dxPx = (currentX - prevCellX) * CONFIG.CELL_SIZE;
        const dyPx = (currentY - prevCellY) * CONFIG.CELL_SIZE;
        const half = CONFIG.CELL_SIZE / 2;
        const goX = Math.abs(dxPx) > half + (dxPx > 0 ? HYST : -HYST);
        const goY = Math.abs(dyPx) > half + (dyPx > 0 ? HYST : -HYST);
        cellX = goX ? Math.round(currentX) : prevCellX;
        cellY = goY ? Math.round(currentY) : prevCellY;
    } else {
        cellX = Math.round(currentX);
        cellY = Math.round(currentY);
    }
    const offsetX = (currentX - cellX) * CONFIG.CELL_SIZE;
    const offsetY = (currentY - cellY) * CONFIG.CELL_SIZE;

    // Перемещаем элемент в нужную клетку (без transition чтобы не было рывков)
    const targetCell = document.getElementById(`cell-${cellX}-${cellY}`);
    if (targetCell && entity.parentElement !== targetCell) {
        entity.style.transition = 'none';
        targetCell.appendChild(entity);
    }
    entity.dataset.animCx = cellX;
    entity.dataset.animCy = cellY;

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

        // Синхронизируем позицию и направление с сервером (если есть данные).
        // ★ Если сервер ещё не подтвердил финал (CUR != END — его тик/ответ в
        // пути), НЕ тащим спрайт на прежнюю серверную клетку: он уже стоит на
        // END по завершённой интерполяции. Обратное вызвало бы «откат назад»
        // на одну клетку перед финальной фиксацией (дёрганье в конце пути).
        if (state.data && state.data.players && state.data.players[anim.playerId]) {
            const player = state.data.players[anim.playerId];
            applyTurnClass(entity, player.TURN);
            const finalPos = parseXY(player.CUR_POSITION_XY);
            if (finalPos.x === anim.endPos.x && finalPos.y === anim.endPos.y) {
                const finalCell = document.getElementById(`cell-${finalPos.x}-${finalPos.y}`);
                if (finalCell && entity.parentElement !== finalCell) {
                    entity.style.transition = 'none';
                    finalCell.appendChild(entity);
                }
            }
        }
    }
}

/**
 * ★ Мягкая дозапись позиции бойца, чья анимация была досрочно снята render-циклом
 * (сервер уже ответил STATUS != MOVING, но poll-ответ шёл ~RTT и спрайт всё ещё
 * «в пути»). Без этого шага следующий render ставил div на новую серверную клетку
 * мгновенно — визуальный телепорт в конце каждого хода.
 *
 * Логика: если серверные часы (на момент сборки snapshot'а — lastServerNow) уже
 * прошли MOVE_END_TIME, боец по серверной модели гарантированно на конечной
 * клетке: ставим его туда сразу (маршрут пройден, догонять нечего). Иначе —
 * долениваем остаток пути обычной серверной интерполяцией до финала.
 */
function catchUpMoveEnd(pid, anim) {
    const player = state.data && state.data.players && state.data.players[pid];
    if (!player) return;
    const entity = document.querySelector(`[data-player-id="${pid}"]`);
    if (!entity) return;

    const endPos = parseXY(player.END_POSITION_XY || player.CUR_POSITION_XY);
    const snapServerNow = state.lastServerNow || anim.lastServerNow || 0;
    const finishedByServer = player.MOVE_END_TIME && snapServerNow >= player.MOVE_END_TIME;

    if (finishedByServer || !player.MOVE_START_TIME || !player.MOVE_END_TIME) {
        // Финал подтверждён серверными часами — мгновенная точная установка
        entity.style.transition = 'none';
        entity.style.transform = '';
        const cell = document.getElementById(`cell-${endPos.x}-${endPos.y}`);
        if (cell && entity.parentElement !== cell) cell.appendChild(entity);
        applyTurnClass(entity, player.TURN);
        return;
    }

    // Остаток маршрута ещё не пройден по серверным часам — долениваем плавно
    const startPos = parseXY(player.START_POSITION_XY || player.CUR_POSITION_XY);
    startMoveAnimation(pid, startPos, endPos, player.MOVE_START_TIME, player.MOVE_END_TIME,
                       `${pid}_${Math.floor(player.MOVE_START_TIME)}_catchup`);
}
