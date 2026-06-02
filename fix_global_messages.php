<?php
require 'db_connect.php';
try {
    $pdo->exec("ALTER TABLE global_messages ADD COLUMN file_type VARCHAR(50) NULL DEFAULT 'file'");
    echo "Added file_type to global_messages\n";
} catch (Exception $e) {
    echo "Error adding to global_messages: " . $e->getMessage() . "\n";
}
