<?php
// api_send_message.php
// チャットメッセージ送信・ファイル添付処理
require_once 'auth.php';
require_once 'functions.php';
require_once 'google_drive_client.php';

check_auth(['admin', 'client']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$project_id   = intval($_POST['project_id'] ?? 0);
$message_text = trim($_POST['message_text'] ?? '');
$sender_id    = $_SESSION['user_id'];

if (!$project_id) {
    echo json_encode(['success' => false, 'error' => 'project_id is required']);
    exit;
}

if (empty($message_text) && (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)) {
    echo json_encode(['success' => false, 'error' => 'メッセージかファイルを入力してください']);
    exit;
}

// RBAC: クライアントは自分の案件のみ
if ($_SESSION['role'] === 'client') {
    $stmtCheck = $pdo->prepare("SELECT id FROM projects WHERE id = :pid AND client_id = :uid");
    $stmtCheck->execute(['pid' => $project_id, 'uid' => $sender_id]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
}

$file_path = null;
$file_type = null;

// ファイルアップロード処理
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $file_name  = $_FILES['file']['name'];
    $tmp_name   = $_FILES['file']['tmp_name'];
    $mime_type  = $_FILES['file']['type'];

    // ファイルタイプを分類
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $file_type = 'image';
    } elseif ($ext === 'pdf') {
        $file_type = 'pdf';
    } else {
        $file_type = 'file';
    }

    try {
        $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);
        $file_path = $drive_file_id;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'ファイルアップロードに失敗しました: ' . $e->getMessage()]);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO messages (project_id, sender_id, thread_type, message_text, file_path, file_type) 
        VALUES (:pid, :sid, 'client_admin', :msg, :fpath, :ftype)
    ");
    $stmt->execute([
        'pid'   => $project_id,
        'sid'   => $sender_id,
        'msg'   => $message_text,
        'fpath' => $file_path,
        'ftype' => $file_type
    ]);
    $new_id = $pdo->lastInsertId();

    /*
    // SMS通知（クールダウン付き）
    try {
        require_once 'notifier.php';
        $notifier = new Notifier($pdo);
        $target_user_id = ($_SESSION['role'] === 'admin') ? 
            $pdo->query("SELECT client_id FROM projects WHERE id = {$project_id}")->fetchColumn() : 1;
        
        if ($notifier->check_cooldown($target_user_id)) {
            $stmtUser = $pdo->prepare("SELECT phone_number FROM users WHERE id = :id");
            $stmtUser->execute(['id' => $target_user_id]);
            $to_phone = $stmtUser->fetchColumn();
            if ($to_phone) {
                $proj_name = $pdo->query("SELECT project_name FROM projects WHERE id = {$project_id}")->fetchColumn();
                $sms_msg   = "【構造設計ポータル】{$proj_name} に新しいメッセージが届きました。ログインして確認してください。";
                if ($notifier->send_sms($to_phone, $sms_msg)) {
                    $notifier->update_cooldown($target_user_id);
                }
            }
        }
    } catch (Exception $e) {
        // SMS失敗してもメッセージ送信は成功とみなす
        error_log('SMS notification failed: ' . $e->getMessage());
    }
    */

    echo json_encode(['success' => true, 'id' => $new_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
