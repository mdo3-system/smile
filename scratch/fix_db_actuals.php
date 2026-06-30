<?php
// scratch/fix_db_actuals.php
require_once __DIR__ . '/../db_connect.php';

$stmt = $pdo->query("SELECT * FROM projects");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cols = [
    'schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky',
    'schedule_overrides', 'schedule_overrides_wall', 'schedule_overrides_skin', 'schedule_overrides_sky'
];

$pdo->beginTransaction();
try {
    foreach ($projects as $proj) {
        $updates = [];
        $params = [];
        
        foreach ($cols as $col) {
            $val = $proj[$col];
            if (empty($val)) {
                continue;
            }
            
            // デコードしてみて配列形式（先頭文字が '['）か確認
            $first_char = substr(trim($val), 0, 1);
            if ($first_char === '[') {
                $decoded = json_decode($val, true) ?: [];
                // 強制的にオブジェクトとしてエンコードし直す
                $new_val = json_encode($decoded, JSON_FORCE_OBJECT);
                
                $updates[] = "{$col} = :{$col}";
                $params[$col] = $new_val;
                
                echo "Project ID: {$proj['id']} | Col: {$col} | Old: {$val} -> New: {$new_val}\n";
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
    echo "Database migration successfully completed.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
