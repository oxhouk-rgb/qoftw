-- database/schema.sql

CREATE DATABASE IF NOT EXISTS arena_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE arena_game;

-- Таблица пользователей
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    currency INT DEFAULT 1000,
    rating INT DEFAULT 1000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица персонажей (классы)
CREATE TABLE character_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    faction VARCHAR(20) NOT NULL,
    base_hp INT DEFAULT 5,
    base_resistance INT DEFAULT 0,
    base_overguard INT DEFAULT 0,
    base_damage INT DEFAULT 5,
    base_ap INT DEFAULT 2,
    base_range INT DEFAULT 5,
    attack_animation VARCHAR(50) DEFAULT 'projectile',
    sprite_path VARCHAR(255) DEFAULT 'SniperDefault.png'
);

-- Таблица способностей
CREATE TABLE abilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    type ENUM('active', 'passive') DEFAULT 'active',
    damage_multiplier FLOAT DEFAULT 1.0,
    ap_cost INT DEFAULT 1,
    range_override INT DEFAULT NULL,
    ability_animation VARCHAR(50) DEFAULT 'no effect',
    effect_type VARCHAR(50) DEFAULT 'damage',
    effect_value INT DEFAULT 0,
    duration INT DEFAULT 0,
    splash BOOLEAN DEFAULT FALSE,
    icon_path VARCHAR(255) DEFAULT 'default.png'
);

-- Таблица предметов
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    type ENUM('armor', 'weapon', 'consumable', 'accessory') DEFAULT 'accessory',
    bonus_hp INT DEFAULT 0,
    bonus_resistance INT DEFAULT 0,
    bonus_overguard INT DEFAULT 0,
    bonus_damage INT DEFAULT 0,
    bonus_ap INT DEFAULT 0,
    bonus_range INT DEFAULT 0,
    special_effect VARCHAR(50) DEFAULT NULL,
    icon_path VARCHAR(255) DEFAULT 'default.png',
    is_consumable BOOLEAN DEFAULT FALSE
);

-- Инвентарь пользователя
CREATE TABLE user_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity INT DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

-- Связь пользователь-персонаж
CREATE TABLE user_characters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    class_id INT NOT NULL,
    skin_name VARCHAR(50) DEFAULT 'Default',
    is_active BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES character_classes(id) ON DELETE CASCADE
);

-- Способности персонажа
CREATE TABLE character_abilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    character_id INT NOT NULL,
    ability_id INT NOT NULL,
    slot_index INT NOT NULL,
    FOREIGN KEY (character_id) REFERENCES user_characters(id) ON DELETE CASCADE,
    FOREIGN KEY (ability_id) REFERENCES abilities(id) ON DELETE CASCADE
);

-- Друзья
CREATE TABLE friends (
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    status ENUM('online', 'offline') DEFAULT 'offline',
    PRIMARY KEY (user_id, friend_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ================= DATA SEEDING =================

INSERT INTO users (username, password_hash, currency, rating) VALUES
('Player1', '0000', 5000, 1200),
('Player2', '0000', 5000, 1150),
('Player3', '0000', 5000, 1100),
('Player4', '0000', 5000, 1050);

INSERT INTO friends (user_id, friend_id, status) VALUES
(1, 2, 'offline'), (1, 3, 'offline'), (1, 4, 'offline'),
(2, 1, 'offline'), (2, 3, 'offline'), (2, 4, 'offline'),
(3, 1, 'offline'), (3, 2, 'offline'), (3, 4, 'offline'),
(4, 1, 'offline'), (4, 2, 'offline'), (4, 3, 'offline');

INSERT INTO character_classes (name, faction, base_hp, base_resistance, base_overguard, base_damage, base_ap, base_range, attack_animation, sprite_path) VALUES
('SniperDefault', 'human', 5, 30, 10, 5, 2, 5, 'projectile', 'SniperDefault.png'),
('TankBot', 'robot', 8, 50, 0, 3, 2, 3, 'melee', 'TankBot.png'),
('AlienScout', 'alien', 4, 10, 5, 6, 3, 6, 'instant', 'AlienScout.png'),
('BioMedic', 'bio', 6, 20, 0, 4, 2, 4, 'beam', 'BioMedic.png');

INSERT INTO abilities (name, description, type, ap_cost, ability_animation, effect_type, effect_value, duration, splash, icon_path) VALUES
('Build Wall', 'Creates a destructible wall', 'active', 1, 'instant', 'create_wall', 5, 0, FALSE, 'wall.png'),
('Place Mine', 'Places an invisible mine', 'active', 1, 'instant', 'create_mine', 4, 0, TRUE, 'mine.png'),
('Sniper Shot', 'Double damage shot', 'active', 2.0, 2, 'projectile', 'damage', 0, FALSE, 'sniper_shot.png');

INSERT INTO items (name, description, type, bonus_resistance, special_effect, icon_path) VALUES
('Basic Armor', '+20% Resistance', 'armor', 20, NULL, 'armor_icon.png');

INSERT INTO items (name, description, type, bonus_damage, special_effect, icon_path) VALUES
('Armor Piercing', '50% dmg ignores resistance', 'weapon', 0, 'penetration', 'ap_icon.png');

INSERT INTO items (name, description, type, special_effect, icon_path, is_consumable) VALUES
('Regenerator', 'Heal 1 HP per turn', 'accessory', 'regen', 'regen_icon.png', FALSE);

INSERT INTO items (name, description, type, special_effect, icon_path, is_consumable) VALUES
('Defender', 'Survive lethal hit with 1 HP', 'accessory', 'defender', 'def_icon.png', FALSE);

INSERT INTO items (name, description, type, bonus_ap, icon_path) VALUES
('Speed Mod', '+1 Action Point', 'accessory', 1, 'speed_icon.png');

INSERT INTO user_inventory (user_id, item_id, quantity) 
SELECT u.id, i.id, 5 
FROM users u 
CROSS JOIN items i 
WHERE u.username LIKE 'Player%' AND i.id BETWEEN 1 AND 5;

INSERT INTO user_characters (user_id, class_id, is_active)
SELECT u.id, c.id, TRUE
FROM users u
CROSS JOIN character_classes c
WHERE u.username LIKE 'Player%' AND c.name = 'SniperDefault';
