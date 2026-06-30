<?php
// scratch/dump_project_actuals.php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT * FROM projects");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $p) {
    echo "ID: {$p['id']} | Name: {$p['project_name']}\n";
    echo "  Status: {$p['status']} | Primary Due: '{$p['primary_due_date']}'\n";
    echo "  req_permit: {$p['req_permit']} | req_opt_kisohari: {$p['req_opt_kisohari']} | req_wall: {$p['req_wall']}\n";
    echo "  Actuals: {$p['schedule_actuals']}\n";
    echo "  Actuals Wall: {$p['schedule_actuals_wall']}\n";
    echo "--------------------------------------------------------\n";
}
