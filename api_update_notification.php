<?php
// api_update_notification.php
require_once 'auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$enabled = isset($input['enabled']) ? intval($input['enabled']) : 1;

try {
    $stmt = $pdo->prepare("UPDATE users SET email_notification_enabled = :val WHERE id = :uid");
    $stmt->execute([
        'val' => $enabled,
        'uid' => $_SESSION['user_id']
    ]);
    
    $_SESSION['email_notification_enabled'] = $enabled;
    
    echo json_encode(['success' => true, 'enabled' => $enabled]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
