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

        if (empty($project_info)) {
            $stmtP = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
            $stmtP->execute(['id' => $project_id]);
            $project_info = $stmtP->fetch();
        }

        // ① 管理者が送信した場合、依頼主（企業スタッフ全員）へメール通知
        if ($is_admin) {
            try {
                $client_company_id = $project_info['client_id'];
                $emails = getCompanyNotificationEmails($client_company_id, $pdo);
                
                if (!empty($emails)) {
                    $project_name = $project_info['project_name'];
                    $subject = "【設計サポート】案件「{$project_name}」に新着メッセージがあります";
                    $body  = "案件「{$project_name}」にて、担当者から新着メッセージが届きました。\n\n";
                    $body .= "送信メッセージ:\n{$message_text}\n\n";
                    $body .= "▼ダッシュボードでご確認ください\n";
                    $body .= "https://system.thanks.work/project_detail.php?id={$project_id}\n\n";
                    $body .= "------\n";
                    $body .= "※このメールに返信いただいてもお返事できません。担当: 菅原 070-8305-8480（SMS可）";
                    
                    foreach ($emails as $email) {
                        sendSystemEmail($email, $subject, $body);
                    }
                }
            } catch (Exception $e) { /* 通知エラーは無視 */ }
        }

        // ② 依頼主（client）が送信した場合、管理者全員へメール通知
        if ($_SESSION['role'] === 'client') {
            try {
                $admin_emails = getAdminNotificationEmails($pdo);
                
                if (!empty($admin_emails)) {
                    $project_name = $project_info['project_name'];
                    $subject = "【設計サポート】依頼主から新着メッセージがあります（{$project_name}）";
                    $body  = "案件「{$project_name}」にて、依頼主から新着メッセージが届きました。\n\n";
                    $body .= "送信メッセージ:\n{$message_text}\n\n";
                    $body .= "▼管理者用案件詳細で確認する:\n";
                    $body .= "https://system.thanks.work/project_detail.php?id={$project_id}\n\n";
                    $body .= "------\n";
                    $body .= "※このメールに返信いただいてもお返事できません。";
                    
                    foreach ($admin_emails as $admin_email) {
                        sendSystemEmail($admin_email, $subject, $body);
                    }
                }
            } catch (Exception $e) { /* 通知エラーは無視 */ }
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
