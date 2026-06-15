<?php
require_once __DIR__ . '/../db_connect.php';
$stmt = $pdo->prepare("UPDATE users SET role = 'accountant' WHERE id = 4");
$success = $stmt->execute();
if ($success) {
    echo "ID: 4 user role has been updated to 'accountant'.\n";
} else {
    echo "Failed to update role.\n";
}
