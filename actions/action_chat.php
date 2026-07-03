<?php
// actions/action_chat.php

// チャットメッセージの送信
if ($action === 'send_message') {
    $message_text = trim($_POST['message_text'] ?? '');
    $target_file = trim($_POST['target_file'] ?? '');
    
    if ($message_text !== '') {
        $thread_type = 'client_admin'; // 対依頼主チャット
        
        // 対象ファイルが選択されている場合は先頭にタグを付ける
        if ($target_file !== '') {
            $message_text = "【" . $target_file . " について】\n" . $message_text;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
            VALUES (:pid, :sid, :thread, :msg)
        ");
        $stmt->execute([
            'pid' => $project_id,
            'sid' => $_SESSION['user_id'],
            'thread' => $thread_type,
            'msg' => $message_text
        ]);

        // 新着メッセージのメール通知配信（双方向）
        sendChatEmailNotification($project_id, $_SESSION['user_id'], $_SESSION['role'], $thread_type, $message_text, $pdo);
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
