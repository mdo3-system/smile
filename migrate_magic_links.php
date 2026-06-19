<?php
// migrate_magic_links.php
require_once __DIR__ . '/db_connect.php';

echo "Starting Magic Links migration...\n";

$columns = [
    'magic_token' => 'VARCHAR(255) NULL',
    'magic_token_expires' => 'DATETIME NULL',
    'allowed_project_id' => 'INT NULL',
    'parent_id' => 'INT NULL'
];

foreach ($columns as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        echo "Successfully added column: $col\n";
    } catch (Exception $e) {
        echo "Column $col might already exist or error: " . $e->getMessage() . "\n";
    }
}

echo "Magic Links migration complete.\n";
