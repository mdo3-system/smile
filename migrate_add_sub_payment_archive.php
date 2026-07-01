<?php
require 'db_connect.php';

try {
    $db_type = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($db_type === 'sqlite') {
        $pdo->exec("ALTER TABLE subcontractor_payments ADD COLUMN is_archived TINYINT DEFAULT 0");
    } else {
        $pdo->exec("ALTER TABLE subcontractor_payments ADD COLUMN is_archived TINYINT DEFAULT 0 AFTER invoice_file_name");
    }
    echo "Column is_archived added successfully.\n";
} catch (Exception $e) {
    echo "Info: " . $e->getMessage() . " (It might already exist)\n";
}
