<?php
require 'db_connect.php';
$stmt = $pdo->query('SELECT status FROM projects WHERE id=9');
$res = $stmt->fetch();
echo "Status of project 9: " . ($res ? $res['status'] : 'not found');
