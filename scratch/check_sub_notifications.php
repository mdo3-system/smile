<?php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT id, contact_name, role, email, email_notification_enabled FROM users WHERE parent_id = 3 OR id = 3");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "ID | Name | Email | Notification Enabled\n";
echo "-------------------------------------------\n";
foreach ($users as $u) {
    echo "{$u['id']} | {$u['contact_name']} | {$u['email']} | {$u['email_notification_enabled']}\n";
}
