<?php
require_once __DIR__ . '/db_connect.php';

$stmt = $pdo->query("SELECT DISTINCT file_category FROM project_files WHERE file_category LIKE '%custom%' OR file_category LIKE '%past%' OR file_category LIKE '%history%' OR file_category LIKE '%version%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT project_id, file_category, file_name, version, is_latest FROM project_files WHERE file_name LIKE '%過去%' OR file_category LIKE '%過去%'");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
