<?php
// google_auth.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/google_drive_client.php';

// 管理者以外はアクセス不可にする
require_once __DIR__ . '/auth.php';
check_auth(['admin']);

$credentials_path = __DIR__ . '/credentials.json';
if (!file_exists($credentials_path)) {
    die("エラー: credentials.json が配置されていません。GCPからOAuthクライアントIDのJSONをダウンロードして配置してください。");
}

$client = new Google\Client();
$client->setAuthConfig($credentials_path);
$client->addScope(Google\Service\Drive::DRIVE);
$client->addScope(Google\Service\Calendar::CALENDAR);
$client->setAccessType('offline');
$client->setPrompt('select_account consent'); // リフレッシュトークンを確実に取得するため

// システムURLに合わせた oauth2callback.php のURLをセット
$app_url = getenv('APP_URL') ?: 'https://system.thanks.work';
$redirect_uri = rtrim($app_url, '/') . '/oauth2callback.php';
$client->setRedirectUri($redirect_uri);

$auth_url = $client->createAuthUrl();

// Googleログイン画面へリダイレクト
header('Location: ' . $auth_url);
exit;
