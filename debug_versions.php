<?php
require_once __DIR__ . '/db_connect.php';

echo "=== Categories with multiple records ===\n";
$stmt = $pdo->query("SELECT project_id, file_category, COUNT(*) as cnt FROM project_files GROUP BY project_id, file_category HAVING cnt > 1");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "=== Sample multi-version records ===\n";
foreach (array_slice($rows, 0, 5) as $r) {
    $stmt2 = $pdo->prepare("SELECT id, project_id, file_category, file_name, drive_file_id, version, is_latest, created_at FROM project_files WHERE project_id = :pid AND file_category = :cat ORDER BY version DESC");
    $stmt2->execute(['pid' => $r['project_id'], 'cat' => $r['file_category']]);
    echo "--- Project: {$r['project_id']}, Cat: {$r['file_category']} ---\n";
    print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
}
