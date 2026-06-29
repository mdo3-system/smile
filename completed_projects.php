<?php
// completed_projects.php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'client', 'accountant']);

$current_user_id = $_SESSION['user_id'];
require_once 'Repositories/UserRepository.php';
$userRepo = new UserRepository($pdo);
$current_user = $userRepo->findById($current_user_id);

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// 完了案件のクエリ
if ($_SESSION['role'] === 'client') {
    // 自身の完了案件のみ
    $sql = "
        SELECT p.*, u.company_name 
        FROM projects p 
        JOIN users u ON p.client_id = u.id 
        WHERE p.client_id = :cid AND p.status = 'completed'
    ";
    $params = ['cid' => $current_user_id];
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
                    <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn">詳細を開く</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>
