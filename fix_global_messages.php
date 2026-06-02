<?php
require_once 'db_connect.php';

try {
    $pdo->exec("ALTER TABLE global_messages ADD COLUMN file_type VARCHAR(50) NULL;");
    echo "Added file_type to global_messages.\n";
} catch (Exception $e) {
    echo "Error or already exists: " . $e->getMessage() . "\n";
}
