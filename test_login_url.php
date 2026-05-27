<?php
// test_login_url.php

if (php_sapi_name() !== 'cli') {
    die("CLI環境から実行してください。\n");
}

echo "=== マジックリンク URL 生成テスト ===\n";

// 1. .env の読み込みテスト
$app_url = '';
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            if (trim($name) === 'APP_URL') {
                $app_url = trim(trim($value), '"\'');
                break;
            }
        }
    }
}

echo "読み込まれた APP_URL: " . ($app_url ? $app_url : "（未設定）") . "\n";

// 2. URL生成ロジックのシミュレート（APP_URLあり）
$token = "test_token_123456";
$role_client = "client";
$role_sub = "subcontractor";

if (!empty($app_url)) {
    $app_url_clean = rtrim($app_url, '/');
    $target_page_client = ($role_client === 'subcontractor') ? 'project_subcontractor.php' : 'index.php';
    $login_url_client = "{$app_url_clean}/{$target_page_client}?token={$token}";
    
    $target_page_sub = ($role_sub === 'subcontractor') ? 'project_subcontractor.php' : 'index.php';
    $login_url_sub = "{$app_url_clean}/{$target_page_sub}?token={$token}";

    echo "\n[APP_URL 有りでの生成URL]\n";
    echo "  依頼主宛リンク: " . $login_url_client . "\n";
    echo "  協力業者宛リンク: " . $login_url_sub . "\n";
}

// 3. 自動検出フォールバックのシミュレート（APP_URLなしの場合を模倣）
echo "\n[自動検出フォールバックのシミュレート]\n";
// $_SERVER のモックを設定
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'system.thanks.work';
$_SERVER['SCRIPT_NAME'] = '/login.php';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$script_dir = str_replace('\\', '/', $script_dir);
$script_dir = rtrim($script_dir, '/');
$detected_app_url = "{$protocol}://{$host}{$script_dir}";

$login_url_detected = "{$detected_app_url}/index.php?token={$token}";
echo "  自動検出されたAPP_URL: " . $detected_app_url . "\n";
echo "  生成されたリンク: " . $login_url_detected . "\n";

echo "\n=== テスト完了 ===\n";
