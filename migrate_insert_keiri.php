<?php
// migrate_insert_keiri.php
require_once __DIR__ . '/db_connect.php';

try {
    // role カラムは予約語なのでバッククォートで囲む
    $stmt = $pdo->prepare("
        INSERT INTO users (company_name, contact_name, `role`, email) 
        VALUES ('住ま居る 経理', '経理担当', 'admin', 'keiri@t-smile.co.jp')
    ");
    $stmt->execute();
    echo "Successfully inserted keiri@t-smile.co.jp as admin.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
