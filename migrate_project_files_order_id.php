<?php
// migrate_project_files_order_id.php
require_once __DIR__ . '/db_connect.php';

echo "Starting project_files subcontractor_order_id migration...\n";

// 1. subcontractor_order_id カラムの追加
try {
    $pdo->exec("ALTER TABLE project_files ADD COLUMN subcontractor_order_id INT NULL AFTER project_id");
    echo "Successfully added subcontractor_order_id column to project_files.\n";
} catch (Exception $e) {
    echo "Column subcontractor_order_id might already exist or error: " . $e->getMessage() . "\n";
}

// 2. 既存データの移行
// 意匠図 (sub_architrend_design) -> subcontractor_orders.order_type = 'design'
// 構造図 (sub_architrend_struct, sub_structural_pdf) -> subcontractor_orders.order_type = 'struct'
try {
    // 既存の案件ごとの発注一覧を取得
    $stmtOrders = $pdo->query("SELECT id, project_id, order_type, status FROM subcontractor_orders ORDER BY created_at DESC");
    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    // 案件ごとにタスクを分類
    $projectTasks = [];
    foreach ($orders as $o) {
        $pid = $o['project_id'];
        $type = $o['order_type'] ?: 'design'; // NULLならデフォルトdesign
        if (!isset($projectTasks[$pid])) {
            $projectTasks[$pid] = ['design' => [], 'struct' => []];
        }
        $projectTasks[$pid][$type][] = $o;
    }

    // project_files から移行対象のデータを取得
    $stmtFiles = $pdo->query("SELECT id, project_id, file_category FROM project_files WHERE file_category IN ('sub_architrend_design', 'sub_architrend_struct', 'sub_structural_pdf') AND subcontractor_order_id IS NULL");
    $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

    $updatedCount = 0;
    foreach ($files as $f) {
        $fid = $f['id'];
        $pid = $f['project_id'];
        $cat = $f['file_category'];

        $targetType = ($cat === 'sub_architrend_design') ? 'design' : 'struct';

        if (isset($projectTasks[$pid][$targetType]) && !empty($projectTasks[$pid][$targetType])) {
            // 最も新しい（または完了している）タスクに紐づける
            // (配列はcreated_at DESC順なので、最初の要素が最新です)
            // ただし、もし status = 'completed' のものがあればそれを優先的に選ぶ
            $selectedOrder = null;
            foreach ($projectTasks[$pid][$targetType] as $task) {
                if ($task['status'] === 'completed') {
                    $selectedOrder = $task;
                    break;
                }
            }
            if (!$selectedOrder) {
                $selectedOrder = $projectTasks[$pid][$targetType][0];
            }

            $stmtUpdate = $pdo->prepare("UPDATE project_files SET subcontractor_order_id = :oid WHERE id = :fid");
            $stmtUpdate->execute(['oid' => $selectedOrder['id'], 'fid' => $fid]);
            $updatedCount++;
        }
    }
    echo "Successfully updated $updatedCount project_files records with subcontractor_order_id.\n";
} catch (Exception $e) {
    echo "Error migrating existing data: " . $e->getMessage() . "\n";
}

echo "Migration complete.\n";
