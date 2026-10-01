<?php
// migrate_add_remember_token.php
require_once __DIR__ . '/db_connect.php';

try {
    $pdo->exec("
        ALTER TABLE users 
        ADD COLUMN IF NOT EXISTS remember_token VARCHAR(64) NULL,
        ADD COLUMN IF NOT EXISTS remember_token_expires DATETIME NULL;
    ");
    echo "Migration successful: remember_token and remember_token_expires added to users table.\n";
} catch (Exception $e) {
    echo "Migration failed or already applied: " . $e->getMessage() . "\n";
}
