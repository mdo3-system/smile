<?php
require 'db_connect.php';

try {
    // 1. global_messages テーブル作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS global_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subcontractor_id INT NOT NULL,
            sender_id INT NOT NULL,
            message_text TEXT NOT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_global_msg_sub FOREIGN KEY (subcontractor_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_global_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "global_messages table created or already exists.\n";

    // 2. subcontractor_payments テーブル作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subcontractor_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subcontractor_id INT NOT NULL,
            target_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
            paid_amount INT NOT NULL DEFAULT 0,
            paid_at DATETIME DEFAULT NULL,
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_sub_payments FOREIGN KEY (subcontractor_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uk_sub_month (subcontractor_id, target_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "subcontractor_payments table created or already exists.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
