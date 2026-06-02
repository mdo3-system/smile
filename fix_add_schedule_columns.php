<?php
require_once 'db_connect.php';

try {
    // 壁量計算用
    $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS schedule_actuals_wall JSON DEFAULT NULL AFTER schedule_actuals");
    // 外皮計算用
    $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS schedule_actuals_skin JSON DEFAULT NULL AFTER schedule_actuals_wall");
    // 天空率計算用
    $pdo->exec("ALTER TABLE projects ADD COLUMN IF NOT EXISTS schedule_actuals_sky JSON DEFAULT NULL AFTER schedule_actuals_skin");

    echo "Columns added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
