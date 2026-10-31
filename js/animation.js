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
    entity.classList.remove('status-idle', 'status-moving', 'status-attack', 'status-ability', 'status-special', 'status-death', 'status-decay', 'status-stunned');
    entity.classList.add('status-' + player.STATUS.toLowerCase());
    // ★ Пока бойца ведёт локальная rAF-интерполяция, держим на спрайте класс
    // status-moving: он отключает CSS idle-bounce (animation:none), который
    // иначе перезаписывал бы inline transform движения — спрайт «прыгал» на
    // клетку назад посреди плавного маршрута.
    // ★ Дополнительно: пока живой rAF-цикл есть в state.moveRaf (кадр поставлен,
    // но ещё не выполнен — окно между poll-ответом и первым кадром), анимация
    // тоже считается идущей: без этой проверки один и тот же poll-ответ мог
    // снять status-moving (сервер уже ответил IDLE), idle-bounce на пару кадров
    // перекрывал transform, а следующий кадр animateMove возвращал класс —
    // видимый подскок в конце пути именно при «изредка плавном, изредка рваном»
    // совпадении таймингов.
    const animPid = entity.dataset.playerId;
    const hasLiveAnim = Array.from(state.animations.keys()).some(k => k.startsWith(animPid + '_'))
        || Array.from(state.moveRaf.keys()).some(k => k.startsWith(animPid + '_'));
    if (hasLiveAnim) {
        entity.classList.add('status-moving');
    }

    // ★ Гасить idle-bounce надо НЕ только во время rAF-интерполяции, но и на
    // время финальной CSS-скользки settleSpriteAtEnd (0.12 с после останова
    // цикла). Иначе в этом окне спрайт уже без status-moving (сервер ответил
    // IDLE) и без inline-transform, а keyframes idle-bounce перезапускаются
    // С НУЛЯ (анимация снималась и вешалась обратно): спрайт за 0.12 с до
    // скользки успевает отпрыгнуть translateY до −2px и назад — ровно тот
    // «то есть, то нет» микроподскок перед самой последней клеткой, который
    // невозможно поймать синхронизацией часов (он чисто CSS-ный).
    // Решение: пока у спрайта жив dataset.settling (ставится/снимается в
    // animation.js), статусная keyframe-анимация обязана быть выключена.
    // ★ Тот же класс держим и на «стоянке в ожидании подтверждения сервера»:
    // animateMove завершил интерполяцию точно по серверным часам, но следующий
    // poll ещё не принёс STATUS=IDLE. Если в этом окне снять status-moving
    // (например, долетел промежуточный ответ со СТАРЫМ MOVING — render тогда
    // вообще не трогает ветку «не двигается»), idle-bounce стартует с нуля и
    // спрайт подпрыгивает на −2px прямо перед фиксацией. Снимется автоматически
    // следующим render'ом, когда сервер подтвердит не-MOVING (см. шапку функции:
    // там pending-анимаций и settling-скользок уже нет).
    if (entity.dataset.settling || entity.dataset.awaitServerIdle === '1') {
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
 * ★ Остановить rAF-цикл интерполяции, если он ещё не выполнился.
 * Обязателен при любом снятии анимации (естественный финал, досрочный стоп
 * из render, смена комнаты): иначе «висящий» в очереди кадрanimateMove
 * продолжит писать transform в спрайт уже после его фиксации на клетке.
 */
function stopMoveLoop(animKey) {
    const rafId = state.moveRaf.get(animKey);
    if (rafId !== undefined) {
        cancelAnimationFrame(rafId);
        state.moveRaf.delete(animKey);
    }
}

/**
 * ★ Снять ВСЕ локальные анимации движения (пакетно, одним проходом).
 * Используется render при смене комнаты и мгновенных переходах. Раньше эти
 * места делали только state.animations.delete(...) без отмены rAF — кадры
 * «осиротевших» циклов продолжает работать до конца серверного маршрута и
 * дёргают спрайт поверх актуальной позиции.
 */
function stopAllMoveAnimations(pidPrefix) {
    state.animations.forEach((anim, key) => {
        if (!pidPrefix || key.startsWith(pidPrefix)) {
            state.animations.delete(key);
            stopMoveLoop(key);
        }
    });
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
 *
 * ★ Защита от наложения циклов: перезапуск той же траектории (catchUp или
 * повторный poll) сначала ОТМЕНЯЕТ предыдущий rAF-кадр (stopMoveLoop) — иначе
 * два независимых цикла писали бы в один спрайт, что само по себе выглядит
 * как микродёрганье даже при идеальных часах.
 */
function startMoveAnimation(playerId, startPos, endPos, serverStartTime, serverEndTime, animKey) {
    // Длительность в миллисекундах (серверная шкала — authoritative)
    const duration = (serverEndTime - serverStartTime) * 1000;

    // ★ Класс status-moving сразу при старте (см. updatePlayerEntity): гасит
    // idle-bounce/keyframes, перекрывающие translate интерполяции.
    // Перезапуск интерполяции (catchUp-доленивание, новый poll по той же
    // траектории) обязан сбросить и финальную скользку предыдущего цикла:
    // висевший transition 0.12s «подтормаживал» первые кадры rAF-анимации
    // (каждый кадр догонял сам себя), а живой settling-маркер держал
    // status-moving уже после старта нового движения — безвредно по классу,
    // но transition по transform здесь заметен глазами.
    const animEntity = document.querySelector(`[data-player-id="${playerId}"]`);
    if (animEntity) {
        animEntity.classList.add('status-moving');
        clearSettleState(animEntity);
    }

    // Отменяем возможный предыдущий цикл по этому же ключу (см. шапку функции)
    stopMoveLoop(animKey);

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
    // Кадр выполнен — id больше не актуален (следующий запишем при продлении)
    state.moveRaf.delete(animKey);

    const anim = state.animations.get(animKey);
    if (!anim) return; // цикл снят снаружи (render/catchUp) — молча выходим

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

    // ★ Подстраховка от рассинхрона классов статуса: render мог за кадр снять
    // status-moving (сервер ответил IDLE раньше, чем завершилась наша
    // интерполяция) — тогда CSS idle-bounce со своим animation-fill перезаписал
    // бы inline transform и спрайт «прыгнул» на пару пикселей вверх посреди
    // пути. Возвращаем гасящий класс, пока цикл жив.
    if (!entity.classList.contains('status-moving')) {
        entity.classList.add('status-moving');
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
        // ★ id кадра сохраняем в state.moveRaf — stopMoveLoop может отменить
        // цикл, ещё не успевший выполниться ни разу (гонка catchUp/restart).
        state.moveRaf.set(animKey, requestAnimationFrame(() => animateMove(animKey)));
    } else {
        state.animations.delete(animKey);

        // ★ Не трогаем status-moving: его снимет следующий render по серверному
        // STATUS. Принудительное снятие здесь возвращало idle-bounce до
        // подтверждения сервера, а любое последующее доленивание пути restarting
        // анимацией выглядело бы как подскок.

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
                // Сервер подтвердил END: мягко прибиваем спрайт к центру клетки
                // (settleSpriteAtEnd сам решит — мгновенно снять микросмещение
                // или доехать остаток за короткую скользку).
                settleSpriteAtEnd(entity, finalPos);
            } else {
                // Финал ещё не подтверждён — оставляем чистый transform-0:
                // отражение обеспечит класс turn-left (CSS), idle-bounce погашен
                // status-moving; спрайт ровно стоит на END до следующего poll.
                clearSettleState(entity);
                entity.style.transition = 'none';
                entity.style.transform = '';
                // ★ Маркер «стоим на END, ждём подтверждения сервера». Без него
                // следующий render (STATUS всё ещё MOVING — IDLE-ответ в пути)
                // вызывает updatePlayerEntity, тот снимает status-moving и
                // навешивает status-idle: idle-bounce стартует С НУЛЯ прямо на
                // финишной клетке — спрайт подпрыгивает translateY(-2px) и
                // назад. Класс держится, пока не придёт ответ с не-MOVING
                // (снимется в remove-строке updatePlayerEntity / clearSettleState).
                entity.dataset.awaitServerIdle = '1';
            }
        } else {
            clearSettleState(entity);
            entity.style.transition = 'none';
            entity.style.transform = '';
        }
    }
}

/**
 * ★ Финальная CSS-скользка transform (0.12 с linear) — общий механизм для
 * settleSpriteAtEnd и FLIP-старта в render.js.
 *
 * Лечит последний видимый дефект «то есть, то нет» на финише пути: раньше
 * здесь стоял setTimeout(…, 160), который глушил transition «на всякий случай».
 * Если за эти 160 мс приходил следующий poll-ответ, он мог ДОСРОЧНО поставить
 * entity.style.transition='none' (ветки мгновенной фиксации в render/animation)
 * — недоигранная скользка обрывалась на середине и остаток (~несколько px)
 * доматывался мгновенно: микро-рывок ровно перед последней клеткой. Теперь
 * сброс transition выполняется ИСКЛЮЧИТЕЛЬНО по событию transitionend (плюс
 * страховка по времени, если событие не пришло — скрытая вкладка и т.п.).
 *
 * dataset.settling — маркер для updatePlayerEntity: пока скользка жива, на
 * спрайте держится status-moving (гасит idle-bounce/keyframes, которые иначе
 * перезапускаются с нуля в этом окне и дают подскок −2px).
 */
function glideTransform(entity, finalTransform) {
    delete entity.dataset.settling; // новый цикл — новый таймер
    entity.style.transition = 'transform 0.12s linear';
    entity.style.transform = finalTransform;
    entity.dataset.settling = '1';
    const finish = () => {
        if (entity.dataset.settling !== '1') return; // цикл уже снят/перезапущен
        delete entity.dataset.settling;
        entity.style.transition = 'none';
    };
    // transitionend всплывает от дочерних элементов (HP-бары имеют свои
    // width-переходы) — фильтруем по propertyNamе, чтобы не снять скользку
    // досрочно чужим событием.
    entity.addEventListener('transitionend', function onEnd(e) {
        if (e.target === entity && e.propertyName === 'transform') {
            entity.removeEventListener('transitionend', onEnd);
            finish();
        }
    });
    // Страховка: transitionend не приходит, если элемент скрыт/удалён или
    // браузер прервал кадр. 300 мс > 0.12 с с запасом; досрочного обрыва больше
    // нет — все «мгновенные» ветки сами снимают settling до записи transition.
    setTimeout(finish, 300);
}

/**
 * ★ Снять финальную скользку/маркер ожидания у спрайта (вызывается из любой
 * «мгновенной» ветки позиционирования ДО записи transition='none'/transform='').
 * Без этого висевший transition доедал мгновенную установку позиции (спрайт
 * «проезжал» лишние клетки по инерции недоигранной скользки), а живой
 * dataset.settling бессменно держал status-moving поверх актуального статуса.
 */
function clearSettleState(entity) {
    delete entity.dataset.settling;
    delete entity.dataset.awaitServerIdle;
}

/**
 * ★ Зафиксировать спрайт на клетке endPos (общий финал catchUpMoveEnd и
 * «мягкого» завершения animateMove).
 *
 * Лечит рывок в КОНЦЕ пути: раньше установка на серверную клетку была строго
 * мгновенной (transform='' + appendChild). Если по интерполяции боец ещё не
 * доехал до END (часы slightly отстают, poll-ответ с финалом опоздал), спрайт
 * «допрыгивал» остаток — при ходе на 1 клетку почти телепорт. Теперь остаток
 * пути (если он больше трети клетки) домноляется короткой CSS-скользкой той же
 * серверной длительности: движение продолжается плавно ровно до END, а не
 * обрывается и не доскакивает.
 */
function settleSpriteAtEnd(entity, endPos) {
    const cell = document.getElementById(`cell-${endPos.x}-${endPos.y}`);
    if (!cell) return;

    const grid = document.getElementById('grid-container');
    // Спрайт уже прибит к нужной клетке — достаточно снять смещение.
    // Если offset мал (< трети клетки) — убираем его мгновенно: скользка на
    // 1-2 пикселя сама выглядела бы как микродрожание. Если велик (часы
    // отстали, спрайт не доехал) — короткая CSS-скользка к центру клетки.
    if (entity.parentElement === cell) {
        clearSettleState(entity);
        const m = /translate\((-?[\d.]+)px,\s*(-?[\d.]+)px\)/.exec(entity.style.transform || '');
        const offX = m ? Math.abs(parseFloat(m[1])) : 0;
        const offY = m ? Math.abs(parseFloat(m[2])) : 0;
        if (offX > CONFIG.CELL_SIZE / 3 || offY > CONFIG.CELL_SIZE / 3) {
            glideTransform(entity, '');
        } else {
            entity.style.transition = 'none';
            entity.style.transform = '';
        }
        delete entity.dataset.animCx;
        delete entity.dataset.animCy;
        return;
    }

    // Клетка в текущей сетке — перестёлкиваемся с сохранением экранных
    // координат: transform компенсирует разницу старой и новой клеток,
    // затем за CSS-скользку обнуляем её → непрерывное продолжение пути.
    if (cell.parentElement === grid && entity.offsetParent) {
        clearSettleState(entity);
        const oldRect = entity.getBoundingClientRect();
        entity.style.transition = 'none';
        entity.style.transform = '';
        cell.appendChild(entity);
        const newRect = entity.getBoundingClientRect();
        const dx = oldRect.left - newRect.left;
        const dy = oldRect.top - newRect.top;
        if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
            const flip = entity.classList.contains('turn-left') ? ' scaleX(-1)' : '';
            entity.style.transform = `translate(${dx}px, ${dy}px)` + flip;
            void entity.offsetWidth; // форсируем reflow — стартовая точка кадра зафиксирована
            glideTransform(entity, flip.trim() ? flip.trim() : '');
        }
    } else {
        // Страховка (клетка вне видимой сетки): прежнее мгновенное поведение
        clearSettleState(entity);
        entity.style.transition = 'none';
        entity.style.transform = '';
        cell.appendChild(entity);
    }
    delete entity.dataset.animCx; // кэш клетки гистерезиса устарел
    delete entity.dataset.animCy;
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
        // Финал подтверждён серверными часами — точная установка на END
        // (через мягкую скользку, если спрайт ещё не доехал — см. settleSpriteAtEnd)
        settleSpriteAtEnd(entity, endPos);
        applyTurnClass(entity, player.TURN);
        return;
    }

    // Остаток маршрута ещё не пройден по серверным часам — долениваем плавно
    const startPos = parseXY(player.START_POSITION_XY || player.CUR_POSITION_XY);
    startMoveAnimation(pid, startPos, endPos, player.MOVE_START_TIME, player.MOVE_END_TIME,
                       `${pid}_${Math.floor(player.MOVE_START_TIME)}_catchup`);
}
