<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../db_connect.php';

echo "Database connection successful.\n";

$userIds = [3, 15, 17];
foreach ($userIds as $uid) {
    $emails = getCompanyNotificationEmails($uid, $pdo);
    echo "User ID {$uid} company emails resolved: " . implode(', ', $emails) . "\n";
}

// 実際にテストメールを投げてみる (mb_send_mailの結果を確認)
$test_emails = [
    'spi@thanks.work',
    't.masaki@shukobuild.com',
    'yu.yamamura@shukobuild.com'
];

echo "\n--- Sending Test Emails ---\n";
foreach ($test_emails as $email) {
    $subject = "【設計サポート】システム接続テストメール";
    $body = "このメールは、木造住宅設計サポート・ポータルからの自動接続テストメールです。\n\n"
          . "本メールが受信できている場合、システムからお客様のメールアドレスへの送信経路は正常に稼働しています。\n\n"
          . "送信日時: " . date('Y-m-d H:i:s') . "\n";
          
    // 実際に送信
    $result = sendSystemEmail($email, $subject, $body);
    echo "To: {$email} | Result: " . ($result ? "SUCCESS (true)" : "FAILED (false)") . "\n";
}
