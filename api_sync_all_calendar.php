<?php
// api_sync_all_calendar.php
require_once 'auth.php';
require_once 'functions.php';
require_once 'src/Services/GoogleCalendarService.php';

// 管理者のみ
check_auth(['admin']);

$calendarService = new \App\Services\GoogleCalendarService($pdo);

// 全案件のIDを取得して同期
$stmt = $pdo->prepare("SELECT id FROM projects ORDER BY id DESC");
$stmt->execute();
$project_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$success_count = 0;
foreach ($project_ids as $pid) {
    try {
        $calendarService->syncProjectEvents($pid);
        $success_count++;
    } catch (Exception $e) {
        error_log("Failed to sync project {$pid} to Calendar: " . $e->getMessage());
    }
}

header("Location: index.php?msg=" . urlencode("Googleカレンダーに全 {$success_count} 件の案件を同期しました。"));
exit;
