<?php
// api_issue_final_invoice.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // JSON破損を防ぐため出力はオフ

function logDebug($msg) {
    file_put_contents(__DIR__ . '/debug_api.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL) {
        logDebug("Fatal Error in api_issue_final_invoice: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$is_accountant = ($_SESSION['role'] === 'accountant');
if (!$is_admin && !$is_accountant) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$project_id = $_POST['project_id'] ?? null;
if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No project ID']);
    exit;
}

try {
    logDebug("Starting final invoice generation for project {$project_id}...");
    require_once __DIR__ . '/actions/action_issue_invoice_helper.php';
    
    $pdfDriveId = issueFinalInvoiceHelper($pdo, $project_id, $_SESSION['user_id']);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'drive_file_id' => $pdfDriveId
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logDebug("Exception in api_issue_final_invoice: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
