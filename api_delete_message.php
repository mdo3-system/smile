<?php
// api_delete_message.php
require_once 'auth.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : null;
$current_user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if (!$message_id || !$current_user_id) {
    echo json_encode(['success' => false, 'error' => '必要なパラメータが不足しています。']);
    exit;
}

try {
    // 対象メッセージを取得
    $stmt = $pdo->prepare("SELECT * FROM messages WHERE id = :id");
    $stmt->execute(['id' => $message_id]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        echo json_encode(['success' => false, 'error' => '該当メッセージが見つかりません。']);
        exit;
    }

    // 削除権限チェック: 自身のメッセージ、または管理者ロールであること
    if (intval($message['sender_id']) !== intval($current_user_id) && $user_role !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'メッセージを削除する権限がありません。']);
        exit;
    }

    // メッセージ削除処理
    $stmtDel = $pdo->prepare("DELETE FROM messages WHERE id = :id");
    $success = $stmtDel->execute(['id' => $message_id]);

    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
