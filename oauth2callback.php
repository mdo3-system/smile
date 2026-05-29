<?php
// oauth2callback.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google_drive_client.php';

// 管理者チェック
require_once __DIR__ . '/auth.php';
check_auth(['admin']);

$credentials_path = __DIR__ . '/credentials.json';
if (!file_exists($credentials_path)) {
    die("エラー: credentials.json が見つかりません。");
}

$client = new Google\Client();
$client->setAuthConfig($credentials_path);
$client->addScope(Google\Service\Drive::DRIVE);
$client->setAccessType('offline');

$app_url = getenv('APP_URL') ?: 'https://system.thanks.work';
$redirect_uri = rtrim($app_url, '/') . '/oauth2callback.php';
$client->setRedirectUri($redirect_uri);

if (!isset($_GET['code'])) {
    die("認証コードが取得できませんでした。");
}

try {
    // 認証コードからアクセストークンを取得
    $accessToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (isset($accessToken['error'])) {
        throw new Exception("トークン取得エラー: " . $accessToken['error_description']);
    }

    if (!isset($accessToken['refresh_token'])) {
        throw new Exception("リフレッシュトークン（refresh_token）が取得できませんでした。すでに連携済みの場合は、一度 Google アカウントの設定から『サードパーティ製アプリとサービス』の接続を削除してからやり直してください。");
    }

    // token.json に保存
    $token_path = __DIR__ . '/token.json';
    file_put_contents($token_path, json_encode($accessToken));
    
    // パーミッションを適切に設定
    chmod($token_path, 0600);

    echo "<h1>Google ドライブの連携が完了しました！</h1>";
    echo "<p>認証情報が正常に保存されました。この画面を閉じて、ポータルサイトに戻ってください。</p>";
    echo "<p><a href='../project_detail.php'>案件ダッシュボードへ戻る</a></p>";

} catch (Exception $e) {
    echo "<h1>エラーが発生しました</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='google_auth.php'>もう一度連携を試す</a></p>";
}
