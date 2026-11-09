<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// 奥田様（ID: 102）からのメッセージを模したテスト送信
$projectId = 73;
$senderId = 102;
$senderRole = 'client';
$threadType = 'client_admin_permit';
$messageText = "【本番疎通テスト】チャット通知メールの改修（SPF/Envelope From対応およびGmail直接配信）の疎通確認テストです。";

echo "Running sendChatEmailNotification...\n";
sendChatEmailNotification($projectId, $senderId, $senderRole, $threadType, $messageText, $pdo);
echo "[OK] Test completed.\n";
