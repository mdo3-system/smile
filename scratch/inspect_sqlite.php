<?php
try {
    $pdo = new PDO("sqlite:db.sqlite3");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . implode(', ', $tables) . "\n";
    
    $schema = [];
    foreach ($tables as $t) {
        $stmt = $pdo->query("PRAGMA table_info($t)");
        $schema[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($schema, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
