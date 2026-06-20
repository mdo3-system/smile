<?php
$_SESSION['user_id'] = 2;
$_SESSION['role'] = 'subcontractor';
$_SESSION['sub_view_mode'] = 'all';

require_once 'db_connect.php';

$user_id = 2;
$target_sub_id = 2;

try {
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id)
        ORDER BY o.created_at DESC
    ");
    $stmtTasks->execute(['sub_id' => $target_sub_id]);
    $tasks = $stmtTasks->fetchAll();
    echo "Success! Count: " . count($tasks) . "\n";
} catch (Exception $e) {
    echo "Error on line 153: " . $e->getMessage() . "\n";
}
