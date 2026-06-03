<?php
// migrate_phase4_3.php
require_once __DIR__ . '/db_connect.php';

echo "<pre>\n";

try {
    // 1. updated_at カラムを追加
    $pdo->exec("ALTER TABLE subcontractor_orders ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at");
    echo "Successfully added 'updated_at' to subcontractor_orders.\n";
} catch (Exception $e) {
    echo "Column 'updated_at' may already exist or error: " . $e->getMessage() . "\n";
}

try {
    // 2. status カラムの ENUM 型を変更し、accepted と rejected を追加する
    $pdo->exec("ALTER TABLE subcontractor_orders MODIFY COLUMN status ENUM('requested','accepted','rejected','in_progress','delivered','completed') NOT NULL DEFAULT 'requested'");
    echo "Successfully updated 'status' enum values in subcontractor_orders.\n";
} catch (Exception $e) {
    echo "Error updating 'status' enum: " . $e->getMessage() . "\n";
}

echo "Done.\n</pre>\n";
