<?php
require_once __DIR__ . '/../db_connect.php';

echo "Database connection successful.\n";

$userIds = [3, 15, 17];
foreach ($userIds as $uid) {
    echo "\n--- Debugging User ID {$uid} ---\n";
    try {
        $stmtParent = $pdo->prepare("SELECT id, parent_id FROM users WHERE id = :uid");
        $stmtParent->execute(['uid' => $uid]);
        $row = $stmtParent->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo "User not found in database.\n";
            continue;
        }
        
        echo "Row fetch result: " . json_encode($row) . "\n";
        
        $parentId = $row['parent_id'] ?: $row['id'];
        echo "Resolved Parent ID: {$parentId}\n";
        
        // プレースホルダーを別々にした修正版クエリ
        $stmtEmails = $pdo->prepare("
            SELECT id, contact_name, email, email_notification_enabled FROM users 
            WHERE (id = :pid_1 OR parent_id = :pid_2)
        ");
        $stmtEmails->execute([
            'pid_1' => $parentId,
            'pid_2' => $parentId
        ]);
        $all_members = $stmtEmails->fetchAll(PDO::FETCH_ASSOC);
        echo "All company members in DB:\n" . json_encode($all_members, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        
        $stmtEmailsFiltered = $pdo->prepare("
            SELECT email FROM users 
            WHERE (id = :pid_1 OR parent_id = :pid_2)
            AND email_notification_enabled = 1
            AND email IS NOT NULL AND email != ''
        ");
        $stmtEmailsFiltered->execute([
            'pid_1' => $parentId,
            'pid_2' => $parentId
        ]);
        $emails = $stmtEmailsFiltered->fetchAll(PDO::FETCH_COLUMN);
        echo "Filtered notification emails: " . implode(', ', $emails) . "\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
