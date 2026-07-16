<?php
require_once __DIR__ . '/db_connect.php';
$stmt = $pdo->query("SELECT * FROM project_files WHERE drive_file_id LIKE 'http%' OR drive_file_id LIKE '%project_detail%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
