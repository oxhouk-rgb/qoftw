// render.js — отрисовка полного snapshot: сетка, сущности, UI, победа.
// Часть модульного клиента: см. AGENTS.md §5.

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
    // ★ Область поиска DOM-сущностей — ТОЛЬКО текущая сетка. В DOM могут жить
    // «сиротские» div'ы удалённых при rebuildGrid() старых комнат: глобальный
    // document.querySelector находил такой div вне видимой сетки, проверка
    // parentElement не срабатывала и объект/NPC оставался невидимкой, а в
    // новой комнате создавался второй — визуальный ДУБЛЬ турелей/бочек/мин
    // во всех комнатах сразу.
    const cells = document.getElementById('grid-container');

    // Очищаем только снаряды (они временные)
    document.querySelectorAll('.projectile').forEach(e => e.remove());
    
    // Сбрасываем подсветку
    document.querySelectorAll('.cell').forEach(cell => {
        // Снимаем и прошлые метки карты (wall/stair): при смене комнаты стены
        // и двери полностью перерисовываются по новому grid ниже.
        cell.classList.remove('highlight-move', 'highlight-attack', 'highlight-ability', 'wall', 'stair');
    });
    
    const room = data.room;
    let roomGrid = room ? room.grid : null;
    let roomExits = room ? room.exits : null;
    let roomExitRooms = room ? room.exit_rooms : null;

    // ★ Мультикомнатный мир: при смене комнаты (переход в дверь/респаун в
    // SPAWN_ROOM) сбрасываем локальные анимации движения — иначе «призрачный»
    // rAF interpolation тащит спрайт от клетки старой комнаты к координатам
    // новой поверх серверной позиции.
    // ★ stopAllMoveAnimations, а не просто animations.clear(): у каждой снятой
    // анимации ОБЯЗАТЕЛЬНО отменяется её rAF-кадр, иначе «осиротевший» цикл
    // дописывает transform в спрайт после перестройки сетки (дёрганье сразу
    // после перехода в дверь).
    const roomChanged = state.lastRoomId !== null && data.room_id && data.room_id !== state.lastRoomId;
    if (roomChanged) {
        stopAllMoveAnimations(null);
    }
    if (data.room_id) state.lastRoomId = data.room_id;

    // ★ При СМЕНЕ комнаты перестраиваем сетку заново: клетки получают новые
    // обработчики кликов этой комнаты, а все div'ы сущностей старой комнаты
    // гарантированно покидают DOM (иначе purge ниже мог бы «сохранить» спрайт
    // своего игрока из прежней комнаты поверх чужой карты).
    if (roomChanged) {
        rebuildGrid();
        // После перестроения клетки НОВЫЕ — стены/двери этого poll-ответа
        // отрисуются на них ниже (цикл отрисовки стен идёт после rebuildGrid,
        // новые клетки чисты по определению).
    }

    // ★ Комната, которую РИСУЕМ сейчас (grid карты = её координатная сетка).
    const viewRoomId = data.room_id || null;
    state.currentRoomId = viewRoomId; // для inCurrentRoom(): проверки путей/подсветки
    // обязаны видеть только сущности этой комнаты — координаты разных комнат
    // пересекаются, иначе клиент подсвечивает/разрешает действия «сквозь стены».
    // Игроки: poll отдаёт список целиком (нужен для инфы/подсветки), но рисовать
    // и анимировать можно только бойцов ТЕКУЩЕЙ комнаты — их координаты живут в
    // сетке своей карты. Без фильтра спрайты чужих комнат «дублировались» поверх
    // стен видимой комнаты (позиции в разных комнатах координатно совпадают).
    // Строгое сравнение для ВСЕХ типов сущностей: объект/NPC/ловушка без
    // ROOM_ID — legacy-данные (поле бойцов снимка), они НЕ должны
    // рисоваться поверх любой мультикомнатной карты — иначе турель чужого
    // спавна «дублируется» во всех комнатах сразу (позиции совпадают).
    const inViewRoom = (e) => !viewRoomId || (e && e.ROOM_ID === viewRoomId);
    
    // Отрисовка стен
    if (roomGrid) {
        for (let y = 0; y < CONFIG.GRID_HEIGHT; y++) {
            for (let x = 0; x < CONFIG.GRID_WIDTH; x++) {
                const cell = document.getElementById('cell-' + x + '-' + y);
                if (roomGrid[y] && roomGrid[y][x] === 0) {
                    cell.classList.add('wall');
                } else {
                    cell.classList.remove('wall');
                }
            }
        }
        
        // Лестницы/двери между комнатами. Подписываем дверь названием комнаты
        // назначения (exits["x,y"] = КУДА; exit_rooms["x,y"] = ОТКУДА — обратная
        // дверь), чтобы игрок видел, куда ведёт переход.
        if (roomExits) {
            Object.keys(roomExits).forEach(key => {
                const [x, y] = key.split(',').map(Number);
                const cell = document.getElementById('cell-' + x + '-' + y);
                if (cell) {
                    cell.classList.add('stair');
                    cell.title = '-> ' + roomExits[key];
                }
            });
        }
        if (roomExitRooms) {
            Object.keys(roomExitRooms).forEach(key => {
                const [x, y] = key.split(',').map(Number);
                const cell = document.getElementById('cell-' + x + '-' + y);
                if (cell) {
                    cell.classList.add('stair');
                    cell.title = 'из ' + roomExitRooms[key];
                }
            });
        }
    }
    
    // ★ Набор div'ов, официально отрисованных этим poll-ответом (для
    // purgeOutOfViewRoomEntities — всё остальное в сетке лишнее).
    const keptEntities = new Set();

    // Отрисовка/обновление игроков
    if (data.players) {
        Object.keys(data.players).forEach(pid => {
            const player = data.players[pid];

            // ★ Комнатная изоляция отрисовки: боец чужой комнаты не должен ни
            // появляться на этой карте, ни оставаться на ней «дубликатом» —
            // при выходе из поля его div удаляется (он перерисуется, когда
            // игрок вернётся в комнату этого бойца).
            // Строгое сравнение: боец БЕЗ ROOM_ID здесь НЕ рисуется (сервер
            // обязан проставить комнату в poll-ответе; «undefined == null» в
            // JS истинно, поэтому мягкая проверка inViewRoom() для игроков
            // ненадёжна — без strict боец без поля рисовался во всех комнатах).
            if (viewRoomId && player.ROOM_ID !== viewRoomId) {
                // Поиск строго внутри cells: div бойца мог «осиротеть» в старой
                // сетке после смены комнаты и остаться невидимым дубликатом.
                const foreign = findEntity('player', pid);
                if (foreign) foreign.remove();
                return;
            }

            // Удаляем элемент только когда игрок полностью убран из боя сервером (REMOVED).
            // Стадии DEATH/DECAY НЕ удаляем — div должен оставаться на поле всё время
            // проигрывания обеих анимаций (по 1 секунде на стадию), респавн/удаление
            // происходит на сервере после DECAY.
            if (player.STATUS === 'REMOVED') {
                const existing = findEntity('player', pid);
                if (existing) existing.remove();
                return;
            }
            
            // Ищем существующий элемент игрока (строго в текущей сетке)
            let entity = findEntity('player', pid);

            // Если сервер снял статус MOVING (например, игрок мгновенно
            // остановлен ловушкой на клетке мины), досрочно завершаем
            // локальную анимацию движения — иначе «призрачный» rAF будет
            // тащить спрайт к прежней конечной точке поверх серверной позиции.
            // ★ Но снимаем НЕ «впустую», а через catchUpMoveEnd: poll-ответ со
            // снятым MOVING ехал до клиента ~RTT и всё это время спрайт шёл по
            // старой траектории; простая установка на серверную клетку выглядела
            // бы как телепорт/рывок в конце пути. catchUp либо мгновенно ставит
            // бойца на END (если серверные часы уже прошли MOVE_END_TIME — путь
            // по модели пройден), либо доленивает остаток маршрута плавно.
            if (entity && player.STATUS !== 'MOVING') {
                state.animations.forEach((anim, key) => {
                    if (key.startsWith(`${pid}_`)) {
                        state.animations.delete(key);
                        stopMoveLoop(key); // ★ без отмены кадра цикл продолжит
                                           // писать transform после catchUp
                        catchUpMoveEnd(pid, anim);
                    }
                });
            }

            if (!entity) {
                // Создаём новый элемент
                entity = createPlayerEntity(player, pid);
                if (!entity) return;
                entity.dataset.playerId = pid;
                // ★ Метка комнаты для purgeOutOfViewRoomEntities(): при смене
                // комнаты бойцы чужих комнат должны быть удалены с поля.
                entity.dataset.roomId = player.ROOM_ID || '';
                
                // Определяем начальную позицию
                const pos = parseXY(player.CUR_POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                if (cell) {
                    cell.appendChild(entity);
                }
            } else {
                // Обновляем существующий элемент
                updatePlayerEntity(entity, player);
                entity.dataset.roomId = player.ROOM_ID || '';
            }
            
            // ★ Мгновенные переходы (дверь между комнатами, респаун): сервер
            // выдаёт «виртуальный финал движения» — STATUS='MOVING', но путь
            // нулевой (START == END или END == CUR, MOVE_END_TIME наступил).
            // Такой боец НЕ анимируется: локальные rAF-анимации старой
            // траектории сбрасываются, спрайт ставится ровно на серверную
            // позицию. Без этого шага спрайт «прилипал» к клетке выхода, а
            // следующий клик начинал маршрут от неё («рывок к exit»).
            const instantMove = player.STATUS === 'MOVING' &&
                (player.START_POSITION_XY === player.END_POSITION_XY ||
                 player.END_POSITION_XY === player.CUR_POSITION_XY);
            if (instantMove) {
                // Снимаем старые траектории ВМЕСТЕ с их rAF-кадрами: delete без
                // stopMoveLoop оставлял висящий кадр, который дёргал спрайт уже
                // после мгновенной установки на серверную клетку.
                stopAllMoveAnimations(`${pid}_`);
                const sPos = parseXY(player.CUR_POSITION_XY);
                const sCell = document.getElementById('cell-' + sPos.x + '-' + sPos.y);
                clearSettleState(entity); // мгновенная фиксация — недоигранная скользка недопустима
                entity.style.transition = 'none';
                entity.style.transform = '';
                if (sCell && entity.parentElement !== sCell) {
                    sCell.appendChild(entity);
                    // ★ Сброс кэша клетки гистерезиса (см. ниже в ветке «не
                    // двигается»: после мгновенного перехода dataset.animCx/animCy
                    // устаревает и следующий путь начался бы с рывка-допрыгивания)
                    delete entity.dataset.animCx;
                    delete entity.dataset.animCy;
                }
            }

            // ★ Дивиденд purge-фильтра: div'ы бойцов, официально подтверждённых
            // этим poll-ответом, попадают в whitelist. Без этого шага
            // purgeOutOfViewRoomEntities() не мог отличить «оригинал» своего
            // игрока от его же осиротевшего дубля и сносил единственный
            // спрайт — фигурка своего игрока исчезала совсем (зоны хода/атаки
            // при этом оставались, т.к. рисуются на клетках). Чужие бойцы
            // фильтруются по data-room-id и без whitelist.
            keptEntities.add(entity);

            // Обрабатываем перемещение
            if (!instantMove && player.STATUS === 'MOVING' && player.MOVE_START_TIME && player.MOVE_END_TIME && player.END_POSITION_XY !== player.CUR_POSITION_XY) {
                // Игрок двигается - запускаем клиентскую анимацию
                const startPos = parseXY(player.START_POSITION_XY);
                const endPos = parseXY(player.END_POSITION_XY);
                
                // Уникальный ключ для этой анимации (на основе времени начала)
                const animKey = `${pid}_${Math.floor(player.MOVE_START_TIME)}`;
                
                // Проверяем не запущена ли уже эта анимация
                if (!state.animations.has(animKey)) {
                    // ★ Снимаем старые анимации этого игрока ВМЕСТЕ с их
                    // rAF-циклами (stopAllMoveAnimations): раньше был только
                    // delete из Map — старый цикл продолжал жить и писал в
                    // transform параллельно с новым. Два независимых цикла по
                    // одному спрайту = микродёрганье даже при точных часах.
                    stopAllMoveAnimations(`${pid}_`);

                    // Мгновенно доворачиваем спрайт по серверному TURN (не дожидаясь следующего поллинга)
                    applyTurnClass(entity, player.TURN);

                    // ★ Непрерывность старта: если спрайт сейчас стоит НЕ на
                    // стартовой клетке нового маршрута (предыдущий ход ещё не
                    // «доехал» визуально или позиция отстала), мгновенная
                    // перестановка + обнуление transform = рывок в начале пути.
                    // FLIP-приём: ставим div на новую клетку, но визуальным
                    // пиксельным сдвигом возвращаем спрайт на прежнее экранное
                    // место, затем за короткую линейную скользку доезжаем до
                    // точки серверной интерполяции — старт непрерывен.
                    const startPosRect = (() => {
                        const sc = document.getElementById(`cell-${startPos.x}-${startPos.y}`);
                        return sc && entity.parentElement !== sc ? { cell: sc } : null;
                    })();
                    if (startPosRect) {
                        clearSettleState(entity); // старт нового пути важнее недоигранной скользки прошлого
                        const oldRect = entity.getBoundingClientRect();
                        entity.style.transition = 'none';
                        entity.style.transform = '';
                        startPosRect.cell.appendChild(entity);
                        const newRect = entity.getBoundingClientRect();
                        const dx = oldRect.left - newRect.left;
                        const dy = oldRect.top - newRect.top;
                        const nowS = serverTimeSec();
                        const p0 = Math.max(0, Math.min(1,
                            (player.MOVE_END_TIME > player.MOVE_START_TIME)
                                ? (nowS - player.MOVE_START_TIME) / (player.MOVE_END_TIME - player.MOVE_START_TIME)
                                : 0));
                        const tgtX = startPos.x + (endPos.x - startPos.x) * p0;
                        const tgtY = startPos.y + (endPos.y - startPos.y) * p0;
                        const destPx = (tgtX - startPos.x) * CONFIG.CELL_SIZE;
                        const destPy = (tgtY - startPos.y) * CONFIG.CELL_SIZE;
                        const flipCls = entity.classList.contains('turn-left') ? ' scaleX(-1)' : '';
                        if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                            entity.dataset.animCx = startPos.x;
                            entity.dataset.animCy = startPos.y;
                            entity.style.transform = `translate(${dx}px, ${dy}px)` + flipCls;
                            void entity.offsetWidth; // reflow: стартовая точка кадра зафиксирована
                            // ★ Скользка — через glideTransform (transitionend, а
                            // не setTimeout): старый таймер на 160 мс мог быть
                            // перехвачен следующим poll'ом и оборвать FLIP на
                            // середине — тот же класс микро-рывка, что и на финише.
                            glideTransform(entity, `translate(${destPx}px, ${destPy}px)` + flipCls);
                        } else {
                            delete entity.dataset.animCx;
                            delete entity.dataset.animCy;
                        }
                    }

                    // Запускаем новую анимацию
                    startMoveAnimation(pid, startPos, endPos, player.MOVE_START_TIME, player.MOVE_END_TIME, animKey);
                }
            } else {
                // Игрок не двигается - показываем серверную позицию
                const pos = parseXY(player.CUR_POSITION_XY);
                const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
                
                // Проверяем нет ли активной анимации для этого игрока
                let pendingAnim = null;
                let pendingKey = null;
                state.animations.forEach((anim, key) => {
                    if (key.startsWith(`${pid}_`)) { pendingAnim = anim; pendingKey = key; }
                });

                if (!pendingAnim) {
                    // Перемещаем без анимации на серверную позицию
                    clearSettleState(entity); // мгновенная фиксация — висящий transition недопустим
                    entity.style.transform = '';
                    if (cell && entity.parentElement !== cell) {
                        entity.style.transition = 'none';
                        cell.appendChild(entity);
                        // ★ Сбрасываем кэш клетки гистерезиса интерполяции: он
                        // хранит клетку, в которой спрайт стоял при последней
                        // rAF-анимации. После мгновенной перестановки div'а
                        // (дверь/респаун/purge — сетка перестроена) следующий
                        // animateMove иначе вычислял offset от СТАРОЙ клетки и
                        // спрайт «допрыгивал» её рывком в начале нового пути.
                        delete entity.dataset.animCx;
                        delete entity.dataset.animCy;
                    }
                } else if (serverTimeSec() >= pendingAnim.serverEndTime) {
                    // ★ Анимация «дожила» до серверного финала ещё до следующего
                    // poll-ответа: снимаем её и фиксируем позицию тем же мягким
                    // способом (catchUp подтвердит END серверными часами).
                    state.animations.delete(pendingKey);
                    catchUpMoveEnd(pid, pendingAnim);
                }
            }
        });
    }
    
    // Отрисовка/обновление объектов
    if (data.objects) {
        Object.keys(data.objects).forEach(oid => {
            const obj = data.objects[oid];

            // ★ Комнатная изоляция: объект чужой комнаты не рисуется поверх
            // этой карты; его div удаляется с поля (перерисуется в своей комнате).
            // querySelector без scope искал div по ВСЕМУ документу — после
            // rebuildGrid() в DOM живут два набора ячеек (старый удалённый и
            // новый), из-за чего «найденный» div мог оставаться в клетке
            // старой сетки, а в новой комнате создавался второй — визуальный
            // ДУБЛЬ бочки/турели во всех комнатах. Ищем строго внутри cells
            // текущей сетки и синхронизируем позицию при каждом рендере.
            if (viewRoomId && obj.ROOM_ID !== viewRoomId) {
                const foreignObj = findEntity('object', oid);
                if (foreignObj) foreignObj.remove();
                return;
            }

            // Удаляем если уничтожен
            if (obj.STATUS === 'DESTROYED') {
                const existing = findEntity('object', oid);
                if (existing) existing.remove();
                return;
            }
            
            let entity = findEntity('object', oid);
            
            if (!entity) {
                entity = createObjectEntity(obj);
                entity.dataset.objectId = oid;
            }
            entity.dataset.roomId = obj.ROOM_ID || '';
            keptEntities.add(entity);
            const pos = parseXY(obj.POSITION_XY);
            const cell = document.getElementById('cell-' + pos.x + '-' + pos.y);
            if (cell && entity.parentElement !== cell) cell.appendChild(entity);
        });
    }
    
    // Отрисовка/обновление ловушек (видны только своему владельцу)
    if (data.traps) {
        Object.keys(data.traps).forEach(tid => {
            const trap = data.traps[tid];

            // ★ Комнатная изоляция: ловушка чужой комнаты не рисуется на этой карте.
            if (viewRoomId && trap.ROOM_ID !== viewRoomId) {
                const foreignTrap = findEntity('trap', tid);
                if (foreignTrap) foreignTrap.remove();
                return;
            }

            
            // Удаляем если не INTACT или не своя
            if (trap.STATUS !== 'INTACT' || (trap.ALLY !== state.playerId && trap.ALLY !== 'NONE')) {
                const existing = findEntity('trap', tid);
                if (existing) existing.remove();
                return;
            }
            
            let entity = findEntity('trap', tid);
            
            if (!entity) {
                entity = createTrapEntity(trap);
                entity.dataset.trapId = tid;
            }
            entity.dataset.roomId = trap.ROOM_ID || '';
            keptEntities.add(entity);
            // ★ Позиция синхронизируется при КАЖДОМ рендере (не только при
            // создании): div мог остаться в клетке перестроенной сетки после
            // смены комнаты — без этого мина «прилипала» к старым координатам.
            const tpos = parseXY(trap.POSITION_XY);
            const tcell = document.getElementById('cell-' + tpos.x + '-' + tpos.y);
            if (tcell && entity.parentElement !== tcell) tcell.appendChild(entity);
        });
    }
    
    // Отрисовка/обновление NPC
    if (data.npcs) {
        Object.keys(data.npcs).forEach(nid => {
            const npc = data.npcs[nid];

            // ★ Комнатная изоляция: NPC чужой комнаты не рисуется на этой карте.
            // Поиск div — строго внутри cells (см. начало renderGrid): глобальный
            // querySelector находил «сиротские» div'ы в старых перестроенных
            // сетках, из-за чего турель ДУБЛИРОВАЛАСЬ во всех комнатах, кроме
            // спавна игрока.
            if (viewRoomId && npc.ROOM_ID !== viewRoomId) {
                const foreignNpc = findEntity('npc', nid);
                if (foreignNpc) foreignNpc.remove();
                return;
            }

            
            // Удаляем если уничтожен
            if (npc.STATUS === 'DESTROYED') {
                const existing = findEntity('npc', nid);
                if (existing) existing.remove();
                return;
            }
            
            let entity = findEntity('npc', nid);
            
            if (!entity) {
                entity = createNpcEntity(npc);
                entity.dataset.npcId = nid;
            }
            entity.dataset.roomId = npc.ROOM_ID || '';
            keptEntities.add(entity);
            // ★ Позиция синхронизируется при КАЖДОМ рендере: после смены
            // комнаты div мог остаться в клетке перестроенной сетки.
            const npos = parseXY(npc.POSITION_XY);
            const ncell = document.getElementById('cell-' + npos.x + '-' + npos.y);
            if (ncell && entity.parentElement !== ncell) {
                entity.style.transition = 'none';
                ncell.appendChild(entity);
            }
            // Обновляем статус NPC
            const statusEl = entity.querySelector('.entity-status');
            if (statusEl) {
                statusEl.textContent = npc.STATUS.toLowerCase();
            }
            entity.className = 'entity npc-entity status-' + npc.STATUS.toLowerCase();
        });
    }
    
    // ★ Гарантированная очистка: удалить из сетки все div'ы сущностей чужих
    // комнат и «осиротевшие» элементы (см. purgeOutOfViewRoomEntities).
    purgeOutOfViewRoomEntities(viewRoomId, keptEntities);

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
    // Отражение спрайта при первичной отрисовке (TURN из снимка бойцов / сервера)
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
        // ★ Комнатная изоляция: poll отдаёт снаряды ВСЕХ комнат, а координаты
        // разных комнат пересекаются — без фильтра взрыв чужой комнаты
        // отрисовывался бы поверх этой карты («призрачные» попадания в пустых
        // комнатах). Комната снаряда = комната кастера из players.
        if (state.currentRoomId) {
            const caster = data.players && data.players[proj.caster];
            const projRoom = caster ? caster.ROOM_ID : null;
            if (projRoom !== state.currentRoomId) return;
        }
        const cell = document.getElementById('cell-' + proj.to_x + '-' + proj.to_y);
        if (!cell) return;
        
        const el = document.createElement('div');
        el.className = 'projectile projectile-' + proj.status;
        
        // Внешний вид снаряда определяется МЕХАНИКОЙ (projectile), а не «типом
        // урона» — типов урона в игре нет, урон — это просто урон.
        if (proj.type === 'area') {
            el.classList.add('kind-area');
        } else if (proj.type === 'line') {
            el.classList.add('kind-line');
        } else {
            el.classList.add('kind-beam');
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
