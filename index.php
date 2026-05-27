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
    <title>案件ダッシュボード | 構造設計サポート・ポータル</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&family=Noto+Sans+JP:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --card-bg: rgba(30, 41, 59, 0.6);
            --card-hover: rgba(30, 41, 59, 0.8);
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --accent-color: #10b981;
            --accent-hover: #059669;
            --text-color: #f1f5f9;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.1);
        }
        
        body {
            font-family: 'Outfit', 'Noto Sans JP', sans-serif;
            background: var(--bg-gradient);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            padding: 20px 30px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(to right, #3b82f6, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }

        .role-badge {
            font-size: 11px;
            background: rgba(255,255,255,0.1);
            color: var(--text-color);
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .logout-link {
            color: #ef4444;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .logout-link:hover {
            color: #dc2626;
        }

        .action-bar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 25px;
        }

        .btn-new {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            transition: all 0.3s ease;
        }
        .btn-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 4px; height: 100%;
            background: linear-gradient(to bottom, #3b82f6, #60a5fa);
        }

        .card:hover {
            background: var(--card-hover);
            transform: translateY(-4px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.25);
            border-color: rgba(255,255,255,0.2);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .card h3 {
            margin: 0 0 12px 0;
            font-size: 20px;
            color: var(--text-color);
            font-weight: 600;
            line-height: 1.4;
        }
        
        .client-name {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-detail {
            display: block;
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-color);
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .btn-detail:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: #fff;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px dashed var(--border-color);
            color: var(--text-muted);
        }
        .empty-state h2 {
            color: var(--text-color);
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <div class="header-left">
                <h1 class="logo">PORTAL</h1>
            </div>
            <div class="user-info">
                <div>
                    <?= htmlspecialchars($user['contact_name'], ENT_QUOTES) ?> 様 
                    <span class="role-badge"><?= htmlspecialchars($_SESSION['role'], ENT_QUOTES) ?></span>
                </div>
                <a href="logout.php" class="logout-link">ログアウト</a>
            </div>
        </div>

        <?php if ($_SESSION['role'] === 'client'): ?>
        <div class="action-bar">
            <a href="new_request.php" class="btn-new">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                新規見積・計算依頼
            </a>
        </div>
        <?php endif; ?>

        <?php if (empty($projects)): ?>
            <div class="empty-state">
                <h2>案件がありません</h2>
                <p>現在進行中の案件はありません。「新規見積・計算依頼」から新しい案件を開始してください。</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($projects as $project): ?>
                    <div class="card">
                        <span class="status-badge"><?= $status_labels[$project['status']] ?? '不明' ?></span>
                        <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                        <div class="client-name">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                            <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?>
                        </div>
                        <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn-detail">詳細を開く</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>