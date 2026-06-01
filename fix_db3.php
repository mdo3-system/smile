<?php
require 'db_connect.php';

try {
    $pdo->exec("ALTER TABLE project_files ADD COLUMN is_published_to_sub TINYINT(1) DEFAULT 0 AFTER is_latest");
    echo "Column added successfully.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
