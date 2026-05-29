<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

use App\Container\AppContainer;

$projectId = $_POST['project_id'] ?? null;
$messageText = $_POST['message_text'] ?? '';
$targetFile = $_POST['target_file'] ?? '';

if (!$projectId) {
    echo json_encode(['success' => false, 'error' => 'No project ID']);
    exit;
}

// Drive upload logic is typically kept here or in ChatService
$uploadedDriveId = null;
$fileType = null;
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    // 既存の Google Drive アップロード処理を呼び出すと仮定
    // $uploadedDriveId = uploadToDrive($_FILES['file']);
    // $fileType = strpos($_FILES['file']['type'], 'image') === 0 ? 'image' : 'pdf';
}

$container = AppContainer::getInstance();
$chatService = $container->getChatService();
$success = $chatService->sendMessage(
    (int)$projectId,
    (int)$_SESSION['user_id'],
    'client_admin',
    $messageText,
    $uploadedDriveId,
    $fileType,
    $targetFile
);

header('Content-Type: application/json');
echo json_encode(['success' => $success]);
