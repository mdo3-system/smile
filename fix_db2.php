<?php
require_once __DIR__ . '/db_connect.php';
try {
    $pdo->exec("ALTER TABLE messages ADD COLUMN file_type VARCHAR(50) NULL");
    echo "Added file_type to messages\n";
} catch (Exception $e) {
    echo "messages.file_type may already exist or error: " . $e->getMessage() . "\n";
}
