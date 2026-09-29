<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/session_config.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['contact_name'] = '管理者';
$_SESSION['email'] = 'admin@example.com';
$session_id = session_id();
session_write_close();

$base_url = 'https://thanks.work/system';
$cookie_header = "PHPSESSID={$session_id}";

function test_url($url, $cookie) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $time = microtime(true) - $start;
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    echo "URL: {$url}\n";
    echo "  HTTP: {$http_code} | TIME: " . round($time, 3) . "s | LENGTH: " . strlen($response) . "\n";
    if ($err) echo "  ERROR: {$err}\n";
    if ($http_code >= 400 || $http_code == 0) {
        echo "  BODY: " . substr($response, 0, 300) . "\n";
    }
}

echo "=== DIAGNOSTIC HTTP TEST (WITH VALID SESSION) ===\n";
test_url("{$base_url}/index.php", $cookie_header);
test_url("{$base_url}/project_detail.php?id=46", $cookie_header);
test_url("{$base_url}/project_detail.php?id=61", $cookie_header);
test_url("{$base_url}/subcontractors_list.php", $cookie_header);
test_url("{$base_url}/completed_projects.php", $cookie_header);
test_url("{$base_url}/admin_sales.php", $cookie_header);
