<?php
require __DIR__ . '/db_connect.php';
header('Content-Type: text/plain; charset=utf-8');

echo "--- USERS (subcontractor) ---\n";
$stmt = $pdo->query("SELECT id, company_name, contact_name, role, phone_number FROM users WHERE role = 'subcontractor'");
print_r($stmt->fetchAll());

echo "\n--- ORDERS ---\n";
$stmt2 = $pdo->query("SELECT id, project_id, subcontractor_id, task_title, status FROM subcontractor_orders");
print_r($stmt2->fetchAll());
