<?php
// completed_sub_orders.php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'subcontractor']);

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// 支払済みの発注一覧を取得するクエリ
$sql = "
    SELECT o.*, p.project_name, u.contact_name as subcontractor_name 
    FROM subcontractor_orders o 
    JOIN projects p ON o.project_id = p.id 
    JOIN users u ON o.subcontractor_id = u.id 
    WHERE o.payment_status = 'paid'
";
$params = [];

// URLパラメータから sub_id を取得 (経理や管理者が特定の業者を見ている場合)
$target_sub_id = isset($_GET['sub_id']) ? intval($_GET['sub_id']) : null;

if (!$is_admin && !$target_sub_id) {
    // 協力業者の場合は自身（およびスタッフ）宛てのもの
    // まず親IDがあるか調べる
    $stmtUserParent = $pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
    $stmtUserParent->execute(['id' => $user_id]);
    $parent_id = $stmtUserParent->fetchColumn();
    $target_sub_id = $parent_id ? $parent_id : $user_id;
}

if ($target_sub_id) {
    $sql .= " AND (o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2))";
    $params['sub_id_1'] = $target_sub_id;
    $params['sub_id_2'] = $target_sub_id;
}

if ($search_query !== '') {
    $sql .= " AND p.project_name LIKE :search";
    $params['search'] = '%' . $search_query . '%';
}

$sql .= " ORDER BY o.completed_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>支払済タスクDB（アーカイブ）</title>
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
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #3b82f6; }
        .card h3 { margin: 0 0 10px 0; font-size: 17px; color: #1e3a8a; }
        .badge { display: inline-block; padding: 5px 10px; background: #dbeafe; color: #1e40af; border-radius: 12px; font-size: 12px; font-weight: bold; margin-bottom: 10px; }
        .order-desc { font-size: 13px; color: #4b5563; margin-bottom: 10px; line-height: 1.5; }
        .btn { display: inline-block; padding: 8px 15px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px; text-align: center; }
        .btn:hover { background: #2563eb; }
        .no-data { text-align: center; color: #64748b; padding: 40px; font-size: 15px; background: white; border-radius: 8px; grid-column: 1 / -1; }
    </style>
</head>
<body>

    <div class="header">
        <h1>📂 支払済タスクDB（アーカイブ）</h1>
        <div style="display:flex; align-items:center; gap:15px;">
            <a href="subcontractor_portal.php<?= $target_sub_id ? '?sub_id=' . intval($target_sub_id) : '' ?>" class="back-btn">⬅️ ポータルへ戻る</a>
        </div>
    </div>

    <!-- 検索フォーム -->
    <div class="search-card">
        <form method="GET" class="search-form">
            <?php if ($target_sub_id): ?>
                <input type="hidden" name="sub_id" value="<?= intval($target_sub_id) ?>">
            <?php endif; ?>
            <input type="text" name="search" placeholder="物件名で検索..." class="search-input" value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>">
            <button type="submit" class="search-btn">検索</button>
            <?php if ($search_query !== ''): ?>
                <a href="completed_sub_orders.php<?= $target_sub_id ? '?sub_id=' . intval($target_sub_id) : '' ?>" style="background:#e2e8f0; color:#475569; padding:8px 15px; border-radius:4px; text-decoration:none; font-size:14px; display:flex; align-items:center; justify-content:center;">リセット</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="grid">
        <?php if (empty($orders)): ?>
            <div class="no-data">支払済みのタスクはありません。</div>
        <?php else: ?>
            <?php foreach ($orders as $o): ?>
                <div class="card">
                    <span class="badge">支払済</span>
                    <h3><?= htmlspecialchars($o['project_name'], ENT_QUOTES) ?></h3>
                    <div class="order-desc">
                        <strong>依頼内容:</strong> <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?><br>
                        <strong>支払額:</strong> <?= number_format($o['order_amount']) ?>円<br>
                        <?php if ($is_admin): ?>
                            <strong>担当:</strong> <?= htmlspecialchars($o['subcontractor_name'], ENT_QUOTES) ?><br>
                        <?php endif; ?>
                        <strong>完了日:</strong> <?= !empty($o['completed_at']) ? date('Y/m/d', strtotime($o['completed_at'])) : '-' ?>
                    </div>
                    <a href="project_subcontractor.php?id=<?= $o['project_id'] ?>" class="btn">詳細を開く</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>
