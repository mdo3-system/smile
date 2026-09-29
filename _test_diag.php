<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';

$projects = $pdo->query("SELECT id, client_id, project_name FROM projects ORDER BY id DESC LIMIT 10")->fetchAll();

echo "Testing projects:\n";
foreach ($projects as $pj) {
    // 1. Admin test
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['parent_id'] = 0;
    $_GET['id'] = $pj['id'];
    
    try {
        ob_start();
        include __DIR__ . '/project_detail.php';
        $out = ob_get_clean();
        echo "  [Admin] Project {$pj['id']} ({$pj['project_name']}): OK (Length: " . strlen($out) . ")\n";
    } catch (\Throwable $e) {
        ob_end_clean();
        echo "  [Admin] Project {$pj['id']} ({$pj['project_name']}): ERROR - " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }

    // 2. Client test
    $_SESSION['user_id'] = $pj['client_id'];
    $_SESSION['role'] = 'client';
    $_SESSION['parent_id'] = 0;
    $_GET['id'] = $pj['id'];
    
    try {
        ob_start();
        include __DIR__ . '/project_detail.php';
        $out = ob_get_clean();
        echo "  [Client] Project {$pj['id']} ({$pj['project_name']}): OK (Length: " . strlen($out) . ")\n";
    } catch (\Throwable $e) {
        ob_end_clean();
        echo "  [Client] Project {$pj['id']} ({$pj['project_name']}): ERROR - " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}
