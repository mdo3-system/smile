<?php
require_once __DIR__ . '/../db_connect.php';
$stmt = $pdo->query("SELECT * FROM users");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo "---------------------------------\n";
}
