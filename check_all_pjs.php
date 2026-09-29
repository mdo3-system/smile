<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';

// 直近の案件をすべてテスト
$stmt = $pdo->query("SELECT id, project_name, client_id FROM projects ORDER BY id DESC LIMIT 5");
$projects = $stmt->fetchAll();

foreach ($projects as $pj) {
    echo "========================================\n";
    echo "TESTING PROJECT ID: {$pj['id']} ({$pj['project_name']})\n";
    
    // Admin
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['parent_id'] = 0;
    $_GET['id'] = $pj['id'];
    
    ob_start();
    try {
        include __DIR__ . '/project_detail.php';
        $content = ob_get_clean();
        echo "  -> ADMIN: SUCCESS (Length: " . strlen($content) . ")\n";
    } catch (\Throwable $e) {
        $content = ob_get_clean();
        echo "  -> ADMIN: EXCEPTION: " . $e->getMessage() . "\n";
        echo "     File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}
