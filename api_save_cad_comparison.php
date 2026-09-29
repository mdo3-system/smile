<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

// 管理者または該当案件のアクセス権確認
$user_role = $_SESSION['user_role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['project_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'プロジェクトIDが指定されていません。']);
    exit;
}

$project_id = intval($input['project_id']);
$comparison_data = $input['comparison_data'] ?? null;

if ($comparison_data === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '照合データが空です。']);
    exit;
}

try {
    // 権限チェック
    $stmt = $pdo->prepare("SELECT id, user_id FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '案件が見つかりません。']);
        exit;
    }

    if ($user_role !== 'admin' && $project['user_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => '権限がありません。']);
        exit;
    }

    $json_str = is_string($comparison_data) ? $comparison_data : json_encode($comparison_data, JSON_UNESCAPED_UNICODE);

    $update_stmt = $pdo->prepare("UPDATE projects SET cad_comparison_json = ? WHERE id = ?");
    $update_stmt->execute([$json_str, $project_id]);

    echo json_encode(['success' => true, 'message' => '照合結果を保存しました。']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
