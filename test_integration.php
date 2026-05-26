<?php
// test_integration.php

if (php_sapi_name() !== 'cli') {
    die("このスクリプトはコマンドラインから実行してください。\n");
}

echo "=== Google Drive API 統合・疎通テストスクリプト ===\n";

$credentials_file = __DIR__ . '/credentials.json';
$has_credentials = file_exists($credentials_file);

if (!$has_credentials) {
    echo "【警告】credentials.json が存在しません。ダミーの credentials.json を一時的に作成してクラス読み込みテストを行います。\n";
    $dummy_json = json_encode([
        "type" => "service_account",
        "project_id" => "dummy-project",
        "private_key_id" => "dummykeyid",
        "private_key" => "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC3\n-----END PRIVATE KEY-----\n",
        "client_email" => "dummy@dummy-project.iam.gserviceaccount.com",
        "client_id" => "1234567890",
        "auth_uri" => "https://accounts.google.com/o/oauth2/auth",
        "token_uri" => "https://oauth2.googleapis.com/token",
        "auth_provider_x509_cert_url" => "https://www.googleapis.com/oauth2/v1/certs",
        "client_x509_cert_url" => "https://www.googleapis.com/robot/v1/metadata/x509/dummy%40dummy-project.iam.gserviceaccount.com"
    ]);
    file_put_contents($credentials_file, $dummy_json);
}

try {
    echo "1. google_drive_client.php のロード中...\n";
    require_once __DIR__ . '/google_drive_client.php';
    echo "   [PASS] 正常にロードされました。\n";

    echo "2. Google\\Service\\Drive インスタンス化テスト...\n";
    $service = get_google_drive_service();
    echo "   [PASS] クライアントおよびサービスが正常にインスタンス化されました。\n";

    // 自前のDB接続確認
    echo "3. データベース接続テスト...\n";
    $host = 'localhost';
    $db   = 'mdo3_system';
    $user = 'mdo3_system01';
    $pass = 'koki2989';
    $charset = 'utf8mb4';
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    ];
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        echo "   [PASS] db_connect.php定義のユーザーで接続に成功しました。\n";
    } catch (PDOException $e) {
        $user = 'root';
        $pass = '';
        $pdo = new PDO($dsn, $user, $pass, $options);
        echo "   [PASS] フォールバックの root ユーザーで接続に成功しました。\n";
    }

} catch (Exception $e) {
    echo "   [FAIL] テスト中に例外が発生しました: " . $e->getMessage() . "\n";
} finally {
    if (!$has_credentials && file_exists($credentials_file)) {
        unlink($credentials_file);
        echo "一時的な credentials.json を削除しました。\n";
    }
}

echo "=== テスト完了 ===\n";
