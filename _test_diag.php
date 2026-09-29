<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/session_config.php';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_GET['id'] = 46;

try {
    ob_start();
    include __DIR__ . '/project_detail.php';
    $out = ob_get_clean();
    echo "SUCCESS: Output length = " . strlen($out) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
