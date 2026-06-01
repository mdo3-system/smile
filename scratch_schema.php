<?php
require 'db_connect.php';
$stmt = $pdo->query('SHOW CREATE TABLE messages');
print_r($stmt->fetch());
