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

        // 管理者が送信した場合、依頼主のEmail（users.email）へメール通知
        if ($is_admin) {
            try {
                $stmtEmail = $pdo->prepare("
                    SELECT u.email FROM projects p JOIN users u ON p.client_id = u.id WHERE p.id = :pid
                ");
                $stmtEmail->execute(['pid' => $project_id]);
                $to_email = $stmtEmail->fetchColumn();
                if ($to_email && filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
                    $project_name = $project_info['project_name'];
                    $subject = "【設計サポート】案件「{$project_name}」に新着メッセージがあります";
                    $body  = "案件「{$project_name}」にて、担当者から新着メッセージが届きました。\n\n";
                    $body .= "▼ダッシュボードでご確認ください\n";
                    $body .= "https://system.thanks.work/project_detail.php?id={$project_id}\n\n";
                    $body .= "------\n";
                    $body .= "※このメールに返信いただいてもお返事できません。担当: 菅原 070-8305-8480（SMS可）";
                    sendSystemEmail($to_email, $subject, $body);
                }
            } catch (Exception $e) { /* 通知エラーは無視 */ }
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
