<?php
require_once 'db_connect.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    echo json_encode(['error' => 'No project_id']);
    exit;
}

// 案件の最終更新日やメッセージの最新IDなどを取得する簡易API
try {
    $stmt = $pdo->prepare("SELECT updated_at FROM projects WHERE id = :id");
    $stmt->execute(['id' => $project_id]);
    $project_updated = $stmt->fetchColumn();
    
    // スレッド別の最新メッセージIDを取得
    $stmtMsg = $pdo->prepare("SELECT MAX(id) FROM messages WHERE project_id = :id");
    $stmtMsg->execute(['id' => $project_id]);
    $max_msg_id = $stmtMsg->fetchColumn() ?: 0;

    echo json_encode([
        'project_updated_at' => $project_updated,
        'max_message_id' => $max_msg_id
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => 'DB Error']);
}
