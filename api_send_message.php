<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';
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

$uploadedFiles = [];

// files[] という名前で複数ファイルを受け取れるようにする
if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $i => $name) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['files']['tmp_name'][$i];
            $fileName = $_FILES['files']['name'][$i];
            $mimeType = $_FILES['files']['type'][$i];
            
            $uploadedDriveId = upload_to_google_drive($fileTmp, $fileName, $mimeType, $projectId, $pdo);
            $fileType = (strpos($mimeType, 'image/') === 0) ? 'image' : 'pdf';
            
            $uploadedFiles[] = [
                'drive_id' => $uploadedDriveId,
                'type' => $fileType
            ];
        }
    }
} elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    // 既存の単一ファイルアップロード（file）
    $fileTmp = $_FILES['file']['tmp_name'];
    $fileName = $_FILES['file']['name'];
    $mimeType = $_FILES['file']['type'];
    
    $uploadedDriveId = upload_to_google_drive($fileTmp, $fileName, $mimeType, $projectId, $pdo);
    $fileType = (strpos($mimeType, 'image/') === 0) ? 'image' : 'pdf';
    
    $uploadedFiles[] = [
        'drive_id' => $uploadedDriveId,
        'type' => $fileType
    ];
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

$success = true;
if (empty($uploadedFiles)) {
    $success = $chatService->sendMessage(
        (int)$projectId,
        (int)$_SESSION['user_id'],
        $threadType,
        $messageText,
        null,
        null,
        $targetFile
    );
} else {
    // 最初のファイルとメッセージテキストを送信
    $first = array_shift($uploadedFiles);
    $success = $chatService->sendMessage(
        (int)$projectId,
        (int)$_SESSION['user_id'],
        $threadType,
        $messageText,
        $first['drive_id'],
        $first['type'],
        $targetFile
    );
    
    // 残りのファイルを個別メッセージとして送信（テキストと対象ファイル情報は空）
    foreach ($uploadedFiles as $f) {
        $chatService->sendMessage(
            (int)$projectId,
            (int)$_SESSION['user_id'],
            $threadType,
            '',
            $f['drive_id'],
            $f['type'],
            null
        );
    }
}

if ($success) {
    try {
        $stmtUpdateChatAt = $pdo->prepare("UPDATE projects SET last_manual_chat_at = NOW() WHERE id = :pid");
        $stmtUpdateChatAt->execute(['pid' => (int)$projectId]);

        // 協力業者またはそのスタッフから管理者に送信された場合、メール通知
        if ($_SESSION['role'] === 'subcontractor' && $threadType === 'sub_admin') {
            $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = :pid");
            $stmtProj->execute(['pid' => (int)$projectId]);
            $project_name = $stmtProj->fetchColumn() ?: '不明';

            // 管理者の全メールアドレスを取得
            $stmtAdmins = $pdo->prepare("SELECT email FROM users WHERE role = 'admin'");
            $stmtAdmins->execute();
            $admin_emails = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

            foreach ($admin_emails as $admin_email) {
                if ($admin_email && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                    $subject = "【設計サポート】協力業者から新着メッセージがあります（{$project_name}）";
                    $body  = "案件「{$project_name}」にて、協力業者からメッセージが届きました。\n\n";
                    $body .= "送信メッセージ:\n{$messageText}\n\n";
                    $body .= "▼管理者用案件詳細で確認する:\n";
                    $body .= "https://system.thanks.work/system/project_detail.php?id={$projectId}\n\n";
                    $body .= "------\n";
                    $body .= "※このメールに返信いただいてもお返事できません。";
                    sendSystemEmail($admin_email, $subject, $body);
                }
            }
        }
    } catch (\Exception $e) {
        // ログ出力など必要であれば行うが、メッセージ送信自体は成功しているためスルーでも良い
        error_log("Failed to send chat email notification: " . $e->getMessage());
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => $success]);
