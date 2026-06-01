<?php
require 'db_connect.php';
$stmt = $pdo->query('SHOW CREATE TABLE subcontractor_orders');
print_r($stmt->fetch());
