<?php
require 'db_connect.php';
try {
    $db_type = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($db_type === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info(messages)");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($cols);
    } else {
        $stmt = $pdo->query("DESCRIBE messages");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($cols);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
