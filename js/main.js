// main.js — точка входа: экран настройки персонажа (Этап 1).
// Часть модульного клиента: см. AGENTS.md §5. Загружается ПОСЛЕДНИМ.
//
// Порядок вызовов клиента (явный init перед join):
//   1) загрузка страницы → fetchRegistries(): GET init.php (обычный идемпотентный
//      вход, БЕЗ reset) — берём реестры abilities/passives для формы; мир при
//      этом не сбрасывается и живого боя не касается;
//   2) «Подключиться к бою» → joinGame(card): POST join.php (валидация карточки,
//      слот-спавн, CAS-запись бойца в мир);
//   3) успех join → enterBattle(player_id): GET init.php + buildGrid + startPolling.
// init намеренно НЕ вызывается автоматически из join.php: сервер остаётся
// единственной точкой сборки мира, а клиентский шаг явен и диагностируем.

const SETUP_STATS = [
    { field: 'HITPOINTS',    min: 1, max: 10, label: 'Здоровье' },
    { field: 'SHIELD',       min: 1, max: 10, label: 'Щит' },
    { field: 'RESISTANCE',   min: 1, max: 9,  label: 'Сопротивление' },
    { field: 'ATTACK',       min: 1, max: 10, label: 'Атака' },
    { field: 'RANGE',        min: 1, max: 10, label: 'Дальность' },
    { field: 'ACTIONS',      min: 1, max: 3,  label: 'Очки действий' },
];
const SETUP_ABILITY_COUNT = 3;   // ровно 3 способности (совпадает с join.php)
const SETUP_PASSIVES_MAX  = 5;   // 0..5 пассивок   (совпадает с join.php)

function setupStatus(msg, color) {
    const el = document.getElementById('init-status');
    if (!el) return;
    el.textContent = msg;
    el.style.color = color || '#eee';
}

/**
 * Шаг 1: подтянуть реестры способностей/пассивок из init.php (идемпотентный
 * обычный вход — мир не трогает) и наполнить селектор + чекбоксы формы.
 */
async function fetchRegistries() {
    setupStatus('Загрузка правил игры...', '#4ecca3');
    try {
        const resp = await fetch(CONFIG.PHP_URL + 'init.php');
        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || !data.success) {
            const detail = data && data.message ? data.message : ('HTTP ' + resp.status);
            setupStatus('Сервер недоступен: ' + detail +
                ' (соберите мир: php tools/reset_world.php)', '#ff4444');
            return false;
        }
        state.registries = { abilities: data.abilities || {}, passives: data.passives || {} };
        buildAbilitySelector(state.registries.abilities);
        buildPassivesList(state.registries.passives);
        setupStatus('', '');
        return true;
    } catch (err) {
        setupStatus('Ошибка загрузки реестров: ' + err.message, '#ff4444');
        return false;
    }
}

function buildAbilitySelector(abilities) {
    const sel = document.getElementById('sel-abilities');
    sel.innerHTML = '';
    Object.keys(abilities).forEach(name => {
        const opt = document.createElement('option');
        opt.value = name;
        const def = abilities[name] || {};
        opt.textContent = name + (def.range !== undefined ? ' (радиус ' + def.range + ')' : '');
        sel.appendChild(opt);
    });
    sel.addEventListener('change', updateAbilityCount);
    updateAbilityCount();
}

function updateAbilityCount() {
    const chosen = selectedAbilities();
    const el = document.getElementById('ability-count');
    el.textContent = chosen.length + '/' + SETUP_ABILITY_COUNT;
    el.style.color = chosen.length === SETUP_ABILITY_COUNT ? '#4ecca3' : '#ffaa00';
}

function selectedAbilities() {
    return Array.from(document.getElementById('sel-abilities').selectedOptions)
        .map(o => o.value);
}

function buildPassivesList(passives) {
    const box = document.getElementById('passives-list');
    box.innerHTML = '';
    Object.keys(passives).forEach(name => {
        const label = document.createElement('label');
        label.className = 'passive-item';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = name;
        cb.addEventListener('change', updatePassiveCount);
        label.appendChild(cb);
        label.appendChild(document.createTextNode(' ' + name));
        box.appendChild(label);
    });
    updatePassiveCount();
}

function selectedPassives() {
    return Array.from(document.querySelectorAll('#passives-list input:checked'))
        .map(cb => cb.value);
}

function updatePassiveCount() {
    const n = selectedPassives().length;
    const el = document.getElementById('passive-count');
    el.textContent = n + '/' + SETUP_PASSIVES_MAX;
    el.style.color = n <= SETUP_PASSIVES_MAX ? '#4ecca3' : '#ff4444';
}

/**
 * Клиентская половина правил join.php: проверка ДО отправки (быстрый фидбек),
 * сервер проверяет всё равно заново и не доверяет клиенту.
 * @return string[] список ошибок пуст, если карточка корректна
 */
function validateCharacterForm() {
    const errors = [];
    const uid = document.getElementById('fld-uid').value.trim();
    if (!/^[0-9]+$/.test(uid) || +uid < 1 || +uid > 4) {
        errors.push('UID: нужно число 1..4 (слот спавна этой сессии)');
    }
    const nick = document.getElementById('fld-nickname').value.trim();
    if (nick.length < 1 || nick.length > 24) {
        errors.push('Никнейм: 1..24 символа');
    }
    SETUP_STATS.forEach(s => {
        const raw = document.getElementById('stat-' + s.field).value.trim();
        if (!/^-?\d+$/.test(raw)) { errors.push(s.label + ': нужно целое число'); return; }
        const v = parseInt(raw, 10);
        if (v < s.min || v > s.max) errors.push(s.label + ': ' + v + ' вне диапазона ' + s.min + '-' + s.max);
    });
    const abils = selectedAbilities();
    if (abils.length !== SETUP_ABILITY_COUNT) {
        errors.push('Способности: выбрано ' + abils.length + ', нужно ровно ' + SETUP_ABILITY_COUNT);
    }
    const passives = selectedPassives();
    if (passives.length > SETUP_PASSIVES_MAX) {
        errors.push('Пассивки: максимум ' + SETUP_PASSIVES_MAX);
    }
    return errors;
}

/**
 * Собрать карточку персонажа — контракт php/join.php (в будущем её присылает
 * аккаунт игрока из БД, а не форма).
 */
function collectCharacterCard() {
    const stats = {};
    SETUP_STATS.forEach(s => {
        stats[s.field] = parseInt(document.getElementById('stat-' + s.field).value, 10);
    });
    return {
        uid: document.getElementById('fld-uid').value.trim(),
        nickname: document.getElementById('fld-nickname').value.trim(),
        stats: stats,
        abilities: selectedAbilities(),
        passives: selectedPassives(),
    };
}

document.addEventListener('DOMContentLoaded', function() {
    fetchRegistries();
    document.getElementById('character-form').addEventListener('submit', async function(ev) {
        ev.preventDefault();
        const errors = validateCharacterForm();
        if (errors.length) {
            setupStatus('Проверьте карточку: ' + errors.join(' · '), '#ff4444');
            return;
        }
        const btn = document.getElementById('btn-join');
        btn.disabled = true;
        setupStatus('Подключение к бою...', '#4ecca3');
        try {
            await joinGame(collectCharacterCard());
        } finally {
            btn.disabled = false;
        }
    });
});

