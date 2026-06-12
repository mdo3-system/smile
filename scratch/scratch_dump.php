<?php
require_once __DIR__ . '/db_connect.php';
try {
    $stmt = $pdo->query("SELECT * FROM messages");
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "MESSAGES ROWS:\n";
    print_r($messages);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
