<?php
// migrate_add_magic_links_used_at.php
require_once __DIR__ . '/db_connect.php';

echo "Adding used_at column to magic_links table...\n";

try {
    $pdo->exec("ALTER TABLE magic_links ADD COLUMN used_at DATETIME NULL");
    echo "Successfully added column: used_at\n";
} catch (Exception $e) {
    echo "Column used_at might already exist or error: " . $e->getMessage() . "\n";
}

echo "Migration complete.\n";
