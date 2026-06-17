<?php
// api_issue_primary_invoice.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // JSON破損を防ぐため出力はオフ

function logDebug($msg) {
    file_put_contents(__DIR__ . '/debug_api.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL) {
        logDebug("Fatal Error in api_issue_primary_invoice: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$is_accountant = ($_SESSION['role'] === 'accountant');
if (!$is_admin && !$is_accountant) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$project_id = $_POST['project_id'] ?? null;
if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No project ID']);
    exit;
}

try {
    logDebug("Starting primary invoice generation with primary response file for project {$project_id}...");
    
    // 一次回答ファイルの検証
    if (empty($_FILES['primary_file']['name']) || $_FILES['primary_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("一次回答ファイル（計算書等）を添付してください。");
    }

    $pdo->beginTransaction();

    // 1. 案件情報の取得
    $stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
    $stmtProj->execute(['id' => $project_id]);
    $project_info = $stmtProj->fetch(PDO::FETCH_ASSOC);
    if (!$project_info) {
        throw new Exception("案件が見つかりません。");
    }

    // 2. アップロード処理 (Google Drive ＋ project_files 登録)
    require_once __DIR__ . '/google_drive_client.php';
    $file_name = $_FILES['primary_file']['name'];
    $tmp_name  = $_FILES['primary_file']['tmp_name'];
    $mime_type = $_FILES['primary_file']['type'];
    
    $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type, $project_id, $pdo);

    // 計算タイプの決定（案件の要求フラグから優先順位か、もしくはPOSTされたtab）
    $tab = $_POST['tab'] ?? '';
    if (empty($tab)) {
        if ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1) {
            $tab = 'permit';
        } elseif ($project_info['req_wall'] == 1) {
            $tab = 'wall';
        } elseif ($project_info['req_skin'] == 1) {
            $tab = 'skin';
        } elseif ($project_info['req_sky'] == 1) {
            $tab = 'sky';
        } else {
            $tab = 'permit';
        }
    }

    $file_category = 'calc_doc'; // 構造計算書
    if ($tab === 'wall') {
        $file_category = 'wall_calc_doc';
    } elseif ($tab === 'skin') {
        $file_category = 'skin_calc_doc';
    } elseif ($tab === 'sky') {
        $file_category = 'sky_calc_doc';
    }

    // 3. ステータスを submission（提出済・確認中）に更新
    require_once __DIR__ . '/Repositories/ProjectRepository.php';
    $projectRepo = new ProjectRepository($pdo);
    $projectRepo->updateStatus($project_id, 'submission');

    // 4. スケジュール実績 JSON の更新（インデックス 2: 構造計算・図面 初回提示 に今日の日付を設定）
    $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
    $stmtAct->execute(['id' => $project_id]);
    $act_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');
    if ($act_row) {
        $colsToUpdate = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
        foreach ($colsToUpdate as $col) {
            $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
            $actuals[2] = $today; // 初回提示
            $stmtUpdateAct = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
            $stmtUpdateAct->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
        }
    }

    // 5. 一次請求書(50%)の自動発行＆チャット通知 (共通ヘルパーの呼び出し)
    require_once __DIR__ . '/actions/action_issue_invoice_helper.php';
    $pdfDriveId = issuePrimaryInvoiceHelper($pdo, $project_id, $_SESSION['user_id']);

    // 6. 一次回答の提示完了チャット通知を追加 (計算書ファイルをチャットにUP)
    $msg = "【一次回答の提示 ＆ 請求書発行】\n一次回答の計算図書「{$file_name}」をアップロードし、一次請求書(50%)を発行いたしました。\n何卒よろしくお願いいたします。";
    $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text, file_path) VALUES (:pid, :sid, 'client_admin', :msg, :fpath)");
    $stmtMsg->execute([
        'pid' => $project_id,
        'sid' => $_SESSION['user_id'],
        'msg' => $msg,
        'fpath' => $drive_file_id
    ]);

    $pdo->commit();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'drive_file_id' => $pdfDriveId
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logDebug("Exception in api_issue_primary_invoice: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
