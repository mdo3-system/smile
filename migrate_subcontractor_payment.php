<?php
// migrate_subcontractor_payment.php
require_once 'db_connect.php';

try {
    // 1. subcontractor_orders テーブルにカラム追加
    // payment_status: unpaid, paid
    // payment_date: date
    $pdo->exec("ALTER TABLE subcontractor_orders 
        ADD COLUMN payment_status VARCHAR(20) DEFAULT 'unpaid' AFTER expected_delivery_date,
        ADD COLUMN payment_date DATE NULL DEFAULT NULL AFTER payment_status");
    echo "subcontractor_orders: payment_status, payment_date added successfully.\n";
} catch (Exception $e) {
    echo "subcontractor_orders columns might already exist or error: " . $e->getMessage() . "\n";
}

try {
    // 2. projects テーブルに deposit_status カラム追加
    // deposit_status: unpaid, partially_paid, paid
    $pdo->exec("ALTER TABLE projects 
        ADD COLUMN deposit_status VARCHAR(20) DEFAULT 'unpaid' AFTER deposit_date");
    echo "projects: deposit_status added successfully.\n";
} catch (Exception $e) {
    echo "projects deposit_status column might already exist or error: " . $e->getMessage() . "\n";
}
