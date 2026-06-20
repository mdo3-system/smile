<?php
require 'db_connect.php';

echo "=== 最近のプロジェクト5件 ===\n";
$stmt = $pdo->query("SELECT id, project_name, status, created_at FROM projects ORDER BY id DESC LIMIT 5");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($projects);

foreach ($projects as $pj) {
    $pid = $pj['id'];
    echo "\n=== プロジェクト ID: {$pid} ({$pj['project_name']}) のファイル一覧 ===\n";
    $stmtFiles = $pdo->prepare("SELECT id, file_category, file_name, is_latest, version FROM project_files WHERE project_id = :pid ORDER BY id ASC");
    $stmtFiles->execute(['pid' => $pid]);
    $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as $f) {
        echo "Category: {$f['file_category']} | Name: {$f['file_name']} | Version: {$f['version']} | Latest: {$f['is_latest']}\n";
    }
}
