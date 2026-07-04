<?php
// scratch/migrate_add_wish_columns.php
require_once __DIR__ . '/../db_connect.php';

$cols = ['schedule_wishes', 'schedule_wishes_wall', 'schedule_wishes_skin', 'schedule_wishes_sky'];

try {
    // 1行取得して現在のカラム構成をチェック
    $stmt = $pdo->query("SELECT * FROM projects LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    foreach ($cols as $col) {
        // すでにカラムが存在するかチェック
        if ($row && array_key_exists($col, $row)) {
            echo "Column '{$col}' already exists in 'projects' table. Skipping.\n";
            continue;
        }
        
        // カラムを追加
        echo "Adding column '{$col}' to 'projects' table...\n";
        $pdo->exec("ALTER TABLE projects ADD COLUMN {$col} TEXT NULL");
        echo "Successfully added '{$col}'.\n";
    }
    
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
