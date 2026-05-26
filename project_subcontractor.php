<?php
// project_subcontractor.php
require_once 'db_connect.php';

// ★重要：現在はテストのため「業者ID=3」固定ですが、将来的にはログインユーザーIDにします
$sub_id = 3; 

// 自分の担当案件リストを取得
$stmt = $pdo->prepare("
    SELECT o.*, p.project_name, p.status AS project_status 
    FROM subcontractor_orders o 
    JOIN projects p ON o.project_id = p.id 
    WHERE o.subcontractor_id = :sub_id 
    ORDER BY o.created_at DESC
");
$stmt->execute(['sub_id' => $sub_id]);
$my_tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>協力業者専用ポータル</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .task-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 15px; border-left: 5px solid #e67e22; }
        .btn-accept { background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>👷 協力業者専用ダッシュボード</h1>
    <?php foreach ($my_tasks as $task): ?>
        <div class="task-card">
            <h3>案件名: <?= htmlspecialchars($task['project_name'], ENT_QUOTES) ?></h3>
            <p>依頼内容: <?= htmlspecialchars($task['task_title'], ENT_QUOTES) ?></p>
            <p>報酬額: <strong><?= number_format($task['order_amount']) ?>円</strong></p>
            
            <?php if ($task['status'] === 'requested'): ?>
                <form method="POST">
                    <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                    <button type="submit" class="btn-accept">この依頼を承諾する</button>
                </form>
            <?php else: ?>
                <p>状態: <span class="badge"><?= $task['status'] ?></span></p>
                <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>