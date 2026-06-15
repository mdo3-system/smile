<?php
// migrate_additional_deposits.php
require_once 'db_connect.php';

try {
    // データベースの種類を判別
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    if ($driver === 'sqlite') {
        // SQLite用カラム追加
        $pdo->exec("ALTER TABLE projects ADD COLUMN additional_deposits TEXT DEFAULT NULL");
        echo "SQLite: additional_deposits column added successfully.\n";
    } else {
        // MySQL (本番環境) 用カラム追加
        $pdo->exec("ALTER TABLE projects ADD COLUMN additional_deposits JSON DEFAULT NULL AFTER deposit_date_rem");
        echo "MySQL: additional_deposits column added successfully.\n";
    }
} catch (Exception $e) {
    // すでに存在する場合のエラーは無視
    if (strpos($e->getMessage(), 'duplicate column name') !== false || strpos($e->getMessage(), 'already exists') !== false) {
        echo "Column additional_deposits already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
