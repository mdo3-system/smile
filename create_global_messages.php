<?php
require_once 'db_connect.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS global_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subcontractor_id INT NOT NULL,
            sender_id INT NOT NULL,
            message_text TEXT,
            file_path VARCHAR(255),
            file_type VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "global_messages table created or already exists.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
