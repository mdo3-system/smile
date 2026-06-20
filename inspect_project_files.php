<?php
require_once __DIR__ . '/db_connect.php';
$stmt = $pdo->query("DESCRIBE project_files");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
