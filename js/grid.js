// grid.js — построение DOM-сетки комнат и поиск/очистка сущностей в ней.
// Часть модульного клиента: см. AGENTS.md §5.

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

/**
 * ★ Перестроение поля при СМЕНЕ комнаты: то же, что buildGrid(), но вызывается
 * из renderGrid(), когда poll-ответ принёс другую комнату. container.innerHTML
 *='' удаляет старые клетки вместе со всеми «осиротевшими» div'ами сущностей
 * прежней комнаты — это страховка поверх purgeOutOfViewRoomEntities():
 * визуально в комнате никогда не остаётся ничего из другой комнаты.
 */
function rebuildGrid() {
    buildGrid();
}

/**
 * ★ Найти DOM-элемент сущности СТРОГО внутри текущей видимой сетки.
 * Глобальный document.querySelector находил «сиротские» div'ы в старых
 * перестроенных сетках (после смены комнаты), поэтому объекты/NPC могли
 * дублироваться визуально во всех комнатах сразу. Видимая сетка всегда
 * последняя в DOM — lastElementChild даёт именно её.
 */
function findEntity(kind, id) {
    const attr = kind === 'player' ? 'data-player-id'
        : kind === 'object' ? 'data-object-id'
        : kind === 'trap' ? 'data-trap-id'
        : 'data-npc-id';
    const container = document.getElementById('grid-container');
    if (!container) return null;
    // Сиротские div'ы старых комнат живут ВНЕ контейнера (их клетки удалены из
    // DOM вместе со старой сеткой) — ищем строго внутри текущего поля.
    return container.querySelector(`[${attr}="${id}"]`);
}

/**
 * ★ Комнатная изоляция клиентских проверок (см. state.currentRoomId).
 * Сущность принадлежит рисуемой комнате? Игрок обязан иметь ROOM_ID —
 * боец без комнаты НЕ считается «своим» (сервер гарантирует поле в
 * poll-ответе; undefined == null в JS истинно, поэтому сравнение строгое).
 */
function inCurrentRoom(e) {
    if (!e) return false;
    const room = state.currentRoomId;
    if (!room) return true; // legacy-режим единой карты — фильтра нет
    return e.ROOM_ID === room;
}

/**
 * ★ КОМНАТНАЯ ИЗОЛЯЦИЯ ОТРИСОВКИ (корень бага «турель/мина видны во всех
 * комнатах»): poll-ответ содержит objects/traps/npcs только текущей комнаты,
 * поэтому div'ы сущностей чужих комнат раньше НИКОГДА не удалялись — после
 * перехода по помещениям они оставались в DOM поверх новой карты по тем же
 * координатам (клетки разных комнат пересекаются), визуально «дублируя»
 * турели, мины и бочки в каждой комнате. Теперь при КАЖДОМ рендере проходятся
 * все div'ы сущностей внутри сетки: всё, что относится к другой комнате
 * (data-room-id ≠ рисуемой) или чему сервер больше не отдаёт элемент
 * (перешло в другую комнату / уничтожено / скрыто), — удаляется с поля.
 * Отрицательный whitelist обязателен: poll фильтрует списки по комнате, так
 * что «исчезнувший» из ответа объект нельзя отличить от перешедшего в другую
 * комнату без сравнения data-room-id div'а с рисуемой комнатой.
 */
function purgeOutOfViewRoomEntities(viewRoomId, keep) {
    const container = document.getElementById('grid-container');
    if (!container) return;
    container.querySelectorAll('[data-player-id], [data-object-id], [data-trap-id], [data-npc-id]').forEach(el => {
        if (keep.has(el)) return;
        // Свой div игрока НЕ удаляем «вслепую»: раньше здесь стоял безусловный
        // el.remove(), из-за чего purge сносил единственный спрайт своего
        // бойца (зоны хода/атаки вокруг него оставались — они рисуются на
        // клетках) и фигурка игрока исчезала совсем. Теперь удаляем только
        // ДУБЛЬ: если в сетке уже есть другой div этого же id, официально
        // подтверждённый этим poll-ответом (он добавлен в keep до вызова
        // purge). Если официального div нет — текущий элемент храним как
        // единственный спрайт своего игрока (переживёт смену комнаты и
        // анимацию перехода).
        if (el.dataset.playerId && el.dataset.playerId === String(state.playerId)) {
            const official = Array.from(keep).some(k =>
                k !== el && k.dataset && k.dataset.playerId === el.dataset.playerId);
            if (official) el.remove();
            return;
        }
        const rid = el.dataset.roomId;
        if (viewRoomId && rid && rid !== viewRoomId) {
            el.remove(); // сущность чужой комнаты — визуальный дубль поверх этой карты
            return;
        }
        if (!rid) el.remove(); // устаревший div без метки комнаты — гарантированный сброс
    });
}

