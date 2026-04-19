// client/js/game.js

// ================= STATE =================
const state = {
    user: null,
    characters: [],
    inventory: [],
    friends: [],
    abilities: [],
    selectedCharacter: null,
    equippedItems: [null, null, null, null, null],
    socket: null,
    gameId: null,
    battle: {
        map: null,
        currentRoom: { x: 2, y: 2 },
        players: [],
        objects: [],
        turnTimer: 10,
        mode: 'free', // 'free' or 'turn'
        myPlayerId: null,
        actionQueue: [],
        ap: 2,
        maxAp: 2
    }
};

// ================= CONSTANTS =================
const CELL_SIZE = 40; // pixels
const ROOM_WIDTH = 15;
const ROOM_HEIGHT = 6;
const ANIMATION_FRAME_DURATION = 100; // ms per frame

// ================= INIT =================
document.addEventListener('DOMContentLoaded', () => {
    initAuth();
    initNavigation();
    state.socket = io();
    setupSocketListeners();
});

// ================= AUTH =================
function initAuth() {
    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('login-username').value;
        const password = document.getElementById('login-password').value;
        
        try {
            const res = await fetch('/api/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();
            if (data.success) {
                state.user = data.user;
                showScreen('main-menu');
                updateUserInfo();
                loadProfile();
            } else {
                alert(data.error);
            }
        } catch (err) {
            alert('Connection error');
        }
    });
    
    document.getElementById('register-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('register-username').value;
        const password = document.getElementById('register-password').value;
        
        try {
            const res = await fetch('/api/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();
            if (data.success) {
                alert('Registered! Please login.');
            } else {
                alert(data.error);
            }
        } catch (err) {
            alert('Connection error');
        }
    });
    
    document.getElementById('logout-btn').addEventListener('click', () => {
        state.user = null;
        showScreen('auth-screen');
    });
}

function updateUserInfo() {
    document.getElementById('player-name').textContent = state.user.username;
    document.getElementById('player-currency').textContent = `💰 ${state.user.currency}`;
    document.getElementById('player-rating').textContent = `⭐ ${state.user.rating}`;
}

async function loadProfile() {
    const res = await fetch(`/api/user/${state.user.id}/profile`);
    const data = await res.json();
    state.characters = data.characters || [];
    state.inventory = data.inventory || [];
    state.friends = data.friends || [];
    state.abilities = data.abilities || [];
    
    if (state.characters.length > 0) {
        state.selectedCharacter = state.characters[0];
        renderArsenal();
    }
}

// ================= NAVIGATION =================
function initNavigation() {
    document.querySelectorAll('.menu-buttons button').forEach(btn => {
        btn.addEventListener('click', () => {
            showScreen(btn.dataset.screen + '-screen');
            if (btn.dataset.screen === 'friends') renderFriends();
            if (btn.dataset.screen === 'shop') loadShop();
        });
    });
    
    document.querySelectorAll('.back-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            showScreen('main-menu');
        });
    });
}

function showScreen(screenId) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    const target = screenId.includes('screen') ? document.getElementById(screenId) : document.getElementById(screenId + '-screen');
    if (target) target.classList.add('active');
}

// ================= ARSENAL =================
function renderArsenal() {
    const charList = document.getElementById('character-list');
    charList.innerHTML = '';
    
    state.characters.forEach((char, idx) => {
        const div = document.createElement('div');
        div.className = 'char-item' + (state.selectedCharacter && state.selectedCharacter.id === char.id ? ' selected' : '');
        div.textContent = `${char.class_name} (${char.faction})`;
        div.addEventListener('click', () => {
            state.selectedCharacter = char;
            renderArsenal();
            renderCharacterDetail();
        });
        charList.appendChild(div);
    });
    
    if (state.selectedCharacter) {
        renderCharacterDetail();
    }
}

function renderCharacterDetail() {
    const char = state.selectedCharacter;
    if (!char) return;
    
    // Stats
    const statsDiv = document.getElementById('char-stats');
    statsDiv.innerHTML = `
        <p>HP: ${char.base_hp}</p>
        <p>Resistance: ${char.base_resistance}%</p>
        <p>Overguard: ${char.base_overguard}</p>
        <p>Damage: ${char.base_damage}</p>
        <p>AP: ${char.base_ap}</p>
        <p>Range: ${char.base_range}</p>
    `;
    
    // Preview (placeholder - will draw sprite when available)
    const canvas = document.getElementById('char-preview-canvas');
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#333';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#fff';
    ctx.font = '20px Arial';
    ctx.textAlign = 'center';
    ctx.fillText(char.class_name, canvas.width/2, canvas.height/2);
    
    // Equipment slots
    const equipDiv = document.getElementById('equipment-slots');
    equipDiv.innerHTML = '';
    for (let i = 0; i < 5; i++) {
        const slot = document.createElement('div');
        slot.className = 'equip-slot' + (state.equippedItems[i] ? ' filled' : '');
        if (state.equippedItems[i]) {
            const img = document.createElement('img');
            img.src = `assets/items/${state.equippedItems[i].icon_path}`;
            img.onerror = () => img.src = '';
            slot.appendChild(img);
        }
        slot.addEventListener('click', () => openItemSelector(i));
        equipDiv.appendChild(slot);
    }
    
    // Ability slots
    const abilityDiv = document.getElementById('ability-slots');
    abilityDiv.innerHTML = '';
    const charAbilities = state.abilities.filter(a => a.slot_index >= 0);
    for (let i = 0; i < 4; i++) {
        const slot = document.createElement('div');
        slot.className = 'ability-slot';
        const ability = charAbilities.find(a => a.slot_index === i);
        if (ability) {
            slot.classList.add('filled');
            const img = document.createElement('img');
            img.src = `assets/abilities/icons/${ability.icon_path}`;
            img.onerror = () => img.src = '';
            slot.appendChild(img);
            slot.title = ability.name;
        } else {
            slot.textContent = i < 2 ? (i === 0 ? 'Auto' : 'Class') : 'Empty';
        }
        abilityDiv.appendChild(slot);
    }
}

function openItemSelector(slotIndex) {
    // Simplified: cycle through inventory
    const available = state.inventory.filter(i => i.quantity > 0);
    if (available.length === 0) return;
    
    const current = state.equippedItems[slotIndex];
    let nextIdx = 0;
    if (current) {
        const idx = available.findIndex(i => i.id === current.id);
        nextIdx = (idx + 1) % available.length;
    }
    
    const item = available[nextIdx];
    state.equippedItems[slotIndex] = item;
    renderCharacterDetail();
}

// ================= SHOP =================
async function loadShop() {
    const content = document.getElementById('shop-content');
    content.innerHTML = '<p>Loading...</p>';
    
    const res = await fetch('/api/shop/characters');
    const chars = await res.json();
    
    content.innerHTML = '';
    chars.forEach(char => {
        const div = document.createElement('div');
        div.className = 'shop-item';
        div.innerHTML = `
            <h4>${char.name}</h4>
            <p>${char.faction}</p>
            <p>HP:${char.base_hp} DMG:${char.base_damage}</p>
            <button onclick="buyCharacter(${char.id})">Buy (1000💰)</button>
        `;
        content.appendChild(div);
    });
}

async function buyCharacter(classId) {
    const res = await fetch(`/api/user/${state.user.id}/buy-character`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ classId })
    });
    const data = await res.json();
    if (data.success) {
        alert('Character purchased!');
        loadProfile();
    } else {
        alert(data.error);
    }
}

// ================= FRIENDS =================
function renderFriends() {
    const list = document.getElementById('friends-list');
    list.innerHTML = '';
    
    state.friends.forEach(friend => {
        const div = document.createElement('div');
        div.className = 'friend-item';
        div.innerHTML = `
            <span>${friend.username}</span>
            <span class="friend-status ${friend.status}"></span>
            <button onclick="inviteFriend(${friend.id})">Invite</button>
        `;
        list.appendChild(div);
    });
}

function inviteFriend(friendId) {
    state.socket.emit('invite-friend', { fromUserId: state.user.id, toUserId: friendId });
    alert('Invite sent!');
}

// ================= LOBBY =================
let selectedMode = null;

document.querySelectorAll('.mode-select button').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.mode-select button').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedMode = btn.dataset.mode;
        document.getElementById('start-match-btn').disabled = false;
    });
});

document.getElementById('start-match-btn').addEventListener('click', () => {
    if (!selectedMode) return;
    
    state.socket.emit('join-lobby', { userId: state.user.id, mode: selectedMode });
    document.getElementById('lobby-status').textContent = 'Searching for match...';
});

function setupSocketListeners() {
    state.socket.on('match-found', (data) => {
        state.gameId = data.gameId;
        startBattle();
    });
    
    state.socket.on('turn-result', (data) => {
        processTurnResults(data.results);
    });
}

// ================= BATTLE SYSTEM =================
function startBattle() {
    showScreen('battle-screen');
    initBattle();
}

function initBattle() {
    const canvas = document.getElementById('battle-canvas');
    const ctx = canvas.getContext('2d');
    
    // Initialize battle state
    state.battle.currentRoom = { x: 2, y: 2 };
    state.battle.mode = 'free';
    state.battle.turnTimer = 10;
    state.battle.ap = state.selectedCharacter ? state.selectedCharacter.base_ap : 2;
    state.battle.maxAp = state.battle.ap;
    
    // Create test players
    state.battle.players = [
        { id: 1, x: 3, y: 3, class: 'SniperDefault', team: 0, hp: 5, maxHp: 5 },
        { id: 2, x: 10, y: 3, class: 'TankBot', team: 1, hp: 8, maxHp: 8 }
    ];
    state.battle.myPlayerId = 1;
    
    // Load room
    loadRoom(2, 2);
    
    // Start game loop
    requestAnimationFrame(battleLoop);
    
    // Setup controls
    setupBattleControls(canvas);
}

function loadRoom(roomX, roomY) {
    // Generate room objects based on coordinates
    const objects = [];
    
    // Portals
    if (roomX < 4) objects.push({ type: 'portal', x: 14, y: 2, target: { x: roomX+1, y: roomY } });
    if (roomX > 0) objects.push({ type: 'portal', x: 0, y: 2, target: { x: roomX-1, y: roomY } });
    if (roomY < 4) objects.push({ type: 'portal', x: 7, y: 5, target: { x: roomX, y: roomY+1 } });
    if (roomY > 0) objects.push({ type: 'portal', x: 7, y: 0, target: { x: roomX, y: roomY-1 } });
    
    // Exit in center
    if (roomX === 2 && roomY === 2) {
        objects.push({ type: 'EXIT', x: 7, y: 3, id: 'exit' });
    }
    
    // Some obstacles
    objects.push({ type: 'wall', x: 5, y: 2, hp: 999 });
    objects.push({ type: 'lootbox', x: 12, y: 4, opened: false });
    
    state.battle.objects = objects;
}

let clickedCell = null;
let selectedAction = null;

function setupBattleControls(canvas) {
    canvas.addEventListener('click', (e) => {
        const rect = canvas.getBoundingClientRect();
        const x = Math.floor((e.clientX - rect.left) / CELL_SIZE);
        const y = Math.floor((e.clientY - rect.top) / CELL_SIZE);
        
        clickedCell = { x, y };
        
        // Check if clicking on actionable area
        const player = state.battle.players.find(p => p.id === state.battle.myPlayerId);
        if (!player) return;
        
        const dist = Math.abs(x - player.x) + Math.abs(y - player.y);
        const range = state.selectedCharacter ? state.selectedCharacter.base_range : 5;
        
        if (dist <= range && state.battle.mode === 'turn' && state.battle.ap > 0) {
            showActionMenu(e.clientX, e.clientY, x, y);
        } else if (state.battle.mode === 'free') {
            // Free movement
            if (dist === 1 && canMoveTo(x, y)) {
                movePlayer(player, x, y);
            }
        }
    });
    
    document.getElementById('action-menu').addEventListener('click', (e) => {
        if (e.target.tagName === 'BUTTON') {
            const action = e.target.dataset.action;
            executeAction(action, clickedCell);
        }
    });
}

function canMoveTo(x, y) {
    // Check bounds
    if (x < 0 || x >= ROOM_WIDTH || y < 0 || y >= ROOM_HEIGHT) return false;
    
    // Check obstacles
    const obstacle = state.battle.objects.find(o => o.x === x && o.y === y && (o.type === 'wall' || o.type === 'EXIT'));
    if (obstacle) return false;
    
    // Check other players (can't move onto them in free mode)
    const player = state.battle.players.find(p => p.x === x && p.y === y);
    if (player && player.id !== state.battle.myPlayerId) return false;
    
    return true;
}

function movePlayer(player, x, y) {
    player.x = x;
    player.y = y;
    
    // Check for portal
    const portal = state.battle.objects.find(o => o.type === 'portal' && o.x === x && o.y === y);
    if (portal) {
        state.battle.currentRoom = portal.target;
        loadRoom(portal.target.x, portal.target.y);
        player.x = portal.target.x === state.battle.currentRoom.x + 1 ? 1 : 13; // Adjust spawn
        player.y = 2;
    }
    
    // Check for EXIT
    const exit = state.battle.objects.find(o => o.type === 'EXIT' && o.x === x && o.y === y);
    if (exit) {
        alert('Victory!');
        endBattle(true);
    }
    
    checkTurnMode();
}

function checkTurnMode() {
    const myPlayer = state.battle.players.find(p => p.id === state.battle.myPlayerId);
    const enemies = state.battle.players.filter(p => p.team !== myPlayer.team);
    
    // Check if any enemy is in same room (simplified: just check if enemies exist)
    if (enemies.length > 0) {
        state.battle.mode = 'turn';
        state.battle.ap = state.battle.maxAp;
        document.getElementById('battle-mode-indicator').textContent = 'TURN MODE';
        document.getElementById('battle-mode-indicator').style.color = '#e94560';
        startTurnTimer();
    } else {
        state.battle.mode = 'free';
        document.getElementById('battle-mode-indicator').textContent = 'FREE MOVE';
        document.getElementById('battle-mode-indicator').style.color = '#4CAF50';
    }
}

function showActionMenu(clientX, clientY, cellX, cellY) {
    const menu = document.getElementById('action-menu');
    menu.style.position = 'absolute';
    menu.style.left = clientX + 'px';
    menu.style.top = clientY + 'px';
    menu.classList.remove('hidden');
    
    clickedCell = { x: cellX, y: cellY };
}

function executeAction(action, target) {
    document.getElementById('action-menu').classList.add('hidden');
    
    const player = state.battle.players.find(p => p.id === state.battle.myPlayerId);
    if (!player) return;
    
    if (action === 'move') {
        if (canMoveTo(target.x, target.y)) {
            movePlayer(player, target.x, target.y);
            state.battle.ap--;
        }
    } else if (action === 'attack') {
        // Auto attack
        performAttack(player, target, 1.0);
        state.battle.ap--;
    } else if (action.startsWith('ability')) {
        const abilityIdx = parseInt(action.replace('ability', '')) - 1;
        const ability = state.abilities[abilityIdx];
        if (ability && state.battle.ap >= ability.ap_cost) {
            useAbility(player, target, ability);
            state.battle.ap -= ability.ap_cost;
        }
    } else if (action === 'end-turn') {
        endTurn();
    }
    
    updateAPDisplay();
}

function performAttack(attacker, target, multiplier) {
    const damage = Math.ceil(state.selectedCharacter.base_damage * multiplier);
    
    // Send to server
    state.socket.emit('battle-action', {
        gameId: state.gameId,
        playerId: state.battle.myPlayerId,
        action: {
            type: 'attack',
            target: target,
            damage: damage,
            attackerPos: { x: attacker.x, y: attacker.y }
        }
    });
    
    // Visual feedback (simplified)
    console.log(`Attack dealt ${damage} damage`);
}

function useAbility(player, target, ability) {
    if (ability.effect_type === 'create_wall') {
        state.battle.objects.push({ type: 'wall', x: target.x, y: target.y, hp: ability.effect_value });
    } else if (ability.effect_type === 'create_mine') {
        state.battle.objects.push({ type: 'mine', x: target.x, y: target.y, damage: ability.effect_value, hidden: true });
    } else if (ability.effect_type === 'damage') {
        performAttack(player, target, ability.damage_multiplier);
    }
    
    state.socket.emit('battle-action', {
        gameId: state.gameId,
        playerId: state.battle.myPlayerId,
        action: {
            type: 'ability',
            abilityId: ability.id,
            target: target
        }
    });
}

function endTurn() {
    state.socket.emit('battle-action', {
        gameId: state.gameId,
        playerId: state.battle.myPlayerId,
        action: { type: 'end-turn' }
    });
}

function startTurnTimer() {
    state.battle.turnTimer = 10;
    const timerEl = document.getElementById('turn-timer');
    
    const interval = setInterval(() => {
        if (state.battle.mode !== 'turn') {
            clearInterval(interval);
            return;
        }
        
        state.battle.turnTimer--;
        timerEl.textContent = state.battle.turnTimer;
        
        if (state.battle.turnTimer <= 0) {
            clearInterval(interval);
            endTurn();
        }
    }, 1000);
}

function updateAPDisplay() {
    document.getElementById('action-points').textContent = `AP: ${state.battle.ap}/${state.battle.maxAp}`;
}

function processTurnResults(results) {
    // Apply results from server
    results.forEach(result => {
        if (result.damage) {
            // Apply damage to target
            const target = state.battle.players.find(p => p.x === result.target.x && p.y === result.target.y);
            if (target) {
                target.hp -= result.damage;
                if (target.hp <= 0) {
                    // Player died
                    target.dead = true;
                }
            }
        }
    });
    
    checkTurnMode();
}

function endBattle(victory) {
    alert(victory ? 'VICTORY!' : 'DEFEAT');
    showScreen('main-menu');
}

// ================= RENDER LOOP =================
function battleLoop() {
    if (!document.getElementById('battle-screen').classList.contains('active')) return;
    
    const canvas = document.getElementById('battle-canvas');
    const ctx = canvas.getContext('2d');
    
    // Clear
    ctx.fillStyle = '#1a1a2e';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    // Draw grid
    ctx.strokeStyle = '#333';
    for (let x = 0; x <= ROOM_WIDTH; x++) {
        ctx.beginPath();
        ctx.moveTo(x * CELL_SIZE, 0);
        ctx.lineTo(x * CELL_SIZE, ROOM_HEIGHT * CELL_SIZE);
        ctx.stroke();
    }
    for (let y = 0; y <= ROOM_HEIGHT; y++) {
        ctx.beginPath();
        ctx.moveTo(0, y * CELL_SIZE);
        ctx.lineTo(ROOM_WIDTH * CELL_SIZE, y * CELL_SIZE);
        ctx.stroke();
    }
    
    // Draw objects
    state.battle.objects.forEach(obj => {
        if (obj.type === 'wall') {
            ctx.fillStyle = '#666';
            ctx.fillRect(obj.x * CELL_SIZE + 2, obj.y * CELL_SIZE + 2, CELL_SIZE - 4, CELL_SIZE - 4);
        } else if (obj.type === 'portal') {
            ctx.fillStyle = '#9b59b6';
            ctx.beginPath();
            ctx.arc(obj.x * CELL_SIZE + CELL_SIZE/2, obj.y * CELL_SIZE + CELL_SIZE/2, CELL_SIZE/3, 0, Math.PI * 2);
            ctx.fill();
        } else if (obj.type === 'EXIT') {
            ctx.fillStyle = '#2ecc71';
            ctx.fillRect(obj.x * CELL_SIZE, obj.y * CELL_SIZE, CELL_SIZE, CELL_SIZE);
            ctx.fillStyle = '#fff';
            ctx.font = '12px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('EXIT', obj.x * CELL_SIZE + CELL_SIZE/2, obj.y * CELL_SIZE + CELL_SIZE/2 + 4);
        } else if (obj.type === 'lootbox') {
            ctx.fillStyle = obj.opened ? '#555' : '#f39c12';
            ctx.fillRect(obj.x * CELL_SIZE + 5, obj.y * CELL_SIZE + 5, CELL_SIZE - 10, CELL_SIZE - 10);
        } else if (obj.type === 'mine') {
            if (!obj.hidden) {
                ctx.fillStyle = '#e74c3c';
                ctx.beginPath();
                ctx.arc(obj.x * CELL_SIZE + CELL_SIZE/2, obj.y * CELL_SIZE + CELL_SIZE/2, 8, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    });
    
    // Draw players
    state.battle.players.forEach(player => {
        if (player.dead) return;
        
        ctx.fillStyle = player.team === 0 ? '#3498db' : '#e74c3c';
        ctx.fillRect(player.x * CELL_SIZE + 5, player.y * CELL_SIZE + 5, CELL_SIZE - 10, CELL_SIZE - 10);
        
        // HP bar
        const hpPercent = player.hp / player.maxHp;
        ctx.fillStyle = '#333';
        ctx.fillRect(player.x * CELL_SIZE + 2, player.y * CELL_SIZE - 5, CELL_SIZE - 4, 4);
        ctx.fillStyle = hpPercent > 0.5 ? '#2ecc71' : '#e74c3c';
        ctx.fillRect(player.x * CELL_SIZE + 2, player.y * CELL_SIZE - 5, (CELL_SIZE - 4) * hpPercent, 4);
        
        // Character name
        ctx.fillStyle = '#fff';
        ctx.font = '10px Arial';
        ctx.textAlign = 'center';
        ctx.fillText(player.class.substring(0, 4), player.x * CELL_SIZE + CELL_SIZE/2, player.y * CELL_SIZE - 8);
    });
    
    requestAnimationFrame(battleLoop);
}
