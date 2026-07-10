<?php
// scratch/investigate_login_issue.php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';

$stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = 10");
$stmtProj->execute();
$project = $stmtProj->fetch(PDO::FETCH_ASSOC);

if ($project) {
    $base_days = getScheduleBaseDays($project);
    $is_koyou_or_kisohari = (($project['req_permit'] ?? 0) == 1 || ($project['req_opt_kisohari'] ?? 0) == 1);
    $req_wall = (int)($project['req_wall'] ?? 0);
    $req_skin = (int)($project['req_skin'] ?? 0);
    $req_sky = (int)($project['req_sky'] ?? 0);
    
    $steps = getScheduleSteps($base_days, $is_koyou_or_kisohari);
    $actuals_col = 'schedule_actuals';
    
    if ($req_wall) {
        $steps = getScheduleStepsWall($base_days);
        $actuals_col = 'schedule_actuals_wall';
    } elseif ($req_skin) {
        $steps = getScheduleStepsSkin($base_days);
        $actuals_col = 'schedule_actuals_skin';
    } elseif ($req_sky) {
        $steps = getScheduleStepsSky($base_days);
        $actuals_col = 'schedule_actuals_sky';
    }
    
    $actuals = json_decode($project[$actuals_col] ?? '{}', true) ?: [];
    
    echo "=== Steps ===\n";
    foreach ($steps as $idx => $step) {
        echo "Index {$idx}: {$step['name']} (actor: {$step['actor']})\n";
    }
    
    echo "\n=== Actuals ===\n";
    print_r($actuals);
    
    $submission_idx = -1;
    foreach ($steps as $idx => $step) {
        if ($step['name'] === '構造図UP' || $step['name'] === '申請図書一式UP') {
            $submission_idx = $idx;
        }
    }
    
    echo "\nsubmission_idx detected: {$submission_idx}\n";
    $is_sub_task_effectively_done = false;
    if ($submission_idx !== -1 && !empty($actuals[$submission_idx])) {
        $is_sub_task_effectively_done = true;
    }
    echo "is_sub_task_effectively_done: " . ($is_sub_task_effectively_done ? 'true' : 'false') . "\n";
}
