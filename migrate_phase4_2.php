<?php
require 'db_connect.php';

try {
    $pdo->beginTransaction();

    $queries = [
        "ALTER TABLE projects ADD COLUMN billing_company_name VARCHAR(255) DEFAULT NULL;",
        "ALTER TABLE users ADD COLUMN phone_number VARCHAR(50) DEFAULT NULL;"
    ];

    foreach ($queries as $q) {
        try {
            $pdo->exec($q);
            echo "Successfully executed: $q\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "Column already exists, skipping: $q\n";
            } else {
                throw $e;
            }
        }
    }

    $pdo->commit();
    echo "\nPhase 4.2 Migration Completed Successfully!\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Migration Failed: " . $e->getMessage() . "\n";
}
