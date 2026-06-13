<?php
require_once __DIR__ . '/db_connect.php';
try {
    $stmt = $pdo->query("DESCRIBE projects");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "PROJECTS COLUMNS:\n";
    foreach ($cols as $c) {
        echo "- {$c['Field']}: {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
