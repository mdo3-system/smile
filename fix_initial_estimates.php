<?php
require 'db_connect.php';

try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN initial_est_amount INT NULL");
    $pdo->exec("ALTER TABLE projects ADD COLUMN initial_est_date DATE NULL");
    echo "Columns added.\n";
} catch (Exception $e) {
    echo "Columns might already exist: " . $e->getMessage() . "\n";
}

$stmt = $pdo->query("SELECT id FROM projects WHERE initial_est_amount IS NULL OR initial_est_amount = 0");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $p) {
    $pid = $p['id'];
    $stmtEst = $pdo->prepare("SELECT total_price FROM estimates WHERE project_id = :pid ORDER BY id ASC LIMIT 1");
    $stmtEst->execute(['pid' => $pid]);
    $initial = $stmtEst->fetchColumn();
    if ($initial) {
        $stmtUpdate = $pdo->prepare("UPDATE projects SET initial_est_amount = :amt, initial_est_date = :dt WHERE id = :pid");
        $stmtUpdate->execute(['amt' => $initial, 'dt' => date('Y-m-d'), 'pid' => $pid]);
        echo "Updated Project $pid with $initial\n";
    }
}
echo "Done\n";
