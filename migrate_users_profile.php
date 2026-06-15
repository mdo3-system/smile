<?php
require_once __DIR__ . '/db_connect.php';

echo "Starting Users Profile Columns Migration...\n";

$columns = [
    'company_kana' => 'VARCHAR(255) NULL',
    'zip_code' => 'VARCHAR(20) NULL',
    'address' => 'VARCHAR(255) NULL',
    'contact_kana' => 'VARCHAR(255) NULL',
    'mobile_number' => 'VARCHAR(50) NULL',
    'billing_company_name' => 'VARCHAR(255) NULL'
];

foreach ($columns as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        echo "Added $col to users table successfully.\n";
    } catch (Exception $e) {
        echo "users.$col may already exist or error: " . $e->getMessage() . "\n";
    }
}

echo "Migration complete.\n";
