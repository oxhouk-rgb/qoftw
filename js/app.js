// Game State Management
const GameState = {
    currentPage: 'main',
    isAuthenticated: false,
    userData: {
        nickname: 'Player1',
        email: 'player@example.com',
        balance: 500000,
        avatar: '👤',
        rank: 500,
        wins: 25,
        losses: 10,
        premium: false
    },
    characters: [],
    items: [],
    friends: [],
    requests: []
};

// Navigation Functions
function navigateTo(page) {
    // Hide all content sections
    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.remove('active');
    });
    
    // Show target section
    const targetSection = document.getElementById(page);
    if (targetSection) {
        targetSection.classList.add('active');
        GameState.currentPage = page;
        
        // Update menu buttons active state
        document.querySelectorAll('.menu-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.page === page) {
                btn.classList.add('active');
            }
        });
        
        // Load page content dynamically
        loadPageContent(page);
    }
}

// Dynamic Content Loading
async function loadPageContent(page) {
    // Main page is already loaded inline, no need to fetch
    if (page === 'main') {
        initMainPage();
        return;
    }
    
    try {
        const response = await fetch(`pages/${page}.html`);
        if (response.ok) {
            const html = await response.text();
            const contentArea = document.querySelector(`#${page} .content-wrapper`);
            if (contentArea) {
                contentArea.innerHTML = html;
                
                // Initialize page-specific scripts after content is loaded
                setTimeout(() => initializePage(page), 100);
            }
        }
    } catch (error) {
        console.error(`Error loading ${page}:`, error);
    }
}

// Page Initialization
function initializePage(page) {
    switch(page) {
        case 'main':
            initMainPage();
            break;
        case 'profile':
            initProfilePage();
            break;
        case 'shop':
            initShopPage();
            break;
        case 'arsenal':
            initArsenalPage();
            break;
        case 'friends':
            initFriendsPage();
            break;
        case 'chat':
            initChatPage();
            break;
    }
}

// Main Page Initialization
function initMainPage() {
    // Add event listeners for game modes
    document.querySelectorAll('.mode-card').forEach(card => {
        card.addEventListener('click', function() {
            const mode = this.dataset.mode;
            console.log('Starting game mode:', mode);
            // Add your game start logic here
        });
    });
}

// Profile Page Initialization
function initProfilePage() {
    // Populate profile data
    const userData = GameState.userData;
    
    const nicknameInput = document.getElementById('profile-nickname');
    const emailInput = document.getElementById('profile-email');
    const currentPasswordInput = document.getElementById('profile-current-password');
    const newPasswordInput = document.getElementById('profile-new-password');
    
    if (nicknameInput) nicknameInput.value = userData.nickname;
    if (emailInput) emailInput.value = userData.email;
}

// Shop Page Initialization
function initShopPage() {
    // Load factions
    loadFactions();
}

function loadFactions() {
    const factions = [
        { id: 1, name: 'Северный Альянс', desc: 'Воины льда и стали' },
        { id: 2, name: 'Огненный Легион', desc: 'Мастеры разрушительной магии' },
        { id: 3, name: 'Лесные Стражи', desc: 'Защитники природы' },
        { id: 4, name: 'Техно Гильдия', desc: 'Инженеры будущего' }
    ];
    
    const container = document.getElementById('factions-container');
    if (container) {
        container.innerHTML = factions.map(faction => `
            <div class="faction-card" onclick="loadCharacters(${faction.id})">
                <div class="faction-name">${faction.name}</div>
                <div class="faction-desc">${faction.desc}</div>
            </div>
        `).join('');
    }
}

function loadCharacters(factionId) {
    // Generate more than 4 characters to demonstrate scrolling
    const characters = [];
    for (let i = 1; i <= 10; i++) {
        characters.push({
            id: i,
            name: `Персонаж ${factionId}-${i}`,
            price: Math.floor(Math.random() * 5000) + 1000,
            health: Math.floor(Math.random() * 10) + 1,
            resistance: Math.floor(Math.random() * 9),
            superDefense: Math.floor(Math.random() * 10),
            attack: Math.floor(Math.random() * 10) + 1,
            actionPoints: Math.floor(Math.random() * 3) + 1,
            description: `Боец фракции ${factionId}, уровень ${Math.floor(Math.random() * 50) + 1}`
        });
    }
    
    const container = document.getElementById('characters-container');
    if (container) {
        container.innerHTML = `
            <div class="character-grid">
                ${characters.map(char => `
                    <div class="character-card">
                        <div class="character-image">🎭</div>
                        <div class="character-name">${char.name}</div>
                        <div class="character-stats">
                            <div class="stat-item">❤️ ${char.health}</div>
                            <div class="stat-item">🛡️ ${char.resistance}</div>
                            <div class="stat-item">⚡ ${char.superDefense}</div>
                            <div class="stat-item">⚔️ ${char.attack}</div>
                            <div class="stat-item">🎯 ${char.actionPoints}</div>
                        </div>
                        <div class="character-price">💰 ${char.price}</div>
                        <div class="character-description">${char.description}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }
    
    // Show back button
    document.getElementById('back-to-shop').classList.remove('hidden');
}

// Arsenal Page Initialization
function initArsenalPage() {
    // Load character or placeholder
    loadArsenalCharacter();
}

function loadArsenalCharacter() {
    const hasCharacter = GameState.characters.length > 0;
    const display = document.getElementById('character-display');
    
    if (display) {
        display.innerHTML = hasCharacter ? '🦸' : '❓';
        display.onclick = () => {
            if (!hasCharacter) {
                showOwnedCharacters();
            }
        };
    }
}

function showOwnedCharacters() {
    // Similar to shop characters but only owned ones without prices
    const characters = [];
    for (let i = 1; i <= 6; i++) {
        characters.push({
            id: i,
            name: `Персонаж ${i}`,
            health: Math.floor(Math.random() * 10) + 1,
            resistance: Math.floor(Math.random() * 9),
            superDefense: Math.floor(Math.random() * 10),
            attack: Math.floor(Math.random() * 10) + 1,
            actionPoints: Math.floor(Math.random() * 3) + 1,
            description: `Ваш боец уровня ${Math.floor(Math.random() * 50) + 1}`
        });
    }
    
    showModal(`
        <div class="character-grid">
            ${characters.map(char => `
                <div class="character-card" onclick="selectCharacter(${char.id})">
                    <div class="character-image">🎭</div>
                    <div class="character-name">${char.name}</div>
                    <div class="character-stats">
                        <div class="stat-item">❤️ ${char.health}</div>
                        <div class="stat-item">🛡️ ${char.resistance}</div>
                        <div class="stat-item">⚡ ${char.superDefense}</div>
                        <div class="stat-item">⚔️ ${char.attack}</div>
                        <div class="stat-item">🎯 ${char.actionPoints}</div>
                    </div>
                    <div class="character-description">${char.description}</div>
                </div>
            `).join('')}
        </div>
    `);
}

function selectCharacter(characterId) {
    closeModal();
    loadArsenalCharacter();
    // Update character stats and equipment
}

// Friends Page Initialization
function initFriendsPage() {
    loadFriendsList();
    loadFriendRequests();
}

function loadFriendsList() {
    const friends = [
        { id: 1, name: 'Player1' },
        { id: 2, name: 'Gamer2024' },
        { id: 3, name: 'ProGamer' },
        { id: 4, name: 'NightHawk' },
        { id: 5, name: 'ShadowWolf' }
    ];
    
    const container = document.getElementById('friends-list');
    if (container) {
        container.innerHTML = friends.map(friend => `
            <div class="friend-item">
                <span class="friend-name">${friend.name}</span>
                <button class="invite-btn" onclick="inviteFriend(${friend.id})">Пригласить</button>
            </div>
        `).join('');
    }
}

function loadFriendRequests() {
    const requests = [
        { id: 1, name: 'NewPlayer1' },
        { id: 2, name: 'NewPlayer2' },
        { id: 3, name: 'NewPlayer3' }
    ];
    
    const container = document.getElementById('friend-requests');
    if (container) {
        container.innerHTML = requests.map(request => `
            <div class="friend-item">
                <span class="friend-name">${request.name}</span>
                <div>
                    <button class="accept-btn" onclick="acceptRequest(${request.id})">Принять</button>
                    <button class="reject-btn" onclick="rejectRequest(${request.id})">Удалить</button>
                </div>
            </div>
        `).join('');
    }
}

function inviteFriend(friendId) {
    console.log('Inviting friend:', friendId);
}

function acceptRequest(requestId) {
    console.log('Accepting request:', requestId);
}

function rejectRequest(requestId) {
    console.log('Rejecting request:', requestId);
}

// Chat Page Initialization
function initChatPage() {
    loadChatMessages();
}

function loadChatMessages() {
    const messages = [
        { author: 'Player1', text: 'Всем привет!' },
        { author: 'Gamer2024', text: 'Кто в рейд?' },
        { author: 'ProGamer', text: 'Ищу команду для PvP' },
        { author: 'NightHawk', text: 'Продам меч +5' },
        { author: 'ShadowWolf', text: 'Кто объяснит как пройти босса?' }
    ];
    
    const container = document.getElementById('chat-messages');
    if (container) {
        container.innerHTML = messages.map(msg => `
            <div class="chat-message">
                <div class="message-author">${msg.author}</div>
                <div>${msg.text}</div>
            </div>
        `).join('');
        container.scrollTop = container.scrollHeight;
    }
}

// Modal Functions
function showModal(content) {
    const modal = document.getElementById('modal-overlay');
    const modalContent = document.getElementById('modal-content');
    
    if (modal && modalContent) {
        modalContent.innerHTML = `
            <div class="modal-header">
                <h2>Выбор</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            ${content}
        `;
        modal.classList.add('active');
    }
}

function closeModal() {
    const modal = document.getElementById('modal-overlay');
    if (modal) {
        modal.classList.remove('active');
    }
}

// Auth Functions
function showLogin() {
    document.getElementById('login-form').classList.remove('hidden');
    document.getElementById('register-form').classList.add('hidden');
}

function showRegister() {
    document.getElementById('login-form').classList.add('hidden');
    document.getElementById('register-form').classList.remove('hidden');
}

function handleLogin(event) {
    event.preventDefault();
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;
    
    // Here you would make an API call to your backend
    console.log('Login attempt:', email);
    
    // Simulate successful login
    GameState.isAuthenticated = true;
    document.getElementById('auth-container').style.display = 'none';
    document.getElementById('game-container').style.display = 'block';
    
    updateUserInfo();
    navigateTo('main');
}

function handleRegister(event) {
    event.preventDefault();
    const nickname = document.getElementById('register-nickname').value;
    const email = document.getElementById('register-email').value;
    const password = document.getElementById('register-password').value;
    
    // Here you would make an API call to your backend
    console.log('Register attempt:', nickname, email);
    
    // Simulate successful registration
    GameState.userData.nickname = nickname;
    GameState.userData.email = email;
    GameState.isAuthenticated = true;
    document.getElementById('auth-container').style.display = 'none';
    document.getElementById('game-container').style.display = 'block';
    
    updateUserInfo();
    navigateTo('main');
}

function logout() {
    GameState.isAuthenticated = false;
    document.getElementById('game-container').style.display = 'none';
    document.getElementById('auth-container').style.display = 'flex';
    showLogin();
}

function updateUserInfo() {
    const userData = GameState.userData;
    
    document.getElementById('user-nickname').textContent = userData.nickname;
    document.getElementById('user-avatar').textContent = userData.avatar;
    document.getElementById('balance-amount').textContent = userData.balance.toLocaleString();
}

function saveProfile() {
    const nickname = document.getElementById('profile-nickname').value;
    const email = document.getElementById('profile-email').value;
    const currentPassword = document.getElementById('profile-current-password').value;
    const newPassword = document.getElementById('profile-new-password').value;
    
    // Validate current password and update profile
    console.log('Saving profile:', { nickname, email, currentPassword, newPassword });
    
    // Update local state
    GameState.userData.nickname = nickname;
    GameState.userData.email = email;
    
    updateUserInfo();
    alert('Профиль сохранен!');
}

function goBack() {
    navigateTo('main');
}

function openShopTab(tab) {
    // Remove active class from all tabs
    document.querySelectorAll('.shop-tab').forEach(t => t.classList.remove('active'));
    
    // Add active class to selected tab
    event.target.classList.add('active');
    
    // Load appropriate content
    if (tab === 'characters') {
        loadFactions();
    } else if (tab === 'items') {
        loadItems();
    }
}

function loadItems() {
    // Generate more than 8 items to demonstrate scrolling
    const items = [];
    for (let i = 1; i <= 20; i++) {
        items.push({
            id: i,
            name: `Предмет ${i}`,
            price: Math.floor(Math.random() * 1000) + 100,
            quantity: Math.floor(Math.random() * 10),
            description: `Игровой предмет номер ${i}`
        });
    }
    
    const container = document.getElementById('items-container');
    if (container) {
        container.innerHTML = `
            <div class="item-grid">
                ${items.map(item => `
                    <div class="item-card">
                        <div class="item-image">📦</div>
                        <div class="item-name">${item.name}</div>
                        <div class="item-quantity">В наличии: ${item.quantity}</div>
                        <div class="item-price">💰 ${item.price}</div>
                        <div class="item-description">${item.description}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }
}

function loadAbilities(socketIndex) {
    // Generate more than 8 abilities to demonstrate scrolling
    const abilities = [];
    for (let i = 1; i <= 15; i++) {
        abilities.push({
            id: i,
            name: `Способность ${i}`,
            damage: Math.floor(Math.random() * 100) + 10,
            cooldown: Math.floor(Math.random() * 5) + 1,
            description: `Боевая способность номер ${i}`
        });
    }
    
    showModal(`
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            ${abilities.map(ability => `
                <div class="character-card" onclick="equipAbility(${socketIndex}, ${ability.id})">
                    <div class="character-name">${ability.name}</div>
                    <div class="character-stats">
                        <div class="stat-item">Урон: ${ability.damage}</div>
                        <div class="stat-item">КД: ${ability.cooldown}s</div>
                    </div>
                    <div class="character-description">${ability.description}</div>
                </div>
            `).join('')}
        </div>
    `);
}

function equipAbility(socketIndex, abilityId) {
    closeModal();
    console.log('Equipping ability:', abilityId, 'to socket:', socketIndex);
}

function loadEquipment(socketIndex) {
    // Generate more than 8 items to demonstrate scrolling
    const items = [];
    for (let i = 1; i <= 15; i++) {
        items.push({
            id: i,
            name: `Экипировка ${i}`,
            bonus: Math.floor(Math.random() * 50) + 10,
            rarity: ['Обычный', 'Редкий', 'Эпический', 'Легендарный'][Math.floor(Math.random() * 4)],
            description: `Предмет экипировки номер ${i}`
        });
    }
    
    showModal(`
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            ${items.map(item => `
                <div class="character-card" onclick="equipItem(${socketIndex}, ${item.id})">
                    <div class="character-name">${item.name}</div>
                    <div class="character-stats">
                        <div class="stat-item">Бонус: +${item.bonus}</div>
                        <div class="stat-item">${item.rarity}</div>
                    </div>
                    <div class="character-description">${item.description}</div>
                </div>
            `).join('')}
        </div>
    `);
}

function equipItem(socketIndex, itemId) {
    closeModal();
    console.log('Equipping item:', itemId, 'to socket:', socketIndex);
}

// Search friends function
function searchFriends() {
    const searchTerm = document.getElementById('friend-search-input').value.toLowerCase();
    const friendItems = document.querySelectorAll('#friends-list .friend-item');
    
    friendItems.forEach(item => {
        const name = item.querySelector('.friend-name').textContent.toLowerCase();
        if (name.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Game interface loaded');
});
