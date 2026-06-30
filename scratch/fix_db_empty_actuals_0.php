<?php
// scratch/fix_db_empty_actuals_0.php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT * FROM projects");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cols = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];

$pdo->beginTransaction();
try {
    foreach ($projects as $proj) {
        $due_date = $proj['primary_due_date'];
        if (empty($due_date)) {
            continue; // 期日がないものはスキップ
        }
        
        $updates = [];
        $params = [];
        
        foreach ($cols as $col) {
            $val = $proj[$col];
            $actuals = json_decode($val ?? '{}', true) ?: [];
            
            // インデックス 0 (受領日) が空の場合、期日の日付（または今日の日付）で補完
            if (empty($actuals[0])) {
                $actuals[0] = $due_date; // 安全のために期日と同日を仮設定
                $new_val = json_encode($actuals, JSON_FORCE_OBJECT);
                
                $updates[] = "{$col} = :{$col}";
                $params[$col] = $new_val;
                
                echo "Project ID: {$proj['id']} ({$proj['project_name']}) | Col: {$col} | Filled actuals[0] = {$due_date}\n";
            }
        }
        
        if (!empty($updates)) {
            $params['id'] = $proj['id'];
            $sql = "UPDATE projects SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmtUpdate = $pdo->prepare($sql);
            $stmtUpdate->execute($params);
        }
    }
    $pdo->commit();
    echo "Database missing actuals[0] migration successfully completed.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
