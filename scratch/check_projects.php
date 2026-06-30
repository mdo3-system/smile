<?php
// scratch/check_projects.php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT id, project_name, status, primary_due_date, req_wall, schedule_actuals, schedule_actuals_wall FROM projects");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo "ID: {$row['id']} | Name: {$row['project_name']} | Status: {$row['status']} | Due: {$row['primary_due_date']} | req_wall: {$row['req_wall']}\n";
    echo "  Actuals: {$row['schedule_actuals']}\n";
    echo "  Actuals Wall: {$row['schedule_actuals_wall']}\n\n";
}
