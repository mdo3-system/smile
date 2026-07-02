<?php
// migrate_projects_inspection_reminder.php
require_once 'db_connect.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'inspection_reminder_sent'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN inspection_reminder_sent TINYINT DEFAULT 0 AFTER schedule_actuals_sky");
        echo "Added 'inspection_reminder_sent' column to projects table.\n";
    } else {
        echo "'inspection_reminder_sent' column already exists.\n";
    }
    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
