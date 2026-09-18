<?php
require_once __DIR__ . '/db_connect.php';

echo "=== Custom deliverables in DB ===\n";
$stmt = $pdo->query("SELECT id, project_id, file_category, file_name, drive_file_id, version, is_latest, created_at FROM project_files WHERE file_category LIKE 'custom_deliverable_%' ORDER BY project_id, id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
