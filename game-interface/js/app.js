// Game Interface Application Logic
const app = {
    // State
    currentUser: null,
    currentScreen: 'auth',
    navigationHistory: [],
    
    // Mock Data
    mockData: {
        user: {
            nickname: 'Player1',
            email: 'player@example.com',
            avatar: 'P',
            balance: 50000,
            wins: 42,
            losses: 18,
            rank: 750,
            premium: true
        },
        friends: [
            { id: 1, name: 'Friend1', online: true },
            { id: 2, name: 'Friend2', online: false },
            { id: 3, name: 'Friend3', online: true },
            { id: 4, name: 'Friend4', online: false },
            { id: 5, name: 'Friend5', online: true }
        ],
        friendRequests: [
            { id: 6, name: 'Requester1' },
            { id: 7, name: 'Requester2' }
        ],
        factions: [
            { id: 1, name: 'Фракция 1', desc: 'Описание первой фракции' },
            { id: 2, name: 'Фракция 2', desc: 'Описание второй фракции' },
            { id: 3, name: 'Фракция 3', desc: 'Описание третьей фракции' },
            { id: 4, name: 'Фракция 4', desc: 'Описание четвертой фракции' }
        ],
        characters: {
            1: [
                { id: 1, name: 'Воин 1', price: 1000, health: 8, resistance: 5, superDefense: 6, attack: 7, actionPoints: 2, desc: 'Сильный воин ближнего боя' },
                { id: 2, name: 'Воин 2', price: 1200, health: 9, resistance: 4, superDefense: 5, attack: 8, actionPoints: 2, desc: 'Защищенный боец' },
                { id: 3, name: 'Воин 3', price: 1500, health: 7, resistance: 6, superDefense: 7, attack: 9, actionPoints: 3, desc: 'Баланс между атакой и защитой' },
                { id: 4, name: 'Воин 4', price: 2000, health: 10, resistance: 7, superDefense: 8, attack: 6, actionPoints: 2, desc: 'Танк с высоким здоровьем' },
                { id: 5, name: 'Воин 5', price: 1800, health: 6, resistance: 5, superDefense: 5, attack: 10, actionPoints: 3, desc: 'Урон в обмен на защиту' },
                { id: 6, name: 'Воин 6', price: 2200, health: 8, resistance: 8, superDefense: 9, attack: 7, actionPoints: 2, desc: 'Хорошо защищенный' }
            ],
            2: [
                { id: 7, name: 'Маг 1', price: 1100, health: 5, resistance: 8, superDefense: 4, attack: 9, actionPoints: 3, desc: 'Мастер стихий' },
                { id: 8, name: 'Маг 2', price: 1300, health: 4, resistance: 9, superDefense: 5, attack: 10, actionPoints: 3, desc: 'Разрушительная сила' },
                { id: 9, name: 'Маг 3', price: 1600, health: 6, resistance: 7, superDefense: 6, attack: 8, actionPoints: 2, desc: 'Поддерживающий маг' },
                { id: 10, name: 'Маг 4', price: 1900, health: 5, resistance: 8, superDefense: 7, attack: 9, actionPoints: 3, desc: 'Боевой маг' },
                { id: 11, name: 'Маг 5', price: 2100, health: 7, resistance: 9, superDefense: 8, attack: 7, actionPoints: 2, desc: 'Защитник команды' },
                { id: 12, name: 'Маг 6', price: 2400, health: 4, resistance: 10, superDefense: 6, attack: 10, actionPoints: 3, desc: 'Стеклянная пушка' }
            ],
            3: [
                { id: 13, name: 'Лучник 1', price: 1050, health: 6, resistance: 4, superDefense: 3, attack: 8, actionPoints: 3, desc: 'Мастер дальнего боя' },
                { id: 14, name: 'Лучник 2', price: 1250, health: 5, resistance: 5, superDefense: 4, attack: 9, actionPoints: 3, desc: 'Снайпер' },
                { id: 15, name: 'Лучник 3', price: 1550, health: 7, resistance: 6, superDefense: 5, attack: 7, actionPoints: 2, desc: 'Тактик' },
                { id: 16, name: 'Лучник 4', price: 1850, health: 6, resistance: 5, superDefense: 6, attack: 10, actionPoints: 3, desc: 'Ассасин' },
                { id: 17, name: 'Лучник 5', price: 2050, health: 8, resistance: 7, superDefense: 5, attack: 8, actionPoints: 2, desc: 'Выживший' },
                { id: 18, name: 'Лучник 6', price: 2350, health: 5, resistance: 6, superDefense: 7, attack: 9, actionPoints: 3, desc: 'Призрачный стрелок' }
            ],
            4: [
                { id: 19, name: 'Целитель 1', price: 1150, health: 7, resistance: 7, superDefense: 8, attack: 4, actionPoints: 2, desc: 'Поддержка команды' },
                { id: 20, name: 'Целитель 2', price: 1350, health: 8, resistance: 8, superDefense: 9, attack: 3, actionPoints: 2, desc: 'Мастер исцеления' },
                { id: 21, name: 'Целитель 3', price: 1650, health: 6, resistance: 9, superDefense: 10, attack: 5, actionPoints: 3, desc: 'Боевой лекарь' },
                { id: 22, name: 'Целитель 4', price: 1950, health: 9, resistance: 7, superDefense: 8, attack: 4, actionPoints: 2, desc: 'Защитник жизни' },
                { id: 23, name: 'Целитель 5', price: 2150, health: 7, resistance: 10, superDefense: 9, attack: 6, actionPoints: 3, desc: 'Духовный наставник' },
                { id: 24, name: 'Целитель 6', price: 2450, health: 10, resistance: 9, superDefense: 10, attack: 3, actionPoints: 2, desc: 'Неуязвимый' }
            ]
        },
        items: [
            { id: 1, name: 'Меч', price: 500, desc: 'Обычный меч', quantity: 2 },
            { id: 2, name: 'Щит', price: 400, desc: 'Деревянный щит', quantity: 1 },
            { id: 3, name: 'Зелье здоровья', price: 100, desc: 'Восстанавливает 50 HP', quantity: 5 },
            { id: 4, name: 'Кольцо силы', price: 800, desc: '+2 к атаке', quantity: 0 },
            { id: 5, name: 'Амулет защиты', price: 750, desc: '+2 к сопротивлению', quantity: 1 },
            { id: 6, name: 'Сапоги скорости', price: 600, desc: '+1 к очкам хода', quantity: 0 },
            { id: 7, name: 'Шлем', price: 450, desc: 'Защита головы', quantity: 1 },
            { id: 8, name: 'Перчатки', price: 350, desc: 'Защита рук', quantity: 2 },
            { id: 9, name: 'Кольчуга', price: 900, desc: 'Хорошая броня', quantity: 0 },
            { id: 10, name: 'Плащ', price: 550, desc: 'Магическая защита', quantity: 1 },
            { id: 11, name: 'Кинжал', price: 480, desc: 'Быстрое оружие', quantity: 3 },
            { id: 12, name: 'Посох', price: 700, desc: 'Для магов', quantity: 0 },
            { id: 13, name: 'Зелье маны', price: 120, desc: 'Восстанавливает ману', quantity: 4 },
            { id: 14, name: 'Свиток телепортации', price: 200, desc: 'Мгновенный выход', quantity: 2 },
            { id: 15, name: 'Камень усиления', price: 1000, desc: 'Улучшает предмет', quantity: 0 }
        ],
        abilities: [
            { id: 1, name: 'Огненный шар', desc: 'Наносит урон огнем' },
            { id: 2, name: 'Ледяная стрела', desc: 'Замораживает врага' },
            { id: 3, name: 'Лечение', desc: 'Восстанавливает здоровье' },
            { id: 4, name: 'Щит', desc: 'Повышает защиту' },
            { id: 5, name: 'Удар молнии', desc: 'Быстрая атака' },
            { id: 6, name: 'Ядовитое облако', desc: 'Отравляет врагов' },
            { id: 7, name: 'Телепорт', desc: 'Перемещение' },
            { id: 8, name: 'Невидимость', desc: 'Скрывает от врагов' },
            { id: 9, name: 'Массовое лечение', desc: 'Лечит всех союзников' },
            { id: 10, name: 'Гнев', desc: 'Временно повышает атаку' },
            { id: 11, name: 'Барьер', desc: 'Поглощает урон' },
            { id: 12, name: 'Критический удар', desc: 'Шанс крита +50%' }
        ],
        ownedCharacters: [
            { id: 1, name: 'Воин 1', health: 8, resistance: 5, superDefense: 6, attack: 7, actionPoints: 2, desc: 'Сильный воин ближнего боя' },
            { id: 7, name: 'Маг 1', health: 5, resistance: 8, superDefense: 4, attack: 9, actionPoints: 3, desc: 'Мастер стихий' }
        ]
    },

    // Initialize
    init: function() {
        this.loadUserData();
        this.updateUI();
    },

    // Navigation
    navigate: function(screenName) {
        if (this.currentScreen !== screenName) {
            this.navigationHistory.push(this.currentScreen);
            this.showScreen(screenName);
        }
    },

    goBack: function() {
        if (this.navigationHistory.length > 0) {
            const prevScreen = this.navigationHistory.pop();
            this.showScreen(prevScreen);
        } else {
            this.showScreen('main');
        }
    },

    showScreen: function(screenName) {
        // Hide all screens
        document.querySelectorAll('.screen').forEach(screen => {
            screen.classList.remove('active');
        });

        // Show target screen
        const targetScreen = document.getElementById(screenName + 'Screen');
        if (targetScreen) {
            targetScreen.classList.add('active');
        }

        this.currentScreen = screenName;
        this.updateMenuButtons();
        
        // Load screen-specific data
        if (screenName === 'friends') {
            this.loadFriendsList();
        } else if (screenName === 'profile') {
            this.loadProfileData();
        }
    },

    updateMenuButtons: function() {
        const userInfo = document.getElementById('userInfo');
        const menuCenter = document.getElementById('menuCenter');
        const logoutBtn = document.getElementById('logoutBtn');
        const backBtn = document.getElementById('backBtn');
        const saveBtn = document.getElementById('saveBtn');

        // Reset all buttons
        userInfo.style.display = 'none';
        menuCenter.style.display = 'none';
        logoutBtn.style.display = 'none';
        backBtn.style.display = 'none';
        saveBtn.style.display = 'none';

        if (!this.currentUser) {
            // Auth screens - no menu
            return;
        }

        // Show user info on main screen and sub-screens
        if (['main', 'profile', 'arsenal', 'shop', 'friends', 'chat'].includes(this.currentScreen)) {
            userInfo.style.display = 'flex';
        }

        // Show menu center only on main screen
        if (this.currentScreen === 'main') {
            menuCenter.style.display = 'flex';
            logoutBtn.style.display = 'block';
        }

        // Show back button on sub-screens
        if (['profile', 'arsenal', 'shop', 'friends', 'chat'].includes(this.currentScreen)) {
            backBtn.style.display = 'block';
        }

        // Show save button only on profile screen
        if (this.currentScreen === 'profile') {
            saveBtn.style.display = 'block';
        }
    },

    // Authentication
    login: function() {
        const email = document.getElementById('authEmail').value;
        const password = document.getElementById('authPassword').value;
        
        // TODO: Replace with actual backend call
        console.log('Login attempt:', email);
        
        // Mock login
        this.currentUser = this.mockData.user;
        this.saveUserData();
        this.updateUI();
        this.showScreen('main');
    },

    register: function() {
        const nickname = document.getElementById('regNickname').value;
        const email = document.getElementById('regEmail').value;
        const password = document.getElementById('regPassword').value;
        
        // TODO: Replace with actual backend call
        console.log('Register attempt:', nickname, email);
        
        // Mock registration
        this.currentUser = {
            nickname: nickname,
            email: email,
            avatar: nickname[0].toUpperCase(),
            balance: 1000,
            wins: 0,
            losses: 0,
            rank: 0,
            premium: false
        };
        this.saveUserData();
        this.updateUI();
        this.showScreen('main');
    },

    logout: function() {
        this.currentUser = null;
        this.navigationHistory = [];
        localStorage.removeItem('gameUser');
        this.updateUI();
        this.showScreen('auth');
    },

    // User Data Management
    saveUserData: function() {
        if (this.currentUser) {
            localStorage.setItem('gameUser', JSON.stringify(this.currentUser));
        }
    },

    loadUserData: function() {
        const saved = localStorage.getItem('gameUser');
        if (saved) {
            this.currentUser = JSON.parse(saved);
        }
    },

    updateUI: function() {
        if (this.currentUser) {
            document.getElementById('userAvatar').textContent = this.currentUser.avatar || 'U';
            document.getElementById('userNickname').textContent = this.currentUser.nickname || 'User';
            document.getElementById('userBalance').textContent = this.currentUser.balance?.toLocaleString() || '0';
            
            // Navigate to main screen if logged in
            if (this.currentScreen === 'auth' || this.currentScreen === 'register') {
                this.showScreen('main');
            }
        } else {
            this.showScreen('auth');
        }
    },

    // Profile
    loadProfileData: function() {
        if (this.currentUser) {
            document.getElementById('profileAvatar').value = this.currentUser.avatar || '';
            document.getElementById('profileNickname').value = this.currentUser.nickname || '';
            document.getElementById('profileEmail').value = this.currentUser.email || '';
            
            document.getElementById('statsWins').textContent = this.currentUser.wins || 0;
            document.getElementById('statsLosses').textContent = this.currentUser.losses || 0;
            document.getElementById('playerRank').textContent = this.currentUser.rank || 0;
            document.getElementById('premiumStatus').textContent = this.currentUser.premium ? 'Да' : 'Нет';
        }
    },

    saveProfile: function() {
        const currentPassword = document.getElementById('currentPassword').value;
        
        // TODO: Replace with actual backend call
        console.log('Save profile with current password:', currentPassword);
        
        // Update local data
        if (this.currentUser) {
            this.currentUser.avatar = document.getElementById('profileAvatar').value || 'U';
            this.currentUser.nickname = document.getElementById('profileNickname').value;
            this.currentUser.email = document.getElementById('profileEmail').value;
            
            this.saveUserData();
            this.updateUI();
            alert('Профиль сохранен!');
        }
    },

    // Shop
    showShopSection: function(section) {
        const factionsGrid = document.getElementById('factionsGrid');
        const shopList = document.getElementById('shopList');
        
        if (section === 'characters') {
            factionsGrid.style.display = 'grid';
            shopList.style.display = 'none';
        } else if (section === 'items') {
            factionsGrid.style.display = 'none';
            shopList.style.display = 'block';
            this.renderItemsList();
        } else if (section === 'currency') {
            factionsGrid.style.display = 'none';
            shopList.style.display = 'block';
            shopList.innerHTML = '<div style="padding: 20px; text-align: center;"><h3>Покупка валюты</h3><p>Здесь будут пакеты валюты</p></div>';
        }
    },

    showFactionCharacters: function(factionId) {
        const factionsGrid = document.getElementById('factionsGrid');
        const shopList = document.getElementById('shopList');
        
        factionsGrid.style.display = 'none';
        shopList.style.display = 'block';
        
        const characters = this.mockData.characters[factionId] || [];
        this.renderCharactersList(characters);
    },

    renderCharactersList: function(characters) {
        const shopList = document.getElementById('shopList');
        shopList.innerHTML = characters.map(char => `
            <div class="character-card">
                <div class="character-image">👤</div>
                <div class="character-name">${char.name}</div>
                <div class="character-stats">
                    HP: ${char.health} | RES: ${char.resistance} | ATK: ${char.attack}
                </div>
                <div class="price-tag">${char.price.toLocaleString()} $</div>
                <div style="font-size: 12px; opacity: 0.8;">${char.desc}</div>
            </div>
        `).join('');
    },

    renderItemsList: function() {
        const shopList = document.getElementById('shopList');
        shopList.innerHTML = this.mockData.items.map(item => `
            <div class="item-card">
                <div class="item-image">📦</div>
                <div class="item-name">${item.name}</div>
                <div class="item-desc">${item.desc}</div>
                <div class="price-tag">${item.price.toLocaleString()} $</div>
                <div class="quantity-indicator">В наличии: ${item.quantity}</div>
            </div>
        `).join('');
    },

    // Arsenal
    openOwnedCharacters: function() {
        const modalBody = document.getElementById('modalBody');
        modalBody.innerHTML = '<div class="scrollable-list">' + this.mockData.ownedCharacters.map(char => `
            <div class="character-card" onclick="app.selectCharacter(${char.id})">
                <div class="character-image">👤</div>
                <div class="character-name">${char.name}</div>
                <div class="character-stats">
                    HP: ${char.health} | RES: ${char.resistance} | ATK: ${char.attack}
                </div>
            </div>
        `).join('') + '</div>';
        
        document.getElementById('modalTitle').textContent = 'Выберите персонажа';
        document.getElementById('modalOverlay').classList.add('active');
    },

    selectCharacter: function(charId) {
        const character = this.mockData.ownedCharacters.find(c => c.id === charId);
        if (character) {
            const display = document.getElementById('characterDisplay');
            display.classList.add('has-character');
            display.innerHTML = `<div class="character-display-img" style="font-size: 80px;">👤</div>`;
            
            document.getElementById('charHealth').textContent = character.health;
            document.getElementById('charResistance').textContent = character.resistance;
            document.getElementById('charSuperDefense').textContent = character.superDefense;
            document.getElementById('charAttack').textContent = character.attack;
            document.getElementById('charActionPoints').textContent = character.actionPoints;
            document.getElementById('characterDescription').textContent = character.desc;
        }
        this.closeModal();
    },

    openAbilitySelector: function(slotIndex) {
        const modalBody = document.getElementById('modalBody');
        modalBody.innerHTML = '<div class="scrollable-list">' + this.mockData.abilities.map(ability => `
            <div class="character-card" onclick="app.selectAbility(${slotIndex}, ${ability.id})">
                <div class="character-name">${ability.name}</div>
                <div class="character-stats">${ability.desc}</div>
            </div>
        `).join('') + '</div>';
        
        document.getElementById('modalTitle').textContent = 'Выберите способность';
        document.getElementById('modalOverlay').classList.add('active');
    },

    selectAbility: function(slotIndex, abilityId) {
        // TODO: Implement ability selection logic
        console.log('Select ability', abilityId, 'for slot', slotIndex);
        this.closeModal();
    },

    openItemSelector: function(slotIndex) {
        const modalBody = document.getElementById('modalBody');
        modalBody.innerHTML = '<div class="scrollable-list">' + this.mockData.items.map(item => `
            <div class="item-card" onclick="app.selectArsenalItem(${slotIndex}, ${item.id})">
                <div class="item-name">${item.name}</div>
                <div class="item-desc">${item.desc}</div>
                <div class="quantity-indicator">В наличии: ${item.quantity}</div>
            </div>
        `).join('') + '</div>';
        
        document.getElementById('modalTitle').textContent = 'Выберите предмет';
        document.getElementById('modalOverlay').classList.add('active');
    },

    selectArsenalItem: function(slotIndex, itemId) {
        // TODO: Implement item selection logic
        console.log('Select item', itemId, 'for slot', slotIndex);
        this.closeModal();
    },

    closeModal: function() {
        document.getElementById('modalOverlay').classList.remove('active');
    },

    // Friends
    loadFriendsList: function() {
        const friendsContent = document.getElementById('friendsListContent');
        const requestsContent = document.getElementById('friendRequestsContent');
        
        friendsContent.innerHTML = this.mockData.friends.map(friend => `
            <div class="friend-item">
                <span>${friend.name} ${friend.online ? '🟢' : '⚫'}</span>
                <button class="btn invite-btn" onclick="app.inviteFriend(${friend.id})">Пригласить</button>
            </div>
        `).join('');
        
        requestsContent.innerHTML = this.mockData.friendRequests.map(request => `
            <div class="request-item">
                <span>${request.name}</span>
                <div>
                    <button class="btn accept-btn" onclick="app.acceptRequest(${request.id})">Принять</button>
                    <button class="btn remove-btn" onclick="app.removeRequest(${request.id})">Удалить</button>
                </div>
            </div>
        `).join('');
    },

    searchFriends: function() {
        const searchTerm = document.getElementById('friendSearch').value.toLowerCase();
        const filtered = this.mockData.friends.filter(f => 
            f.name.toLowerCase().includes(searchTerm)
        );
        
        const friendsContent = document.getElementById('friendsListContent');
        friendsContent.innerHTML = filtered.map(friend => `
            <div class="friend-item">
                <span>${friend.name} ${friend.online ? '🟢' : '⚫'}</span>
                <button class="btn invite-btn" onclick="app.inviteFriend(${friend.id})">Пригласить</button>
            </div>
        `).join('');
    },

    inviteFriend: function(friendId) {
        console.log('Invite friend', friendId);
        // TODO: Replace with actual backend call
    },

    acceptRequest: function(requestId) {
        console.log('Accept request', requestId);
        // TODO: Replace with actual backend call
    },

    removeRequest: function(requestId) {
        console.log('Remove request', requestId);
        // TODO: Replace with actual backend call
    },

    // Chat
    sendMessage: function() {
        const input = document.getElementById('chatInput');
        const message = input.value.trim();
        
        if (message) {
            const chatMessages = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-message';
            messageDiv.innerHTML = `
                <div class="message-author">${this.currentUser?.nickname || 'Игрок'}</div>
                <div class="message-text">${message}</div>
            `;
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            input.value = '';
            
            // TODO: Replace with actual backend call
            console.log('Send message:', message);
        }
    },

    // Game Modes
    startGame: function(mode) {
        console.log('Start game mode:', mode);
        // TODO: Replace with actual backend call
        alert('Запуск режима: ' + mode);
    }
};

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    app.init();
});
