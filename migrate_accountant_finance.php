<?php
// migrate_accountant_finance.php
require_once 'db_connect.php';

try {
    // projects テーブルに 50%入金額、残金入金額、および複数追加見積JSON格納用のカラムを追加
    $pdo->exec("ALTER TABLE projects 
        ADD COLUMN deposit_amount_50 INT DEFAULT 0 AFTER deposit_amount,
        ADD COLUMN deposit_amount_rem INT DEFAULT 0 AFTER deposit_amount_50,
        ADD COLUMN additional_estimates LONGTEXT DEFAULT NULL AFTER deposit_amount_rem");
    echo "projects: deposit_amount_50, deposit_amount_rem, additional_estimates added successfully.\n";
} catch (Exception $e) {
    echo "projects finance columns might already exist or error: " . $e->getMessage() . "\n";
}
