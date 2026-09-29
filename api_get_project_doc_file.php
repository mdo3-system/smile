<?php
// api_get_project_doc_file.php
// 案件の各スロットの最新図書ファイルをバイナリ取得するAPI

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/google_drive_client.php';

// CORS対応 (ローカルパーサーやブラウザからのアクセス用)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

check_auth(['admin', 'client', 'accountant', 'subcontractor']);

$project_id = $_GET['project_id'] ?? null;
$category   = $_GET['category'] ?? null;
$file_id    = $_GET['file_id'] ?? null;

if (!$project_id) {
    http_response_code(400);
    die("案件IDが指定されていません。");
}

try {
    $target_file = null;

    if ($file_id) {
        $stmt = $pdo->prepare("SELECT * FROM project_files WHERE id = ? AND project_id = ?");
        $stmt->execute([$file_id, $project_id]);
        $target_file = $stmt->fetch();
    } elseif ($category) {
        // カテゴリの最新ファイルを取得 (複数の候補カテゴリにも対応)
        $cats = explode(',', $category);
        $in_placeholders = implode(',', array_fill(0, count($cats), '?'));
        $params = array_merge([$project_id], $cats);

        $stmt = $pdo->prepare("
            SELECT * FROM project_files 
            WHERE project_id = ? AND file_category IN ($in_placeholders) AND is_latest = 1 
            ORDER BY version DESC, id DESC LIMIT 1
        ");
        $stmt->execute($params);
        $target_file = $stmt->fetch();
    }

    if (!$target_file || empty($target_file['drive_file_id'])) {
        http_response_code(404);
        die("対象の図書ファイルが見つかりません。");
    }

    $drive_id = $target_file['drive_file_id'];
    $file_name = $target_file['file_name'];
    $file_content = null;

    if (strpos($drive_id, 'uploads/') === 0) {
        $local_path = __DIR__ . '/' . $drive_id;
        if (file_exists($local_path)) {
            $file_content = file_get_contents($local_path);
        }
    } else {
        $file_content = download_google_drive_file($drive_id);
    }

    if ($file_content === null) {
        http_response_code(500);
        die("ファイルのダウンロードに失敗しました。");
    }

    // 拡張子から Content-Type を判定
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $content_type = 'application/octet-stream';
    if ($ext === 'pdf') $content_type = 'application/pdf';
    elseif ($ext === 'dxf') $content_type = 'application/dxf';
    elseif ($ext === 'jww') $content_type = 'application/x-jww';

    header("Content-Type: {$content_type}");
    header('Content-Disposition: inline; filename="' . rawurlencode($file_name) . '"');
    header('Content-Length: ' . strlen($file_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $file_content;
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo "エラー: " . $e->getMessage();
}
