<?php
require 'db_connect.php';

try {
    $db_type = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($db_type === 'sqlite') {
        $pdo->exec("ALTER TABLE subcontractor_payments ADD COLUMN invoice_file_path VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE subcontractor_payments ADD COLUMN invoice_file_name VARCHAR(255) DEFAULT NULL");
    } else {
        $pdo->exec("ALTER TABLE subcontractor_payments ADD COLUMN invoice_file_path VARCHAR(255) DEFAULT NULL AFTER note");
        $pdo->exec("ALTER TABLE subcontractor_payments ADD COLUMN invoice_file_name VARCHAR(255) DEFAULT NULL AFTER invoice_file_path");
    }
    echo "Columns invoice_file_path and invoice_file_name added successfully.\n";
} catch (Exception $e) {
    echo "Info: " . $e->getMessage() . " (It might already exist)\n";
}
