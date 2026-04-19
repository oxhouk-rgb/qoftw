const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
const cors = require('cors');
const path = require('path');

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: "*" } });

app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, '../client')));

// DB Config
const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: '', // Set your MySQL password
    database: 'arena_game'
};

let db;

async function initDB() {
    try {
        db = await mysql.createConnection(dbConfig);
        console.log('MySQL Connected');
    } catch (err) {
        console.error('DB Connection Error:', err);
    }
}

initDB();

// ================= AUTH ROUTES =================

app.post('/api/register', async (req, res) => {
    const { username, password } = req.body;
    try {
        const hash = await bcrypt.hash(password, 10);
        const [result] = await db.execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            [username, hash]
        );
        
        // Add starter character
        const charId = result.insertId;
        // Get default character class ID (SniperDefault)
        const [classes] = await db.execute('SELECT id FROM character_classes WHERE name = ?', ['SniperDefault']);
        if (classes.length > 0) {
            await db.execute('INSERT INTO user_characters (user_id, class_id, is_active) VALUES (?, ?, TRUE)', [charId, classes[0].id]);
        }
        
        res.json({ success: true, userId: charId });
    } catch (err) {
        res.status(400).json({ error: err.message });
    }
});

app.post('/api/login', async (req, res) => {
    const { username, password } = req.body;
    try {
        const [users] = await db.execute('SELECT * FROM users WHERE username = ?', [username]);
        if (users.length === 0) return res.status(401).json({ error: 'User not found' });
        
        const user = users[0];
        // Special case for seeded players with plain text '0000'
        let valid = false;
        if (user.password_hash === '0000') {
            valid = password === '0000';
        } else {
            valid = await bcrypt.compare(password, user.password_hash);
        }
        
        if (!valid) return res.status(401).json({ error: 'Invalid password' });
        
        res.json({ 
            success: true, 
            user: { id: user.id, username: user.username, currency: user.currency, rating: user.rating } 
        });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// ================= USER DATA ROUTES =================

app.get('/api/user/:id/profile', async (req, res) => {
    try {
        const [characters] = await db.execute(`
            SELECT uc.*, cc.name as class_name, cc.faction, cc.base_hp, cc.base_resistance, 
                   cc.base_overguard, cc.base_damage, cc.base_ap, cc.base_range, cc.attack_animation, cc.sprite_path
            FROM user_characters uc
            JOIN character_classes cc ON uc.class_id = cc.id
            WHERE uc.user_id = ?
        `, [req.params.id]);
        
        const [inventory] = await db.execute(`
            SELECT ui.*, i.name, i.description, i.bonus_hp, i.bonus_resistance, i.bonus_overguard, 
                   i.bonus_damage, i.bonus_ap, i.bonus_range, i.special_effect, i.icon_path
            FROM user_inventory ui
            JOIN items i ON ui.item_id = i.id
            WHERE ui.user_id = ?
        `, [req.params.id]);
        
        const [friends] = await db.execute(`
            SELECT u.id, u.username, f.status
            FROM friends f
            JOIN users u ON f.friend_id = u.id
            WHERE f.user_id = ?
        `, [req.params.id]);
        
        const [abilities] = await db.execute(`
            SELECT ca.slot_index, a.*
            FROM character_abilities ca
            JOIN abilities a ON ca.ability_id = a.id
            WHERE ca.character_id IN (SELECT id FROM user_characters WHERE user_id = ? AND is_active = TRUE)
        `, [req.params.id]);
        
        res.json({ characters, inventory, friends, abilities });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.post('/api/user/:id/equip', async (req, res) => {
    const { itemId, slot } = req.body;
    // Logic to equip item (simplified - in full version track equipped items separately)
    res.json({ success: true });
});

app.post('/api/user/:id/abilities', async (req, res) => {
    const { characterId, abilityIds } = req.body; // [id1, id2] for slots 2 and 3
    try {
        await db.execute('DELETE FROM character_abilities WHERE character_id = ?', [characterId]);
        
        // Slot 0 & 1 are default, add customs
        for (let i = 0; i < abilityIds.length; i++) {
            await db.execute('INSERT INTO character_abilities (character_id, ability_id, slot_index) VALUES (?, ?, ?)',
                [characterId, abilityIds[i], i + 2]);
        }
        res.json({ success: true });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/api/shop/characters', async (req, res) => {
    try {
        const [chars] = await db.execute('SELECT * FROM character_classes');
        res.json(chars);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.get('/api/shop/items', async (req, res) => {
    try {
        const [items] = await db.execute('SELECT * FROM items');
        res.json(items);
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

app.post('/api/user/:id/buy-character', async (req, res) => {
    const { classId } = req.body;
    try {
        const [user] = await db.execute('SELECT currency FROM users WHERE id = ?', [req.params.id]);
        const [charClass] = await db.execute('SELECT * FROM character_classes WHERE id = ?', [classId]);
        
        if (user[0].currency >= 1000) { // Default price
            await db.execute('UPDATE users SET currency = currency - 1000 WHERE id = ?', [req.params.id]);
            await db.execute('INSERT INTO user_characters (user_id, class_id) VALUES (?, ?)', [req.params.id, classId]);
            res.json({ success: true });
        } else {
            res.status(400).json({ error: 'Not enough currency' });
        }
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// ================= GAME SERVER LOGIC =================

const rooms = {}; // Map of room sessions
const matchmakingQueue = [];

io.on('connection', (socket) => {
    console.log('Client connected:', socket.id);
    
    socket.on('join-lobby', async (data) => {
        const { userId, mode } = data;
        // Add to queue or create lobby
        matchmakingQueue.push({ socket, userId, mode });
        
        if (matchmakingQueue.length >= 4 && mode === 'FFA') {
            startMatch(matchmakingQueue.splice(0, 4));
        }
    });
    
    socket.on('invite-friend', (data) => {
        const { fromUserId, toUserId } = data;
        io.to(`user_${toUserId}`).emit('friend-invite', { fromUserId });
    });
    
    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);
    });
});

async function startMatch(players) {
    const gameId = 'game_' + Date.now();
    const gameRoom = {
        id: gameId,
        players: players.map(p => ({ socket: p.socket, userId: p.userId, ready: false })),
        map: generateMap(),
        state: 'waiting'
    };
    
    rooms[gameId] = gameRoom;
    
    players.forEach(p => {
        p.socket.join(gameId);
        p.socket.emit('match-found', { gameId });
    });
}

function generateMap() {
    // 5x5 grid of rooms, each room 6x15 cells
    const map = [];
    for (let y = 0; y < 5; y++) {
        for (let x = 0; x < 5; x++) {
            const room = {
                id: `room_${x}_${y}`,
                coords: { x, y },
                cells: [],
                objects: []
            };
            
            // Generate 6x15 grid
            for (let cy = 0; cy < 6; cy++) {
                for (let cx = 0; cx < 15; cx++) {
                    room.cells.push({ x: cx, y: cy, type: 'floor' });
                }
            }
            
            // Add portals
            if (x < 4) room.objects.push({ type: 'portal', x: 14, y: 2, target: { x: x+1, y } });
            if (x > 0) room.objects.push({ type: 'portal', x: 0, y: 2, target: { x: x-1, y } });
            if (y < 4) room.objects.push({ type: 'portal', x: 7, y: 5, target: { x, y: y+1 } });
            if (y > 0) room.objects.push({ type: 'portal', x: 7, y: 0, target: { x, y: y-1 } });
            
            // Exit in center
            if (x === 2 && y === 2) {
                room.objects.push({ type: 'EXIT', x: 7, y: 3, id: 'exit' });
            }
            
            // Random obstacles
            if (Math.random() > 0.7) {
                room.objects.push({ type: 'wall', x: 5, y: 3, hp: 999 });
            }
            
            map.push(room);
        }
    }
    return map;
}

// Battle handling
io.on('connection', (socket) => {
    socket.on('battle-action', (data) => {
        const { gameId, playerId, action } = data;
        const room = rooms[gameId];
        if (!room) return;
        
        // Queue action
        if (!room.pendingActions) room.pendingActions = [];
        room.pendingActions.push({ playerId, action });
        
        // Check if all ready
        if (room.pendingActions.length >= room.players.length) {
            resolveTurn(room);
        }
    });
});

function resolveTurn(room) {
    // Process actions in order
    const results = [];
    room.pendingActions.forEach(act => {
        // Validate and apply
        results.push({ playerId: act.playerId, success: true });
    });
    
    room.pendingActions = [];
    
    // Broadcast results
    io.to(room.id).emit('turn-result', { results });
}

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});
