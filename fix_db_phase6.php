<?php
require_once __DIR__ . '/db_connect.php';

echo "<pre>\n";

try {
    $pdo->exec("ALTER TABLE subcontractor_orders ADD COLUMN expected_delivery_date DATE NULL");
    echo "Successfully added 'expected_delivery_date' to subcontractor_orders.\n";
} catch (Exception $e) {
    echo "Column 'expected_delivery_date' may already exist or error: " . $e->getMessage() . "\n";
}

echo "Done.\n</pre>\n";
