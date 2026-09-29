<?php
require_once 'db_connect.php';
try {
    $stmt = $pdo->query("
        SELECT u.id, u.company_name, u.contact_name, u.phone_number,
               (SELECT COUNT(*) FROM subcontractor_orders WHERE subcontractor_id = u.id AND status != 'delivered') as active_tasks
        FROM users u
        WHERE u.role = 'subcontractor'
        ORDER BY u.id ASC
    ");
    $res = $stmt->fetchAll();
    echo "SUCCESS: count=" . count($res) . "\n";
    print_r($res);
} catch (Exception $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
