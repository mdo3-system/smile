<?php
require_once __DIR__ . '/db_connect.php';

// 管理者 (user_id = 1) の追加通知先メールアドレスを確認・登録
$adminId = 1;
$gmail = 's2712350@gmail.com';

$stmtCheck = $pdo->prepare("SELECT id FROM user_notification_emails WHERE user_id = :uid AND email = :email LIMIT 1");
$stmtCheck->execute(['uid' => $adminId, 'email' => $gmail]);
$exists = $stmtCheck->fetchColumn();

if (!$exists) {
    $stmtInsert = $pdo->prepare("INSERT INTO user_notification_emails (user_id, email, created_at) VALUES (:uid, :email, NOW())");
    $stmtInsert->execute(['uid' => $adminId, 'email' => $gmail]);
    echo "[OK] Added {$gmail} to user_notification_emails for Admin (user_id = 1)\n";
} else {
    echo "[INFO] {$gmail} already exists in user_notification_emails for Admin\n";
}

// 登録後の管理者通知先メール一覧を表示
require_once __DIR__ . '/functions.php';
$emails = getAdminNotificationEmails($pdo);
echo "Current Admin Notification Emails:\n";
print_r($emails);
