<?php
// api_get_messages.php
// チャットの新着メッセージをJSON形式で返すAPIエンドポイント
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'client']);

$project_id = intval($_GET['project_id'] ?? 0);
$since_id   = intval($_GET['since_id'] ?? 0);

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['error' => 'project_id is required']);
    exit;
}

// RBAC: クライアントは自分の案件のみ
if ($_SESSION['role'] === 'client') {
    $stmtCheck = $pdo->prepare("SELECT id FROM projects WHERE id = :pid AND client_id = :uid");
    $stmtCheck->execute(['pid' => $project_id, 'uid' => $_SESSION['user_id']]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT m.*, u.contact_name, u.role
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    WHERE m.project_id = :pid 
      AND m.thread_type = 'client_admin'
      AND m.id > :since
    ORDER BY m.id ASC
");
$stmt->execute(['pid' => $project_id, 'since' => $since_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($messages);
