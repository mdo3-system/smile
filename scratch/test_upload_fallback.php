<?php
require 'db_connect.php';
require 'google_drive_client.php';

try {
    // 仮のファイルを生成
    $tmp_file = tempnam(sys_get_temp_dir(), 'test_upload');
    file_put_contents($tmp_file, 'test file content');
    
    // テスト実行 (協力業者ID=3, データベース接続)
    $res = upload_to_google_drive($tmp_file, 'test_fallback_invoice.txt', 'text/plain', null, $pdo, 3);
    
    echo "Success! Path: " . $res . "\n";
    
    @unlink($tmp_file);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
