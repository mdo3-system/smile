<?php
// api_delete_project_file.php
ini_set('display_errors', 0);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$file_id = isset($_POST['file_id']) ? intval($_POST['file_id']) : null;
$current_user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if (!$file_id || !$current_user_id) {
    echo json_encode(['success' => false, 'error' => '必要なパラメータが不足しています。']);
    exit;
}

try {
    // 対象ファイル情報を取得
    $stmt = $pdo->prepare("
        SELECT pf.*, p.client_id 
        FROM project_files pf
        JOIN projects p ON pf.project_id = p.id
        WHERE pf.id = :id
    ");
    $stmt->execute(['id' => $file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        echo json_encode(['success' => false, 'error' => '該当する添付ファイルが見つかりません。']);
        exit;
    }

    $project_id = intval($file['project_id']);
    $category = $file['file_category'];
    $was_latest = intval($file['is_latest']) === 1;

    // 権限チェック: 管理者/経理、案件の依頼主、または協力業者
    $can_delete = false;
    if (in_array($user_role, ['admin', 'accountant'])) {
        $can_delete = true;
    } elseif (intval($file['client_id']) === intval($current_user_id)) {
        $can_delete = true;
    } else {
        // 協力業者・スタッフチェック
        $stmtSub = $pdo->prepare("
            SELECT COUNT(*) 
            FROM subcontractor_orders so
            JOIN users u ON so.subcontractor_id = u.id OR u.parent_id = so.subcontractor_id
            WHERE so.project_id = :pid AND u.id = :uid
        ");
        $stmtSub->execute(['pid' => $project_id, 'uid' => $current_user_id]);
        if ($stmtSub->fetchColumn() > 0) {
            $can_delete = true;
        }
    }

    if (!$can_delete) {
        echo json_encode(['success' => false, 'error' => 'このファイルを削除する権限がありません。']);
        exit;
    }

    $pdo->beginTransaction();

    // レコードを削除
    $stmtDel = $pdo->prepare("DELETE FROM project_files WHERE id = :id");
    $stmtDel->execute(['id' => $file_id]);

    // もし最新版（is_latest = 1）のファイルを削除した場合、残っている過去バージョンがあれば最新版フラグを修復・昇格
    if ($was_latest) {
        $stmtFind = $pdo->prepare("
            SELECT id FROM project_files 
            WHERE project_id = :pid AND file_category = :cat 
            ORDER BY version DESC, id DESC 
            LIMIT 1
        ");
        $stmtFind->execute(['pid' => $project_id, 'cat' => $category]);
        $prev_id = $stmtFind->fetchColumn();

        if ($prev_id) {
            $stmtUpd = $pdo->prepare("UPDATE project_files SET is_latest = 1 WHERE id = :id");
            $stmtUpd->execute(['id' => $prev_id]);
        }
    }

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
