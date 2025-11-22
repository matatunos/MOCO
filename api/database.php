<?php
// Database configuration
define('DB_FILE', __DIR__ . '/../data/moco.db');

// Create database if not exists
function initDatabase() {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ");
    
    // Insert default settings if not exist
    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute(['allow_registration', '1']);
    $stmt->execute(['max_file_size', '104857600']); // 100MB
    $stmt->execute(['allowed_extensions', 'all']);
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            path TEXT NOT NULL,
            parent_id INTEGER,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (parent_id) REFERENCES folders(id)
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            original_name TEXT NOT NULL,
            size INTEGER NOT NULL,
            mime_type TEXT,
            storage_path TEXT NOT NULL,
            folder_id INTEGER,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (folder_id) REFERENCES folders(id)
        )
    ");
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS shared_folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            folder_id INTEGER NOT NULL,
            shared_with_user_id INTEGER NOT NULL,
            permission TEXT DEFAULT 'read',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (folder_id) REFERENCES folders(id),
            FOREIGN KEY (shared_with_user_id) REFERENCES users(id)
        )
    ");
    
    return $db;
}

function getDb() {
    return new PDO('sqlite:' . DB_FILE);
}

function getSetting($key) {
    $db = getDb();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['value'] : null;
}

function setSetting($key, $value) {
    $db = getDb();
    $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    return $stmt->execute([$key, $value]);
}
