<?php
// scratch/dump_xserver_db.php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT * FROM projects");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$r['id']} | Name: {$r['project_name']}\n";
    echo "  Status: {$r['status']} | Due: '{$r['primary_due_date']}'\n";
    echo "  req_permit: {$r['req_permit']} | req_opt_kisohari: {$r['req_opt_kisohari']} | req_wall: {$r['req_wall']}\n";
    echo "  Actuals: {$r['schedule_actuals']}\n";
    echo "  Actuals Wall: {$r['schedule_actuals_wall']}\n";
    echo "--------------------------------------------------------\n";
}
