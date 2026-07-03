<?php
// migrate_add_project_archive_flags.php
require_once 'db_connect.php';

try {
    // 1. is_archived カラムの追加
    $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'is_archived'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN is_archived TINYINT DEFAULT 0 AFTER status");
        echo "Added 'is_archived' column to projects table.\n";
    } else {
        echo "'is_archived' column already exists.\n";
    }

    // 2. is_client_archived カラムの追加
    $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'is_client_archived'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN is_client_archived TINYINT DEFAULT 0 AFTER is_archived");
        echo "Added 'is_client_archived' column to projects table.\n";
    } else {
        echo "'is_client_archived' column already exists.\n";
    }

    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
