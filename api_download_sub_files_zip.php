<?php
// api_download_sub_files_zip.php
require_once 'auth.php';
require_once 'functions.php';

// アクセス制御
check_auth(['admin', 'accountant', 'subcontractor']);

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    die("案件が指定されていません。");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// 案件情報を取得
$stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = :pid");
$stmtProj->execute(['pid' => $project_id]);
$project = $stmtProj->fetch();

if (!$project) {
    die("指定された案件が見つかりません。");
}

// 協力業者の場合、自分が受注している案件以外へのアクセスを防ぐ
if ($role === 'subcontractor') {
    // 自分またはスタッフがこの案件に紐づく orders に存在するか確認
    // subcontractor_id はセッションの parent_id もしくは user_id に該当
    $stmtUserParent = $pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
    $stmtUserParent->execute(['id' => $user_id]);
    $parent_id = $stmtUserParent->fetchColumn();
    $target_sub_id = $parent_id ? $parent_id : $user_id;

    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM subcontractor_orders 
        WHERE project_id = :pid AND (subcontractor_id = :sub_id OR subcontractor_id IN (SELECT id FROM users WHERE parent_id = :parent_id))
    ");
    $stmtCheck->execute([
        'pid' => $project_id,
        'sub_id' => $target_sub_id,
        'parent_id' => $target_sub_id
    ]);
    if ($stmtCheck->fetchColumn() == 0) {
        die("この案件へのアクセス権限がありません。");
    }
}

// この案件で「公開フラグ = 1」かつ「最新 = 1」の共通図書・CADファイルの一覧を取得
$stmtFiles = $pdo->prepare("
    SELECT * FROM project_files 
    WHERE project_id = :project_id 
      AND (file_category IN ('cad_layout', 'cad_plan_1f', 'cad_plan_2f', 'cad_plan_3f', 'cad_plan_ph', 'cad_plan_rf', 'cad_elevation', 'cad_section', 'app_doc', 'soil_report', 'soil_impr', 'pdf_precut')
        OR file_category LIKE 'custom_%')
      AND is_latest = 1 
      AND is_published_to_sub = 1
");
$stmtFiles->execute(['project_id' => $project_id]);
$shared_files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

if (count($shared_files) === 0) {
    die("ダウンロード可能な公開ファイルが存在しません。");
}

// ZIP作成
$zip = new ZipArchive();
$temp_zip_file = tempnam(sys_get_temp_dir(), 'project_zip');

if ($zip->open($temp_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("ZIPファイルの作成に失敗しました。");
}

require_once 'google_drive_client.php';
$added_files_count = 0;

foreach ($shared_files as $file) {
    $drive_id = $file['drive_file_id'];
    $file_name = $file['file_name'];
    
    if (empty($drive_id)) {
        continue;
    }
    
    // Google Driveからダウンロードするか、ローカルファイルかを判定
    $file_content = null;
    if (strpos($drive_id, 'uploads/') === 0) {
        // ローカル
        $local_path = __DIR__ . '/' . $drive_id;
        if (file_exists($local_path)) {
            $file_content = file_get_contents($local_path);
        }
    } else {
        // Google Drive
        try {
            $file_content = download_google_drive_file($drive_id);
        } catch (Exception $e) {
            error_log("Failed to download file from Drive (ID: {$drive_id}): " . $e->getMessage());
        }
    }
    
    if ($file_content !== null) {
        $zip->addFromString($file_name, $file_content);
        $added_files_count++;
    }
}

$zip->close();

if ($added_files_count === 0) {
    @unlink($temp_zip_file);
    die("ファイルの読み込みに失敗したため、ZIPを作成できませんでした。");
}

// レスポンス出力
$zip_download_name = $project['project_name'] . '_shared_files.zip';
// ファイル名に使えない文字を除去
$zip_download_name = str_replace(['/', '\\', '?', '%', '*', ':', '|', '"', '<', '>'], '_', $zip_download_name);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($zip_download_name) . '"');
header('Content-Length: ' . filesize($temp_zip_file));
header('Pragma: no-cache');
header('Expires: 0');
readfile($temp_zip_file);

@unlink($temp_zip_file);
exit;
