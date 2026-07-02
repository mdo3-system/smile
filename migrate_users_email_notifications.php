<?php
// migrate_users_email_notifications.php
require_once 'db_connect.php';

try {
    // 1. email_notification_enabled カラムの追加
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_notification_enabled'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_notification_enabled TINYINT DEFAULT 1 AFTER role");
        echo "Added 'email_notification_enabled' column to users table.\n";
    } else {
        echo "'email_notification_enabled' column already exists.\n";
    }

    // 2. last_active_at カラムの追加
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_active_at'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_active_at DATETIME NULL AFTER email_notification_enabled");
        echo "Added 'last_active_at' column to users table.\n";
    } else {
        echo "'last_active_at' column already exists.\n";
    }

    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
