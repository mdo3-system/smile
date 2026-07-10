<?php
// scratch/investigate_login_issue.php
require_once __DIR__ . '/../db_connect.php';

$stmtProj = $pdo->prepare("SELECT id, project_name, req_permit, req_opt_kisohari, req_wall, req_skin, req_sky FROM projects WHERE id = 10");
$stmtProj->execute();
$pj = $stmtProj->fetch(PDO::FETCH_ASSOC);

if ($pj) {
    echo "=== Column values for Project ID 10 ===\n";
    foreach ($pj as $col => $val) {
        echo "{$col}: '{$val}'\n";
    }
}
