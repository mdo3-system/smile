<?php
require_once __DIR__ . '/db_connect.php';

try {
    // drive_file_id が NULL または空文字で、かつ is_latest = 0 の不要な空旧バージョンをカウント
    $stmt = $pdo->query("SELECT COUNT(*) FROM project_files WHERE (drive_file_id IS NULL OR drive_file_id = '') AND (file_name = '' OR file_name IS NULL) AND is_latest = 0");
    $count = $stmt->fetchColumn();
    echo "Unused empty old version records: " . $count . PHP_EOL;

    if ($count > 0) {
        $del = $pdo->exec("DELETE FROM project_files WHERE (drive_file_id IS NULL OR drive_file_id = '') AND (file_name = '' OR file_name IS NULL) AND is_latest = 0");
        echo "Deleted empty old records: " . $del . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
