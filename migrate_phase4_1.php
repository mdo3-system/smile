<?php
// migrate_phase4_1.php
require 'db_connect.php';

try {
    $pdo->beginTransaction();

    $queries = [
        "ALTER TABLE projects ADD COLUMN billing_company_name VARCHAR(255) DEFAULT NULL;",
        "ALTER TABLE projects ADD COLUMN billing_phone_number VARCHAR(255) DEFAULT NULL;",
        "ALTER TABLE project_files ADD COLUMN update_reason TEXT DEFAULT NULL;"
    ];

    foreach ($queries as $q) {
        try {
            $pdo->exec($q);
            echo "Successfully executed: $q\n";
        } catch (PDOException $e) {
            // カラムが既に存在する場合のエラーは無視
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "Column already exists, skipping: $q\n";
            } else {
                throw $e;
            }
        }
    }

    $pdo->commit();
    echo "\nPhase 4.1 Migration Completed Successfully!\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Migration Failed: " . $e->getMessage() . "\n";
}
