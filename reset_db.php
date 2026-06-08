<?php
// reset_db.php
// ユーザー情報以外のデータベースリセットスクリプト

require_once __DIR__ . '/db_connect.php';

if (php_sapi_name() !== 'cli') {
    die("このスクリプトはコマンドライン(CLI)からのみ実行可能です。\n");
}

echo "ユーザー情報以外のデータベースのリセットを開始します...\n";

try {
    // 外部キー制約を一時的に無効化
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // リセット対象のテーブルリスト
    $tables = [
        'estimates',
        'project_files',
        'subcontractor_orders',
        'messages',
        'project_specs',
        'projects'
    ];

    foreach ($tables as $table) {
        echo "テーブル: {$table} をクリアしています...\n";
        $pdo->exec("TRUNCATE TABLE `{$table}`");
    }

    // 外部キー制約を再有効化
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "データベースのリセットが正常に完了しました。\n";
} catch (Exception $e) {
    echo "エラーが発生しました: " . $e->getMessage() . "\n";
}
