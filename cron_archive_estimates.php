<?php
// cron_archive_estimates.php
// 毎日1回 cron から実行され、30日以上経過した見積中案件を自動アーカイブするスクリプト

require_once __DIR__ . '/db_connect.php';

try {
    // 30日前の日付を算出
    $limit_date = date('Y-m-d H:i:s', strtotime('-30 days'));

    // 自動アーカイブ対象を抽出：
    // status = 'quote_req' かつ is_archived = 0 かつ
    // (initial_est_date が 30日前以前、もしくは initial_est_date が空で created_at が 30日前以前)
    $stmt = $pdo->prepare("
        SELECT id, project_name, created_at, initial_est_date 
        FROM projects 
        WHERE status = 'quote_req' 
        AND is_archived = 0
        AND (
            (initial_est_date IS NOT NULL AND initial_est_date <= :limit_date1)
            OR
            (initial_est_date IS NULL AND created_at <= :limit_date2)
        )
    ");
    $stmt->execute([
        'limit_date1' => $limit_date,
        'limit_date2' => $limit_date
    ]);
    
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $archived_count = 0;

    foreach ($projects as $project) {
        $stmtUpd = $pdo->prepare("UPDATE projects SET is_archived = 1 WHERE id = :id");
        $stmtUpd->execute(['id' => $project['id']]);
        $archived_count++;
    }

    echo "Cron run completed. Automatically archived {$archived_count} estimate requests.\n";
} catch (Exception $e) {
    echo "Cron error: " . $e->getMessage() . "\n";
}
