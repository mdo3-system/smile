<?php
// migrate_nullable_drive_file_id.php
require_once __DIR__ . '/db_connect.php';

try {
    // ALTER TABLE は MySQL で暗黙的なコミット（Implicit Commit）を伴うためトランザクションは使用しない
    $query = "ALTER TABLE project_files MODIFY COLUMN drive_file_id VARCHAR(255) DEFAULT NULL";
    $pdo->exec($query);
    echo "Successfully executed: $query\n";
    echo "\nMigration Completed Successfully!\n";

} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
