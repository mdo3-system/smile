<?php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT id, project_id, task_title, order_type, order_amount, status FROM subcontractor_orders ORDER BY id DESC LIMIT 10");
$rows = $stmt->fetchAll();

echo "ID | ProjectID | TaskTitle | OrderType | OrderAmount | Status\n";
echo "-------------------------------------------------------------\n";
foreach ($rows as $row) {
    echo "{$row['id']} | {$row['project_id']} | {$row['task_title']} | {$row['order_type']} | {$row['order_amount']} | {$row['status']}\n";
}
