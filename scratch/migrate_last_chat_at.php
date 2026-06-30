<?php
// scratch/migrate_last_chat_at.php
require_once __DIR__ . '/../db_connect.php';

try {
    // ALTER TABLE などの DDL は暗黙のコミット（Implicit Commit）が発生し、トランザクションが終了してしまうため、
    // ここではあえてトランザクションを開始せずに実行します。
    
    // 1. カラムの追加 (カラムが既に存在するかチェックして無ければ追加する)
    echo "Checking column last_manual_chat_at in projects...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM projects LIKE 'last_manual_chat_at'")->fetchAll();
    if (empty($columns)) {
        echo "Adding last_manual_chat_at to projects...\n";
        $pdo->exec("ALTER TABLE projects ADD COLUMN last_manual_chat_at DATETIME DEFAULT NULL");
    } else {
        echo "Column already exists.\n";
    }

    // 2. 過去の手動メッセージの同期
    echo "Syncing past manual messages to projects...\n";
    $stmt = $pdo->query("SELECT id, created_at FROM projects");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtLatestMsg = $pdo->prepare("
        SELECT MAX(created_at) 
        FROM messages 
        WHERE project_id = :pid 
          AND message_text NOT LIKE '【自動通知】%'
          AND message_text NOT LIKE '【経理情報更新】%'
          AND message_text NOT LIKE '【お支払い完了のお知らせ】%'
          AND message_text NOT LIKE '【一次回答%'
          AND message_text NOT LIKE '【一次請求書%'
          AND message_text NOT LIKE '【お振込確認%'
          AND message_text NOT LIKE '【ご入金確認のお知らせ】%'
          AND message_text NOT LIKE '【発注完了通知】%'
    ");

    $stmtUpdate = $pdo->prepare("UPDATE projects SET last_manual_chat_at = :chat_at WHERE id = :pid");

    foreach ($projects as $p) {
        $stmtLatestMsg->execute(['pid' => $p['id']]);
        $latest = $stmtLatestMsg->fetchColumn();

        if ($latest) {
            $stmtUpdate->execute(['chat_at' => $latest, 'pid' => $p['id']]);
            echo "Project ID {$p['id']}: Synced latest chat at {$latest}\n";
        } else {
            // チャットが無い場合は作成日を設定
            $stmtUpdate->execute(['chat_at' => $p['created_at'], 'pid' => $p['id']]);
            echo "Project ID {$p['id']}: No manual chat. Using project creation date {$p['created_at']}\n";
        }
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
