<?php
// test_issue.php
require_once __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

$project_id = 2; // テスト壁量計算 (id=2)

try {
    require_once __DIR__ . '/actions/action_issue_invoice_helper.php';
    echo "Running helper...\n";
    $pdfDriveId = issuePrimaryInvoiceHelper($pdo, $project_id, 1);
    echo "Success! Drive ID: " . $pdfDriveId . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
