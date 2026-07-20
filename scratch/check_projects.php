<?php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT id, project_name, status, req_permit, req_wall, req_skin, req_sky, req_opt_kisohari FROM projects ORDER BY id DESC LIMIT 15");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $p) {
    echo "ID: {$p['id']} | Name: {$p['project_name']} | Status: {$p['status']} | Permit: {$p['req_permit']} | Wall: {$p['req_wall']} | Skin: {$p['req_skin']} | Sky: {$p['req_sky']} | Kisohari: {$p['req_opt_kisohari']}\n";
}
