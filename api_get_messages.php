<?php
require_once __DIR__ . '/vendor/autoload.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

use App\Controllers\Api\ChatController;

$controller = new ChatController();
$controller->getMessages();
