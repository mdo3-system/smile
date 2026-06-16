<?php
require_once __DIR__ . '/../db_connect.php';
$stmt = $pdo->query("DESCRIBE estimates");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
