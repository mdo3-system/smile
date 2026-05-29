<?php
// index.php
require_once 'auth.php';
check_auth(['admin', 'client']);

// 1. ログインユーザーの情報を取得
$current_user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $current_user_id]);
$user = $stmt->fetch();

// 2. 案件の取得（ロールに応じたフィルタ）
if ($_SESSION['role'] === 'client') {
    // クライアントの場合は、自身が依頼主の案件のみ取得
    $query = "
        SELECT p.*, u.company_name 
        FROM projects p 
        JOIN users u ON p.client_id = u.id 
        WHERE p.client_id = :cid
        ORDER BY p.created_at DESC
    ";
    $stmtProj = $pdo->prepare($query);
    $stmtProj->execute(['cid' => $current_user_id]);
    $projects = $stmtProj->fetchAll();
} else {
    // 管理者の場合は全案件を取得
    $query = "
        SELECT p.*, u.company_name 
        FROM projects p 
        JOIN users u ON p.client_id = u.id 
        ORDER BY p.created_at DESC
    ";
    $projects = $pdo->query($query)->fetchAll();
}

// ステータスを日本語表示に変換する用の配列
$status_labels = [
    'quote_req'      => '見積依頼',
    'contracted'     => '受注済',
    'primary_prep'   => '一次回答準備中',
    'structural_dwg' => '構造図作成中',
    'submission'     => '提出済・確認中',
    'correction'     => '補正対応中',
    'completed'      => '完了'
];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>業務管理ポータル</title>
    <style>
        /* 簡単なデザイン（CSS）を適用します */
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #0056b3; }
        .card h3 { margin: 0 0 10px 0; font-size: 18px; color: #0056b3; }
        .badge { display: inline-block; padding: 5px 10px; background: #e9ecef; border-radius: 12px; font-size: 12px; font-weight: bold; margin-bottom: 10px; }
        .client-name { font-size: 14px; color: #666; margin-bottom: 10px; }
        .btn { display: inline-block; padding: 8px 15px; background: #0056b3; color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px; }
        .btn:hover { background: #004494; }
    </style>
</head>
<body>

    <div class="header">
        <h1>💼 案件ダッシュボード</h1>
        <div style="display:flex; align-items:center; gap:15px;">
            <div>ログイン中: <?= htmlspecialchars($user['contact_name'], ENT_QUOTES) ?> 様 <span style="font-size:11px; background:#4b5563; color:white; padding:2px 6px; border-radius:4px; margin-left:5px;"><?= htmlspecialchars($_SESSION['role'], ENT_QUOTES) ?></span></div>
            <a href="logout.php" style="font-size:12px; color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
        </div>
    </div>

    <?php if ($_SESSION['role'] === 'client'): ?>
    <div style="margin-bottom: 20px; text-align: right;">
        <a href="new_request.php" class="btn" style="background:#28a745;">➕ 新規見積・計算依頼</a>
    </div>
    <?php endif; ?>

    <div class="grid">
        <?php foreach ($projects as $project): ?>
            <div class="card">
                <span class="badge"><?= $status_labels[$project['status']] ?? '不明' ?></span>
                <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn">詳細を開く</a>
            </div>
        <?php endforeach; ?>
    </div>

</body>
</html>