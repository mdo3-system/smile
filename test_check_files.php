<?php
require_once 'db_connect.php';
$stmt = $pdo->query("SELECT id, project_name FROM projects ORDER BY id DESC LIMIT 5");
$projects = $stmt->fetchAll();
print_r($projects);

if (!empty($projects)) {
    $pid = $projects[0]['id'];
    echo "\n=== Files for Project ID $pid ===\n";
    $stmtF = $pdo->prepare("SELECT id, project_id, file_category, file_name, version, is_latest, drive_file_id FROM project_files WHERE project_id = ? ORDER BY version DESC, id DESC");
    $stmtF->execute([$pid]);
    print_r($stmtF->fetchAll());
}
