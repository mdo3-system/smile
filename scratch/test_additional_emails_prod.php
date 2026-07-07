<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';

try {
    $pdo->beginTransaction();

    // ID 15 へのダミー追加アドレス設定
    $pdo->exec("INSERT INTO user_notification_emails (user_id, email) VALUES (15, 't.masaki_add1@shukobuild.com')");
    // ID 17 へのダミー追加アドレス設定
    $pdo->exec("INSERT INTO user_notification_emails (user_id, email) VALUES (17, 'yu.yamamura_add1@shukobuild.com')");
    $pdo->exec("INSERT INTO user_notification_emails (user_id, email) VALUES (17, 'yu.yamamura_add2@shukobuild.com')");

    echo "=== Resolve Company Emails for User ID 15 ===\n";
    $emails15 = getCompanyNotificationEmails(15, $pdo);
    echo "Resolved Emails (Expected count: 6):\n";
    print_r($emails15);

    echo "\n=== Resolve Company Emails for User ID 17 ===\n";
    $emails17 = getCompanyNotificationEmails(17, $pdo);
    echo "Resolved Emails (Expected count: 6):\n";
    print_r($emails17);

    // テストなのでロールバックして本番のデータを綺麗にしておく
    $pdo->rollBack();
    echo "\nTest transaction rolled back successfully.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Test failed: " . $e->getMessage() . "\n";
}
