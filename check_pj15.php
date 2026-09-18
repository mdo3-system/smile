<?php
require_once __DIR__ . '/db_connect.php';

$stmt = $pdo->query("SELECT id, project_id, file_category, file_name, version, is_latest, drive_file_id FROM project_files WHERE project_id = 15");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
