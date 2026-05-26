<?php
// migrate_to_drive.php

if (php_sapi_name() !== 'cli') {
    die("このスクリプトはコマンドラインから実行してください。\n");
}

require_once __DIR__ . '/google_drive_client.php';

// 自前でPDO接続を作成（db_connect.php が使えない場合のフォールバック付き）
$host = 'localhost';
$db   = 'mdo3_system';
$user = 'mdo3_system01';
$pass = 'koki2989';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // XAMPP等のローカル環境用のフォールバック（root、パスワードなし）
    try {
        $user_fallback = 'root';
        $pass_fallback = '';
        $pdo = new PDO($dsn, $user_fallback, $pass_fallback, $options);
        echo "【警告】db_connect.phpの認証情報での接続に失敗したため、ローカルの root アカウントで接続しました。\n\n";
    } catch (\PDOException $ex) {
        die("データベース接続失敗: " . $ex->getMessage() . "\n");
    }
}

echo "=== Google Drive API ファイル一括マイグレーションを開始します ===\n";

// 移行対象のレコードを抽出 (drive_file_id が uploads/ から始まるもの)
$stmt = $pdo->prepare("SELECT * FROM project_files WHERE drive_file_id LIKE 'uploads/%'");
$stmt->execute();
$rows = $stmt->fetchAll();

$total = count($rows);
if ($total === 0) {
    echo "移行対象のローカルファイル（uploads/ 配下）は登録されていません。\n";
    echo "処理を終了します。\n";
    exit;
}

echo "対象レコード数: {$total} 件\n\n";

$success_count = 0;
$fail_count = 0;
$skip_count = 0;

foreach ($rows as $row) {
    $id = $row['id'];
    $file_category = $row['file_category'];
    $file_name = $row['file_name'];
    $local_rel_path = $row['drive_file_id'];
    $local_abs_path = __DIR__ . '/' . $local_rel_path;

    echo "[ID: {$id}] カテゴリ: {$file_category} | ファイル名: {$file_name}\n";
    echo "  ローカルパス: {$local_rel_path}\n";

    if (!file_exists($local_abs_path)) {
        echo "  --> 【警告】ローカルファイルが存在しません。スキップします。\n\n";
        $skip_count++;
        continue;
    }

    // MIMEタイプの判定
    $mime_type = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($local_abs_path);
        if ($mime) {
            $mime_type = $mime;
        }
    }

    try {
        echo "  --> Google Driveへアップロード中...\n";
        $drive_file_id = upload_to_google_drive($local_abs_path, $file_name, $mime_type);
        echo "  --> アップロード成功 (ID: {$drive_file_id})\n";

        // DB更新
        $stmtUpdate = $pdo->prepare("UPDATE project_files SET drive_file_id = :drive_id WHERE id = :id");
        $stmtUpdate->execute([
            'drive_id' => $drive_file_id,
            'id' => $id
        ]);
        echo "  --> データベースを更新しました。\n\n";
        $success_count++;
    } catch (Exception $e) {
        echo "  --> 【エラー】移行に失敗しました: " . $e->getMessage() . "\n\n";
        $fail_count++;
    }
}

echo "=== マイグレーション処理が完了しました ===\n";
echo "総件数: {$total} 件\n";
echo "成功: {$success_count} 件\n";
echo "失敗: {$fail_count} 件\n";
echo "スキップ: {$skip_count} 件\n";
