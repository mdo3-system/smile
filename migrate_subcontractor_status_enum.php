<?php
// migrate_subcontractor_status_enum.php
// subcontractor_orders.status の ENUM 制限を VARCHAR(20) に変更し、
// 不正値により空文字列になった過去レコードを正しいステータスに修復するスクリプトです。

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

try {
    $pdo->beginTransaction();

    // 1. subcontractor_orders.status カラムのデータ型を VARCHAR(20) に変更
    echo "Changing subcontractor_orders.status Column to VARCHAR(20)...\n";
    $pdo->exec("ALTER TABLE subcontractor_orders MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'requested'");
    echo "Schema updated successfully.\n";

    // 2. 既存の空ステータスレコードを修復
    echo "Repairing empty status records...\n";

    // ID 28 (ProjectID 10): cb_requested
    $stmt28 = $pdo->prepare("UPDATE subcontractor_orders SET status = 'cb_requested' WHERE id = 28 AND (status = '' OR status IS NULL)");
    $stmt28->execute();
    echo "ID 28 (Project 10) repaired: " . $stmt28->rowCount() . " row(s).\n";

    // ID 30 (ProjectID 17): cancelled
    $stmt30 = $pdo->prepare("UPDATE subcontractor_orders SET status = 'cancelled' WHERE id = 30 AND (status = '' OR status IS NULL)");
    $stmt30->execute();
    echo "ID 30 (Project 17) repaired: " . $stmt30->rowCount() . " row(s).\n";

    // ID 24 (ProjectID 7): cancelled
    $stmt24 = $pdo->prepare("UPDATE subcontractor_orders SET status = 'cancelled' WHERE id = 24 AND (status = '' OR status IS NULL)");
    $stmt24->execute();
    echo "ID 24 (Project 7) repaired: " . $stmt24->rowCount() . " row(s).\n";

    // ID 18 (ProjectID 13): cancelled
    $stmt18 = $pdo->prepare("UPDATE subcontractor_orders SET status = 'cancelled' WHERE id = 18 AND (status = '' OR status IS NULL)");
    $stmt18->execute();
    echo "ID 18 (Project 13) repaired: " . $stmt18->rowCount() . " row(s).\n";

    $pdo->commit();
    echo "Data repaired successfully.\n";

    // 3. 整合性を合わせるために各プロジェクトの status を自己修復
    echo "Syncing projects status with schedule...\n";
    $target_projects = [10, 17, 7, 13];
    foreach ($target_projects as $pid) {
        syncProjectStatusWithSchedule($pid, $pdo);
        echo "Project ID {$pid} status synced.\n";
    }

    echo "Migration and repair completed successfully.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
