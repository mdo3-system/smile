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

        // 管理者が送信した場合、依頼主のEmail通知先があればメールを飛ばす
        if ($is_admin) {
            $stmtNotify = $pdo->prepare("SELECT message_text FROM messages WHERE project_id = :pid AND message_text LIKE '%【見積完了時の通知先（SMS/Email）】%' ORDER BY id ASC LIMIT 1");
            $stmtNotify->execute(['pid' => $project_id]);
            $notifyMsg = $stmtNotify->fetchColumn();
            $to_email = '';
            if ($notifyMsg) {
                preg_match('/【見積完了時の通知先（SMS\/Email）】\n([^\n]+)/', $notifyMsg, $matches);
                if (!empty($matches[1]) && filter_var(trim($matches[1]), FILTER_VALIDATE_EMAIL)) {
                    $to_email = trim($matches[1]);
                }
            }
            if ($to_email) {
                $project_name = $project_info['project_name'];
                $subject = "【設計サポート】案件「{$project_name}」に新着メッセージがあります";
                $body = "案件「{$project_name}」にて、管理者から新着メッセージが届きました。\n\n";
                $body .= "以下のURLよりダッシュボードにログインしてご確認ください。\n";
                $body .= "https://thanks.work/system/project_detail.php?id={$project_id}\n\n";
                $body .= "※本メールは送信専用です。";
                sendSystemEmail($to_email, $subject, $body);
            }
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
