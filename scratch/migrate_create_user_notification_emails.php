<?php
require_once __DIR__ . '/../db_connect.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_notification_emails` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `email` VARCHAR(255) NOT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    echo "Table user_notification_emails created successfully (or already exists).\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
