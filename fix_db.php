<?php
require_once __DIR__ . '/db_connect.php';

try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN drive_folder_id VARCHAR(255) NULL");
    echo "Added drive_folder_id to projects\n";
} catch (Exception $e) {
    echo "projects.drive_folder_id may already exist or error: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN drive_folder_id VARCHAR(255) NULL");
    echo "Added drive_folder_id to users\n";
} catch (Exception $e) {
    echo "users.drive_folder_id may already exist or error: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE estimates ADD COLUMN pdf_drive_file_id VARCHAR(255) NULL");
    echo "Added pdf_drive_file_id to estimates\n";
} catch (Exception $e) {
    echo "estimates.pdf_drive_file_id may already exist or error: " . $e->getMessage() . "\n";
}

echo "Done.\n";
