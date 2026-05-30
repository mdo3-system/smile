<?php
require 'db_connect.php';
$tables = ['projects', 'subcontractor_orders', 'users', 'project_specs'];
$schema = [];
foreach ($tables as $t) {
    try {
        $stmt = $pdo->query("DESCRIBE $t");
        $schema[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
echo json_encode($schema, JSON_PRETTY_PRINT);
