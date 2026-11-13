<?php
require_once __DIR__ . '/db_connect.php';

$columnsToAdd = [
    'preview_drive_file_id' => 'VARCHAR(255) DEFAULT NULL',
    'preview_png_drive_file_id' => 'VARCHAR(255) DEFAULT NULL',
    'preview_status' => 'VARCHAR(32) DEFAULT NULL',
    'preview_updated_at' => 'DATETIME DEFAULT NULL',
];

$stmt = $pdo->query("SHOW COLUMNS FROM project_files");
$existingCols = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existingCols[$row['Field']] = true;
}

foreach ($columnsToAdd as $col => $def) {
    if (!isset($existingCols[$col])) {
        $sql = "ALTER TABLE project_files ADD COLUMN {$col} {$def}";
        $pdo->exec($sql);
        echo "[OK] Added column {$col} to project_files\n";
    } else {
        echo "[INFO] Column {$col} already exists in project_files\n";
    }
}

echo "Migration migrate_add_cad_preview.php finished successfully.\n";
