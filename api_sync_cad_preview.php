<?php
// api_sync_cad_preview.php
// Z840 バックグラウンドCAD自動変換・プレビュー同期・自動整合性照合連携API

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/google_drive_client.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Sync-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$expected_token = getenv('CAD_SYNC_TOKEN') ?: 'thankscad_z840_sync_2026';
$provided_token = $_REQUEST['sync_token'] ?? ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');

if ($provided_token !== $expected_token) {
    require_once __DIR__ . '/session_config.php';
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized sync token']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? 'list_pending';

try {
    if ($action === 'list_pending') {
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 15;
        $project_id_filter = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

        $where_proj = $project_id_filter > 0 ? " AND pf.project_id = {$project_id_filter} " : "";

        $sql = "
            SELECT pf.id AS file_id, pf.project_id, pf.file_category, pf.file_name,
                   pf.drive_file_id, pf.version, pf.created_at,
                   p.project_name, p.drive_folder_id AS project_drive_folder_id,
                   u.company_name, u.contact_name
            FROM project_files pf
            JOIN projects p ON pf.project_id = p.id
            LEFT JOIN users u ON p.client_id = u.id
            WHERE pf.is_latest = 1
              AND pf.drive_file_id IS NOT NULL
              AND pf.drive_file_id != ''
              AND (pf.preview_drive_file_id IS NULL OR pf.preview_drive_file_id = '')
              AND (pf.preview_status IS NULL OR pf.preview_status NOT IN ('ready', 'error', 'skipped'))
              AND (
                  LOWER(pf.file_name) LIKE '%.jww'
                  OR LOWER(pf.file_name) LIKE '%.jwc'
                  OR LOWER(pf.file_name) LIKE '%.dxf'
                  OR LOWER(pf.file_name) LIKE '%.zip'
              )
              {$where_proj}
            ORDER BY pf.id DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'count' => count($items),
            'items' => $items
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'download_raw') {
        $file_id = (int)($_GET['file_id'] ?? 0);
        if ($file_id <= 0) {
            http_response_code(400);
            die("Invalid file_id");
        }
        $stmt = $pdo->prepare("SELECT * FROM project_files WHERE id = ?");
        $stmt->execute([$file_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['drive_file_id'])) {
            http_response_code(404);
            die("File not found");
        }

        $drive_id = $row['drive_file_id'];
        // 若し preview_only=1 が指定されており preview_drive_file_id があればプレビューPDFを返す
        if (!empty($_GET['prefer_preview']) && !empty($row['preview_drive_file_id'])) {
            $drive_id = $row['preview_drive_file_id'];
        }

        $content = null;
        if (strpos($drive_id, 'uploads/') === 0) {
            $local_path = __DIR__ . '/' . $drive_id;
            if (file_exists($local_path)) {
                $content = file_get_contents($local_path);
            }
        } else {
            $content = download_google_drive_file($drive_id);
        }

        if ($content === null) {
            http_response_code(500);
            die("Failed to download content");
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($row['file_name']) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    if ($action === 'upload_preview') {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $status  = $_POST['status'] ?? 'ready';
        $notify  = isset($_POST['notify']) ? (int)$_POST['notify'] : 0;

        if ($file_id <= 0) {
            throw new Exception("file_id is required");
        }

        $stmt = $pdo->prepare("
            SELECT pf.*, p.project_name, u.company_name, u.contact_name
            FROM project_files pf
            JOIN projects p ON pf.project_id = p.id
            LEFT JOIN users u ON p.client_id = u.id
            WHERE pf.id = ?
        ");
        $stmt->execute([$file_id]);
        $fileRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fileRow) {
            throw new Exception("Target file row not found: {$file_id}");
        }

        $project_id = (int)$fileRow['project_id'];
        $orig_name = $fileRow['file_name'];
        $base_name = pathinfo($orig_name, PATHINFO_FILENAME);

        if ($status !== 'ready') {
            $stmtUpd = $pdo->prepare("UPDATE project_files SET preview_status = ?, preview_updated_at = NOW() WHERE id = ?");
            $stmtUpd->execute([$status, $file_id]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'ok', 'file_id' => $file_id, 'preview_status' => $status]);
            exit;
        }

        $preview_pdf_drive_id = $_POST['preview_drive_file_id'] ?? null;
        $preview_png_drive_id = $_POST['preview_png_drive_file_id'] ?? null;

        if (isset($_FILES['preview_pdf']) && $_FILES['preview_pdf']['error'] === UPLOAD_ERR_OK) {
            $pdf_filename = "【プレビュー】{$base_name}.pdf";
            $preview_pdf_drive_id = upload_to_google_drive(
                $_FILES['preview_pdf']['tmp_name'],
                $pdf_filename,
                'application/pdf',
                $project_id,
                $pdo
            );
        }

        if (isset($_FILES['preview_png']) && $_FILES['preview_png']['error'] === UPLOAD_ERR_OK) {
            $png_filename = "【プレビュー】{$base_name}.png";
            $preview_png_drive_id = upload_to_google_drive(
                $_FILES['preview_png']['tmp_name'],
                $png_filename,
                'image/png',
                $project_id,
                $pdo
            );
        }

        if (empty($preview_pdf_drive_id)) {
            throw new Exception("No preview_pdf uploaded or drive_id provided");
        }

        $stmtUpd = $pdo->prepare("
            UPDATE project_files
            SET preview_drive_file_id = :pdf_id,
                preview_png_drive_file_id = :png_id,
                preview_status = 'ready',
                preview_updated_at = NOW()
            WHERE id = :fid
        ");
        $stmtUpd->execute([
            'pdf_id' => $preview_pdf_drive_id,
            'png_id' => $preview_png_drive_id,
            'fid'    => $file_id
        ]);

        // 新規アップロード分（直近24時間以内）または notify=1 指定時は管理者へメール通知
        $uploaded_ts = !empty($fileRow['created_at']) ? strtotime($fileRow['created_at']) : 0;
        $is_recent = ($uploaded_ts > 0 && (time() - $uploaded_ts) < 86400);
        $mail_sent = false;

        if ($notify === 1 || ($notify !== -1 && $is_recent)) {
            $adminEmails = getAdminNotificationEmails($pdo);
            $clientLabel = !empty($fileRow['company_name']) ? $fileRow['company_name'] : ($fileRow['contact_name'] ?: '依頼主');
            $projName = $fileRow['project_name'];
            $pdfUrl = (strpos($preview_pdf_drive_id, 'uploads/') === 0)
                ? "https://system.thanks.work/{$preview_pdf_drive_id}"
                : "https://drive.google.com/file/d/{$preview_pdf_drive_id}/view";
            $portalUrl = "https://system.thanks.work/project_detail.php?id={$project_id}";

            $subject = "【設計サポート】CAD図面プレビュー自動作成完了（{$projName}）";
            $body = "{$clientLabel} 様からアップロードされたCAD図面のブラウザ用プレビュー（PDF/PNG）が自動作成されました。\n\n"
                  . "■ 案件名: {$projName}\n"
                  . "■ 元ファイル名: {$orig_name}\n"
                  . "■ プレビューPDFを直接開く:\n{$pdfUrl}\n\n"
                  . "■ ポータル画面で確認・整合性照合を行う:\n{$portalUrl}\n";

            foreach ($adminEmails as $email) {
                if (sendSystemEmail($email, $subject, $body)) {
                    $mail_sent = true;
                }
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'file_id' => $file_id,
            'project_id' => $project_id,
            'preview_drive_file_id' => $preview_pdf_drive_id,
            'preview_png_drive_file_id' => $preview_png_drive_id,
            'mail_sent' => $mail_sent
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'get_project_comparison_docs') {
        $project_id = (int)($_GET['project_id'] ?? 0);
        if ($project_id <= 0) {
            throw new Exception("Invalid project_id");
        }

        $slot_defs = [
            'app'        => ['app_doc'],
            'kanabakari' => ['cad_section', 'pdf_section', 'cad_design_all', 'all_in_one_zip'],
            'section'    => ['cad_cross_section', 'pdf_cross_section', 'cad_design_all', 'all_in_one_zip'],
            'area'       => ['pdf_area_calc', 'cad_plan_1f', 'pdf_plan', 'cad_design_all', 'all_in_one_zip'],
            'elevation'  => ['cad_elevation', 'pdf_elevation', 'cad_design_all', 'all_in_one_zip'],
        ];

        $docs = [];
        foreach ($slot_defs as $key => $cats) {
            $in = implode(',', array_fill(0, count($cats), '?'));
            $params = array_merge([$project_id], $cats);
            $stmt = $pdo->prepare("
                SELECT id AS file_id, file_name, file_category, drive_file_id, preview_drive_file_id, preview_png_drive_file_id, version, created_at
                FROM project_files
                WHERE project_id = ? AND file_category IN ({$in}) AND is_latest = 1
                ORDER BY version DESC, id DESC LIMIT 1
            ");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $docs[$key] = $row;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'project_id' => $project_id,
            'docs' => $docs
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_comparison_result') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        $comparison_json = $_POST['comparison_json'] ?? '';
        if ($project_id <= 0 || empty($comparison_json)) {
            throw new Exception("project_id and comparison_json are required");
        }
        $stmt = $pdo->prepare("UPDATE projects SET cad_comparison_json = ? WHERE id = ?");
        $stmt->execute([$comparison_json, $project_id]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'project_id' => $project_id]);
        exit;
    }

    throw new Exception("Unknown action: {$action}");

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
