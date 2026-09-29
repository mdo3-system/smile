<?php
require_once 'db_connect.php';
$stmt = $pdo->query("SELECT id, project_id, file_category, file_name, version, drive_file_id FROM project_files ORDER BY id DESC LIMIT 15");
print_r($stmt->fetchAll());
