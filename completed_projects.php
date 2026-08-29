<?php
// completed_projects.php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'client', 'accountant']);

$current_user_id = $_SESSION['user_id'];
require_once 'Repositories/UserRepository.php';
require_once 'Repositories/ProjectRepository.php';
$userRepo = new UserRepository($pdo);
$projectRepo = new ProjectRepository($pdo);
$current_user = $userRepo->findById($current_user_id);

// 管理者による完了差し戻し（進行中へ復帰）POST処理
$flash_msg = $_GET['msg'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revert_completed_status') {
    if ($_SESSION['role'] !== 'admin') {
        die("管理者権限が必要です。");
    }
    $target_pid = intval($_POST['project_id'] ?? 0);
    if ($target_pid > 0) {
        $stmtP = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
        $stmtP->execute(['id' => $target_pid]);
        $target_project = $stmtP->fetch(PDO::FETCH_ASSOC);
        if ($target_project) {
            // ステータスを「審査・待機」へ差し戻し
            $projectRepo->updateStatus($target_pid, 'submitting');

            // スケジュール実績の完了ステップ（最終ステップ）をクリア
            $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
            $stmtAct->execute(['id' => $target_pid]);
            $act_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
            if ($act_row) {
                $cols_to_completed_steps = [
                    'schedule_actuals' => [11],
                    'schedule_actuals_wall' => [8],
                    'schedule_actuals_skin' => [8],
                    'schedule_actuals_sky' => [8],
                ];
                foreach ($cols_to_completed_steps as $col => $steps) {
                    $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
                    foreach ($steps as $step_idx) {
                        if (isset($actuals[$step_idx])) {
                            unset($actuals[$step_idx]);
                        }
                    }
                    $stmtUpdateAct = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                    $stmtUpdateAct->execute(['act' => json_encode($actuals, JSON_FORCE_OBJECT), 'pid' => $target_pid]);
                }
            }

            // チャットへ通知メッセージ登録
            $msg = "【管理者通知】案件の完了状態が取り消され、ステータスが「審査・待機」に差し戻されました。";
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $target_pid,
                'sid' => $current_user_id,
                'msg' => $msg
            ]);
            sendChatEmailNotification($target_pid, $current_user_id, 'admin', 'client_admin', $msg, $pdo);

            syncScheduleDatesToFinance($target_pid, $pdo);

            header("Location: completed_projects.php?msg=" . urlencode("案件「" . $target_project['project_name'] . "」を進行中（審査・待機）に差し戻しました。"));
            exit;
        }
    }
}

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';


// 完了案件のクエリ
if ($_SESSION['role'] === 'client') {
    // 自身または所属グループ（親・子アカウント）の完了案件を取得
    $my_company_id = $_SESSION['parent_id'] ?: $current_user_id;
    $sql = "
        SELECT p.*, u.company_name 
        FROM projects p 
        JOIN users u ON p.client_id = u.id 
        WHERE (p.client_id = :cid OR p.client_id IN (SELECT id FROM users WHERE parent_id = :pid)) 
          AND p.status = 'completed'
    ";
    $params = ['cid' => $my_company_id, 'pid' => $my_company_id];
    if ($search_query !== '') {
        $sql .= " AND p.project_name LIKE :search";
        $params['search'] = '%' . $search_query . '%';
    }
    $sql .= " ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // 管理者または経理は全完了案件
    $sql = "
        SELECT p.*, u.company_name 
        FROM projects p 
        JOIN users u ON p.client_id = u.id 
        WHERE p.status = 'completed'
    ";
    $params = [];
    if ($search_query !== '') {
        $sql .= " AND p.project_name LIKE :search";
        $params['search'] = '%' . $search_query . '%';
    }
    $sql .= " ORDER BY p.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$status_labels = [
    'completed' => '完了'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>完了案件DB（アーカイブ）</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; }
        .back-btn { font-weight:bold; color:white; background:#6b7280; padding:6px 16px; border-radius:4px; text-decoration:none; font-size:13px; }
        .back-btn:hover { background:#4b5563; }
        
        .search-card { background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .search-form { display: flex; gap: 10px; }
        .search-input { flex: 1; padding: 8px 12px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .search-btn { background: #3b82f6; color: white; border: none; padding: 8px 20px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
        .search-btn:hover { background: #2563eb; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #10b981; }
        .card h3 { margin: 0 0 10px 0; font-size: 18px; color: #0f766e; }
        .badge { display: inline-block; padding: 5px 10px; background: #d1fae5; color: #065f46; border-radius: 12px; font-size: 12px; font-weight: bold; margin-bottom: 10px; }
        .client-name { font-size: 14px; color: #666; margin-bottom: 10px; }
        .btn { display: inline-block; padding: 8px 15px; background: #10b981; color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px; text-align: center; }
        .btn:hover { background: #059669; }
        .btn-revert { display: inline-block; padding: 8px 12px; background: #ef4444; color: #fff; border: none; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; }
        .btn-revert:hover { background: #dc2626; }
        .alert-success { background: #ecfdf5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; font-size: 14px; }
        .no-data { text-align: center; color: #64748b; padding: 40px; font-size: 15px; background: white; border-radius: 8px; grid-column: 1 / -1; }
    </style>
</head>
<body>

    <div class="header">
        <h1>📂 完了案件DB（アーカイブ）</h1>
        <div style="display:flex; align-items:center; gap:15px;">
            <a href="index.php" class="back-btn">⬅️ ダッシュボードへ戻る</a>
        </div>
    </div>

    <?php if (!empty($flash_msg)): ?>
        <div class="alert-success">
            ✅ <?= htmlspecialchars($flash_msg, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <!-- 検索フォーム -->
    <div class="search-card">
        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="物件名で検索..." class="search-input" value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>">
            <button type="submit" class="search-btn">検索</button>
            <?php if ($search_query !== ''): ?>
                <a href="completed_projects.php" style="background:#e2e8f0; color:#475569; padding:8px 15px; border-radius:4px; text-decoration:none; font-size:14px; display:flex; align-items:center; justify-content:center;">リセット</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="grid">
        <?php if (empty($projects)): ?>
            <div class="no-data">完了した案件はありません。</div>
        <?php else: ?>
            <?php foreach ($projects as $project): ?>
                <div class="card">
                    <span class="badge">完了</span>
                    <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                    <?php if ($_SESSION['role'] !== 'client'): ?>
                        <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div style="display:flex; gap:10px; align-items:center; margin-top:15px;">
                        <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn">詳細を開く</a>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <form method="POST" action="completed_projects.php" style="margin:0;">
                                <input type="hidden" name="action" value="revert_completed_status">
                                <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                                <button type="submit" class="btn-revert" onclick="return confirm('案件「<?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?>」の完了状態を取り消し、進行中（審査・待機）に差し戻します。よろしいですか？')">🔄 進行中に戻す</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>


</body>
</html>
