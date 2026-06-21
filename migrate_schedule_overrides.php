<?php
// migrate_schedule_overrides.php
require_once 'db_connect.php';

try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    $cols = [
        'schedule_overrides' => 'schedule_actuals',
        'schedule_overrides_wall' => 'schedule_actuals_wall',
        'schedule_overrides_skin' => 'schedule_actuals_skin',
        'schedule_overrides_sky' => 'schedule_actuals_sky'
    ];
    
    foreach ($cols as $col => $after) {
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("ALTER TABLE projects ADD COLUMN $col TEXT DEFAULT NULL");
                echo "SQLite: $col column added successfully.<br>";
            } else {
                // AFTER を使ってきれいに並べる
                $pdo->exec("ALTER TABLE projects ADD COLUMN $col JSON DEFAULT NULL AFTER $after");
                echo "MySQL: $col column added successfully.<br>";
            }
        } catch (Exception $col_e) {
            if (strpos($col_e->getMessage(), 'duplicate column name') !== false || strpos($col_e->getMessage(), 'already exists') !== false || strpos($col_e->getMessage(), 'Duplicate column') !== false) {
                echo "Column $col already exists.<br>";
            } else {
                throw $col_e;
            }
        }
    }
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "<br>";
}
