<?php
require_once __DIR__ . '/db_connect.php';

try {
    echo "=== Normalizing file versions for single-file slots ===\n";
    // 実ファイルが存在する project_files を (project_id, file_category) ごとにグループ化
    $stmt = $pdo->query("
        SELECT project_id, file_category, COUNT(*) as cnt, MAX(version) as max_v, MIN(version) as min_v
        FROM project_files
        WHERE (drive_file_id IS NOT NULL AND drive_file_id != '') OR file_name = '【他ファイルに記載】'
        GROUP BY project_id, file_category
        HAVING cnt = 1 AND max_v > 1
    ");
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($slots) . " slots with single file but version > 1.\n";

    $updateStmt = $pdo->prepare("
        UPDATE project_files 
        SET version = 1 
        WHERE project_id = :pid AND file_category = :cat 
          AND ((drive_file_id IS NOT NULL AND drive_file_id != '') OR file_name = '【他ファイルに記載】')
    ");

    $updatedCount = 0;
    foreach ($slots as $s) {
        $updateStmt->execute(['pid' => $s['project_id'], 'cat' => $s['file_category']]);
        $updatedCount++;
        echo "Updated Project {$s['project_id']} [{$s['file_category']}] from V{$s['max_v']} to V1\n";
    }

    echo "Total slots normalized to V1: {$updatedCount}\n";
    echo "\nNormalization Completed Successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
