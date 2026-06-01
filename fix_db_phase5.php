<?php
require 'db_connect.php';
try {
    $pdo->exec("ALTER TABLE global_messages ADD COLUMN file_path VARCHAR(255) NULL, ADD COLUMN file_type VARCHAR(50) NULL");
    echo "Success: Added file columns to global_messages\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
