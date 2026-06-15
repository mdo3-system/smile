<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/google_drive_client.php';

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

$uploadedDriveId = null;
$fileType = null;
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['file']['tmp_name'];
    $fileName = $_FILES['file']['name'];
    $mimeType = $_FILES['file']['type'];
    
    $uploadedDriveId = upload_to_google_drive($fileTmp, $fileName, $mimeType, $projectId, $pdo);
    $fileType = (strpos($mimeType, 'image/') === 0) ? 'image' : 'pdf';
}

$container = AppContainer::getInstance();
$chatService = $container->getChatService();
$tab = $_POST['tab'] ?? '';
$threadType = $_POST['thread_type'] ?? '';
if (!$threadType) {
    if ($tab === 'permit' || $tab === '') {
        $threadType = 'client_admin_permit';
    } else {
        $threadType = 'client_admin_' . $tab;
    }
}
$success = $chatService->sendMessage(
    (int)$projectId,
    (int)$_SESSION['user_id'],
    $threadType,
    $messageText,
    $uploadedDriveId,
    $fileType,
    $targetFile
);

header('Content-Type: application/json');
echo json_encode(['success' => $success]);
