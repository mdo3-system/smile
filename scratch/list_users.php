<?php
require_once __DIR__ . '/../db_connect.php';
$stmt = $pdo->query("SELECT id, company_name, contact_name, email, role FROM users");
while ($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Co: {$row['company_name']} | Name: {$row['contact_name']} | Email: {$row['email']} | Role: {$row['role']}\n";
}
