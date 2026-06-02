<?php
require 'db_connect.php';
$stmt = $pdo->query('SELECT * FROM projects ORDER BY id DESC LIMIT 1');
print_r($stmt->fetch(PDO::FETCH_ASSOC));
