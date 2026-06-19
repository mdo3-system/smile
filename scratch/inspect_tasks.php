<?php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT id, project_name, status FROM projects ORDER BY id DESC LIMIT 5");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== Projects ===\n";
print_r($projects);

$stmt2 = $pdo->query("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id ORDER BY o.id DESC LIMIT 10");
$orders = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== Subcontractor Orders ===\n";
foreach ($orders as $o) {
    echo "ID: {$o['id']}, ProjID: {$o['project_id']}, Sub: {$o['contact_name']}, Title: {$o['task_title']}, Amt: {$o['order_amount']}, Status: {$o['status']}, Type: {$o['order_type']}, Created: {$o['created_at']}\n";
}
