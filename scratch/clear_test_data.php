<?php
// scratch/clear_test_data.php
// ユーザー（users）以外の案件・仕様・ファイル・発注・メッセージデータを削除するスクリプト

require_once __DIR__ . '/../db_connect.php';

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    $tables = [
        'projects',
        'project_specs',
        'project_files',
        'subcontractor_orders',
        'messages'
    ];
    
    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM {$table}");
        // AUTO_INCREMENTをリセット
        $pdo->exec("ALTER TABLE {$table} AUTO_INCREMENT = 1;");
        echo "Cleared table: {$table}\n";
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "ユーザー以外のテストデータをすべて正常に削除しました。\n";
} catch (Exception $e) {
    echo "エラーが発生しました: " . $e->getMessage() . "\n";
}
