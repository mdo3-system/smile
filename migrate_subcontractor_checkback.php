<?php
// migrate_subcontractor_checkback.php
require_once 'db_connect.php';

try {
    // subcontractor_orders テーブルにカラム追加
    // checkback_text: TEXT NULL (チェックバック修正指示)
    // checkback_updated_at: DATETIME NULL (最終更新日)
    $pdo->exec("ALTER TABLE subcontractor_orders 
        ADD COLUMN checkback_text TEXT NULL DEFAULT NULL AFTER payment_date,
        ADD COLUMN checkback_updated_at DATETIME NULL DEFAULT NULL AFTER checkback_text");
    echo "subcontractor_orders: checkback_text, checkback_updated_at added successfully.\n";
} catch (Exception $e) {
    echo "subcontractor_orders columns might already exist or error: " . $e->getMessage() . "\n";
}
