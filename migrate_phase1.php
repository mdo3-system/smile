<?php
require_once __DIR__ . '/db_connect.php';

echo "Starting Phase 1 Migration...\n";

// 1. projects table
$projectColumns = [
    'deposit_amount' => 'INT DEFAULT 0',
    'additional_amount' => 'INT DEFAULT 0'
];

foreach ($projectColumns as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE projects ADD COLUMN $col $def");
        echo "Added $col to projects\n";
    } catch (Exception $e) {
        echo "projects.$col may exist: " . $e->getMessage() . "\n";
    }
}

// 2. subcontractor_orders table
$orderColumns = [
    'completed_at' => 'DATETIME NULL',
    'due_date' => 'DATE NULL',
    'order_type' => 'VARCHAR(50) NULL',
    'floor_area' => 'FLOAT NULL',
    'opt_kiso' => 'TINYINT(1) DEFAULT 0',
    'opt_yuka' => 'TINYINT(1) DEFAULT 0'
];

foreach ($orderColumns as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE subcontractor_orders ADD COLUMN $col $def");
        echo "Added $col to subcontractor_orders\n";
    } catch (Exception $e) {
        echo "subcontractor_orders.$col may exist: " . $e->getMessage() . "\n";
    }
}

echo "Migration done.\n";
