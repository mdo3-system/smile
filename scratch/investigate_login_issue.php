<?php
// scratch/investigate_login_issue.php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../src/Helpers/StatusHelper.php';

$stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = 10");
$stmtProj->execute();
$pj = $stmtProj->fetch(PDO::FETCH_ASSOC);

if ($pj) {
    echo "=== Current Ball Status for Project ID 10 ===\n";
    $ball = \App\Helpers\StatusHelper::getBallStatus($pj, $pdo);
    print_r($ball);
}
