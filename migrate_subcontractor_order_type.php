<?php
// migrate_subcontractor_order_type.php
// 既存の 'structure' となっている subcontractor_orders.order_type を 'struct' に統一し、
// 表記揺れによる不具合を永続的に解消するスクリプトです。

require_once __DIR__ . '/db_connect.php';

try {
    $pdo->beginTransaction();
    
    // structure となっているものを struct にアップデート
    $stmt = $pdo->prepare("UPDATE subcontractor_orders SET order_type = 'struct' WHERE order_type = 'structure'");
    $stmt->execute();
    $affected_rows = $stmt->rowCount();
    
    $pdo->commit();
    echo "Migration completed successfully. Affected rows: {$affected_rows}\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
