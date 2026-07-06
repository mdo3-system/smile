<?php
// scratch/migrate_add_invoice_rate.php
require_once __DIR__ . '/../db_connect.php';

try {
    // 既にカラムが存在するかチェック
    $stmtCheck = $pdo->query("SHOW COLUMNS FROM projects LIKE 'primary_invoice_rate'");
    $exists = $stmtCheck->fetch();

    if (!$exists) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN primary_invoice_rate DECIMAL(3,2) DEFAULT 0.50 AFTER formal_est_date");
        echo "Successfully added column 'primary_invoice_rate' to 'projects' table.\n";
    } else {
        echo "Column 'primary_invoice_rate' already exists in 'projects' table.\n";
    }
} catch (Exception $e) {
    echo "Error adding column: " . $e->getMessage() . "\n";
}
