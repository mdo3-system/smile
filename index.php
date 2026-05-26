<?php
// index.php
require_once 'db_connect.php';

// 管理者（菅原様）のID
$current_user_id = 1;

// 1. ログインユーザー（菅原様）の情報を取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $current_user_id]);
$user = $stmt->fetch();

// 2. 登録されている全案件を、顧客名（company_name）と一緒に取得
$query = "
    SELECT p.*, u.company_name 
    FROM projects p 
    JOIN users u ON p.client_id = u.id 
    ORDER BY p.created_at DESC
";
$projects = $pdo->query($query)->fetchAll();

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
        <div>ログイン中: <?= htmlspecialchars($user['contact_name'], ENT_QUOTES) ?> 様</div>
    </div>

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