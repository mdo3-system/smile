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
    $isGlobal = false;
    // 対象メッセージを取得
    $stmt = $pdo->prepare("SELECT * FROM messages WHERE id = :id");
    $stmt->execute(['id' => $message_id]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        // global_messages テーブルから取得
        $stmtG = $pdo->prepare("SELECT * FROM global_messages WHERE id = :id");
        $stmtG->execute(['id' => $message_id]);
        $message = $stmtG->fetch(PDO::FETCH_ASSOC);
        if ($message) {
            $isGlobal = true;
        }
    }

    if (!$message) {
        echo json_encode(['success' => false, 'error' => '該当メッセージが見つかりません。']);
        exit;
    }

    // 削除権限チェック: 本人のメッセージ、管理者/経理、または同じ協力業者グループのユーザーであること
    $can_delete = false;

    if (in_array($user_role, ['admin', 'accountant'])) {
        $can_delete = true;
    } elseif (intval($message['sender_id']) === intval($current_user_id)) {
        $can_delete = true;
    } else {
        // ログインユーザーと送信者の所属会社（親ID）が一致するかチェック
        $stmtUser = $pdo->prepare("SELECT id, parent_id FROM users WHERE id IN (:uid, :sid)");
        $stmtUser->execute(['uid' => $current_user_id, 'sid' => $message['sender_id']]);
        $uMap = [];
        while ($r = $stmtUser->fetch(PDO::FETCH_ASSOC)) {
            $uMap[$r['id']] = $r['parent_id'] ? intval($r['parent_id']) : intval($r['id']);
        }
        if (isset($uMap[$current_user_id]) && isset($uMap[$message['sender_id']]) && $uMap[$current_user_id] === $uMap[$message['sender_id']]) {
            $can_delete = true;
        }
    }

    if (!$can_delete) {
        echo json_encode(['success' => false, 'error' => 'メッセージを削除する権限がありません。']);
        exit;
    }

    // メッセージ削除処理
    $targetTable = $isGlobal ? 'global_messages' : 'messages';
    $stmtDel = $pdo->prepare("DELETE FROM {$targetTable} WHERE id = :id");
    $success = $stmtDel->execute(['id' => $message_id]);

    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
