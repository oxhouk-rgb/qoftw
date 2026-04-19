# Arena 4-Player Browser Game

## Installation & Setup

### 1. Database Setup (MySQL)

1. Install MySQL if not already installed
2. Run the schema file:
```bash
mysql -u root -p < database/schema.sql
```
3. Update `server/server.js` with your MySQL credentials:
```javascript
const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: 'YOUR_PASSWORD',
    database: 'arena_game'
};
```

### 2. Install Node.js Dependencies

```bash
npm install
```

### 3. Start Server

```bash
npm start
```

Server will run on http://localhost:3000

## Test Accounts

Pre-created accounts for testing:
- **Username**: Player1, Player2, Player3, Player4
- **Password**: 0000 (for all)

All test accounts are already friends with each other.

## Project Structure

```
/workspace
├── database/
│   └── schema.sql          # MySQL database schema and seed data
├── server/
│   └── server.js           # Node.js + Express + Socket.io backend
├── client/
│   ├── index.html          # Main HTML file
│   ├── css/
│   │   └── style.css       # Game styles
│   ├── js/
│   │   └── game.js         # Frontend game logic
│   └── assets/
│       ├── characters/     # Character sprites by faction
│       │   ├── human/
│       │   ├── bio/
│       │   ├── robot/
│       │   └── alien/
│       ├── abilities/
│       │   ├── icons/      # 64x64 ability icons
│       │   └── anim/       # 128x128 ability animations
│       ├── items/          # 64x64 item icons
│       └── attack_anim/    # Attack animation sprites
└── package.json
```

## Game Features

### Authentication
- Login/Register system
- Test accounts pre-configured

### Main Menu
- Arsenal (character management)
- Shop (buy characters/items)
- Friends list
- Game lobby

### Battle System
- 5x5 grid of rooms (each 6x15 cells)
- Free movement mode (when alone or with allies)
- Turn-based mode (when enemies present)
- 10-second turn timer
- Action Points (AP) system
- Portals between rooms
- EXIT objective in center room

### Characters
- 4 factions: Human, Bio, Robot, Alien
- Starter character: SniperDefault
- Stats: HP, Resistance, Overguard, Damage, AP, Range
- 6 animation types: idle, attack, move, base_abilities, class_ability, death

### Abilities
- Auto-attack
- Class ability
- 2 custom abilities
- Types: projectile, beam, AOE, instant, melee, stationary

### Items (5 equipment slots)
- Basic Armor (+20% resistance)
- Armor Piercing (50% damage ignores resistance)
- Regenerator (heal 1 HP per turn)
- Defender (survive lethal hit with 1 HP)
- Speed Mod (+1 AP)

### Game Modes
- FFA (4 players)
- 2v2 (4 players)
- PVE (1-2 players)
- Custom Game (with friends)

## Adding Graphics

Place your sprite files in the appropriate folders:

**Characters**: `client/assets/characters/[faction]/[CharacterName][Skin].png`
- Format: 128x128 per frame
- Layout: 8 frames horizontally, 6 rows (idle, attack, move, base_abilities, class_ability, death)

**Abilities**: 
- Icons: `client/assets/abilities/icons/[ability_name].png` (64x64)
- Animations: `client/assets/abilities/anim/[ability_name]ability.png` (128x128, 8 frames)

**Items**: `client/assets/items/[item_name].png` (64x64)

**Attack Animations**: `client/assets/attack_anim/[CharacterName]Attack.png` (128x128, 8 frames)

## Extending the Game

### Add New Character
1. Insert into `character_classes` table
2. Add sprite to `client/assets/characters/[faction]/`

### Add New Ability
1. Insert into `abilities` table
2. Add icon and animation assets
3. Implement effect logic in `server/server.js` resolveTurn function

### Add New Item
1. Insert into `items` table
2. Add icon asset
3. Implement special effect in battle calculation logic

## Notes

- All damage calculations round up to nearest integer
- Minimum damage is always 1
- Resistance capped at 90%
- Sprites face right by default, flip horizontally when moving left
- No camera movement needed - each room fits on screen
