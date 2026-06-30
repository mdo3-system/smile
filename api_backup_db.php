<?php
// api_backup_db.php
require_once 'auth.php';
require_once 'functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\DatabaseBackupService;

// 管理者と経理のみアクセスを許可
check_auth(['admin', 'accountant']);

try {
    $backupService = new DatabaseBackupService($pdo);
    $sqlContent = $backupService->exportDatabaseToSql();

    $zip = new ZipArchive();
    $zipFilename = "db_backup_" . date('Ymd_His') . ".zip";
    $sqlFilename = "backup_" . date('Ymd_His') . ".sql";

    // 一時ファイルを使用してZIPを構築
    $tempZipPath = tempnam(sys_get_temp_dir(), 'db_backup');

    if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $zip->addFromString($sqlFilename, $sqlContent);
        $zip->close();

        // HTTP ヘッダー設定
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Content-Length: ' . filesize($tempZipPath));
        header('Pragma: no-cache');
        header('Expires: 0');

        // ストリーム出力して一時ファイルを削除
        readfile($tempZipPath);
        unlink($tempZipPath);
        exit;
    } else {
        throw new Exception("Failed to create ZIP archive.");
    }
} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    die("データベースのバックアップ作成中にエラーが発生しました: " . $e->getMessage());
}
