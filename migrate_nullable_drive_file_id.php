<?php
// migrate_nullable_drive_file_id.php
require_once __DIR__ . '/db_connect.php';

try {
    $pdo->beginTransaction();

    // drive_file_id カラムを NULL 許容に変更する
    $query = "ALTER TABLE project_files MODIFY COLUMN drive_file_id VARCHAR(255) DEFAULT NULL";
    $pdo->exec($query);
    echo "Successfully executed: $query\n";

    $pdo->commit();
    echo "\nMigration Completed Successfully!\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
