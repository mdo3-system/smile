<?php
// migrate_accountant_finance_date.php
require_once 'db_connect.php';

try {
    $pdo->exec("ALTER TABLE projects 
        ADD COLUMN deposit_date_50 DATE DEFAULT NULL AFTER deposit_amount_rem,
        ADD COLUMN deposit_date_rem DATE DEFAULT NULL AFTER deposit_date_50");
    echo "projects: deposit_date_50, deposit_date_rem added successfully.\n";
} catch (Exception $e) {
    echo "projects deposit date columns might already exist or error: " . $e->getMessage() . "\n";
}
