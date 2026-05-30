<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug logging
function logDebug($msg) {
    file_put_contents(__DIR__ . '/debug_api.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL) {
        logDebug("Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

require_once __DIR__ . '/vendor/autoload.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

use App\Controllers\Api\EstimateController;

try {
    logDebug("Starting save process...");
    $controller = new EstimateController();
    $controller->save();
    logDebug("Save process completed.");
} catch (Throwable $e) {
    logDebug("Exception caught at top level: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

