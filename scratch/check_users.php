<?php
require_once __DIR__ . '/../db_connect.php';
$stmt = $pdo->query("SELECT id, company_name, contact_name, role, email FROM users");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']} | Name: {$row['contact_name']} | Role: {$row['role']} | Email: {$row['email']}\n";
}
