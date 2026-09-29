<?php
// migrate_add_cad_comparison_column.php
require_once 'db_connect.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'cad_comparison_json'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN cad_comparison_json LONGTEXT NULL COMMENT 'CAD/図書照合解析結果JSON' AFTER inspection_reminder_sent");
        echo "Added 'cad_comparison_json' column to projects table.\n";
    } else {
        echo "'cad_comparison_json' column already exists.\n";
    }
    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
