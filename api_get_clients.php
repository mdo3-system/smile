<?php
// api_get_clients.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';

// 管理者権限チェック
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '管理者権限が必要です。']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT u.id, u.company_name, u.contact_name, u.email, u.phone_number, u.mobile_number, u.created_at,
               COUNT(p.id) as project_count
        FROM users u
        LEFT JOIN projects p ON u.id = p.client_id
        WHERE u.role = 'client'
        GROUP BY u.id
        ORDER BY u.id DESC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'clients' => $clients], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
