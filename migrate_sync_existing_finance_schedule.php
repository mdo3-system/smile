<?php
// migrate_sync_existing_finance_schedule.php
// 既存の財務データ（着手金日・残金日）とスケジュール実績日の双方向一括同期スクリプト

require_once 'db_connect.php';
require_once 'functions.php';

try {
    $stmt = $pdo->query("SELECT id, project_name FROM projects");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Starting one-time migration to sync finance and schedule dates...\n";
    $count = 0;

    foreach ($projects as $project) {
        $pid = $project['id'];
        
        // 1. まずスケジュール実績日 ➔ 財務データ（着手金・残金入金日）への同期を試みる
        syncScheduleDatesToFinance($pid, $pdo);

        // 2. 次に財務データ（着手金・残金入金日）➔ スケジュール実績日への同期を試みる
        // （これにより、どちらか片方しか入っていない場合に、正しく双方向へ伝播します）
        syncFinanceDatesToSchedule($pid, $pdo);

        $count++;
    }

    echo "Migration completed. Successfully processed {$count} projects.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
