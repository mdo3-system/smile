<?php
// migrate_subcontractor_checkback_file.php
require_once 'db_connect.php';

try {
    // subcontractor_orders テーブルに checkback_file_path カラムを追加する
    $pdo->exec("ALTER TABLE subcontractor_orders 
        ADD COLUMN checkback_file_path VARCHAR(255) NULL DEFAULT NULL AFTER checkback_updated_at");
    echo "subcontractor_orders: checkback_file_path added successfully.\n";
} catch (Exception $e) {
    echo "subcontractor_orders columns might already exist or error: " . $e->getMessage() . "\n";
}
