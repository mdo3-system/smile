<?php
require 'db_connect.php';

$stmt = $pdo->query("SELECT id FROM projects");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $p) {
    $pid = $p['id'];
    $stmtEst = $pdo->prepare("SELECT total_price FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
    $stmtEst->execute(['pid' => $pid]);
    $latest = $stmtEst->fetchColumn();
    if ($latest) {
        $tax = round($latest * 0.1);
        $grandTotal = $latest + $tax;
        $stmtUpdate = $pdo->prepare("UPDATE projects SET initial_est_amount = :amt, initial_est_date = :dt WHERE id = :pid");
        $stmtUpdate->execute(['amt' => $grandTotal, 'dt' => date('Y-m-d'), 'pid' => $pid]);
        echo "Updated Project $pid with $grandTotal\n";
    }
}
echo "Done\n";
