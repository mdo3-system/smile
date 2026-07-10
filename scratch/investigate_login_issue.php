<?php
// scratch/investigate_login_issue.php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';

echo "=== Running syncProjectStatusWithSchedule for Project ID 10 ===\n";
syncProjectStatusWithSchedule(10, $pdo);

$stmtProj = $pdo->prepare("
    SELECT id, project_name, status, schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky 
    FROM projects 
    WHERE id = 10
");
$stmtProj->execute();
$pj = $stmtProj->fetch(PDO::FETCH_ASSOC);

if ($pj) {
    echo "=== Project ID 10 ===\n";
    echo "Name: {$pj['project_name']}\n";
    echo "Status: {$pj['status']}\n";
    echo "schedule_actuals: {$pj['schedule_actuals']}\n";
    echo "schedule_actuals_wall: {$pj['schedule_actuals_wall']}\n";
    echo "schedule_actuals_skin: {$pj['schedule_actuals_skin']}\n";
    echo "schedule_actuals_sky: {$pj['schedule_actuals_sky']}\n";
    
    echo "\n=== subcontractor_orders ===\n";
    $stmtOrders = $pdo->prepare("SELECT id, status, task_title, order_type FROM subcontractor_orders WHERE project_id = 10");
    $stmtOrders->execute();
    foreach ($stmtOrders->fetchAll(PDO::FETCH_ASSOC) as $o) {
        echo "Order ID: {$o['id']} | Title: {$o['task_title']} | Type: {$o['order_type']} | Status: {$o['status']}\n";
    }
}
