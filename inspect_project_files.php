<?php
require_once __DIR__ . '/db_connect.php';
$stmt = $pdo->query("SELECT * FROM project_files WHERE file_category LIKE 'custom_deliverable_%' ORDER BY id DESC LIMIT 50");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
