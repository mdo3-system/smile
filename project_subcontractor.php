<?php
// project_subcontractor.php
require_once 'db_connect.php';

// ★重要：現在はテストのため「業者ID=3」固定ですが、将来的にはログインユーザーIDにします
$sub_id = 3; 

// 承諾処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
    $stmt = $pdo->prepare("UPDATE subcontractor_orders SET status = 'accepted' WHERE id = :id AND subcontractor_id = :sub_id");
    $stmt->execute(['id' => $order_id, 'sub_id' => $sub_id]);
    header("Location: project_subcontractor.php");
    exit;
}

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
    <?php foreach ($my_tasks as $task): 
        // 該当案件の最新のCADファイルを取得
        $stmtFiles = $pdo->prepare("
            SELECT * FROM project_files 
            WHERE project_id = :project_id 
            AND file_category LIKE 'cad_%' 
            AND is_latest = 1
        ");
        $stmtFiles->execute(['project_id' => $task['project_id']]);
        $cad_files = $stmtFiles->fetchAll();
    ?>
        <div class="task-card">
            <h3>案件名: <?= htmlspecialchars($task['project_name'], ENT_QUOTES) ?></h3>
            <p>依頼内容: <?= htmlspecialchars($task['task_title'], ENT_QUOTES) ?></p>
            <p>報酬額: <strong><?= number_format($task['order_amount']) ?>円</strong></p>
            
            <?php if ($task['status'] === 'requested'): ?>
                <form method="POST">
                    <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                    <button type="submit" class="btn-accept">この依頼を承諾する</button>
                </form>
                
                <div class="cad-files-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; color:#c0392b; font-size:13px;">
                    <strong>📂 CADデータダウンロード:</strong>
                    <span style="margin-left:10px; font-weight:bold;">🔒 承諾待ちのため非公開 (依頼承諾後に公開されます)</span>
                </div>
            <?php else: ?>
                <p>状態: <span class="badge" style="background:#28a745; color:white; padding:3px 8px; border-radius:4px;"><?= htmlspecialchars($task['status'], ENT_QUOTES) ?></span></p>
                
                <div class="cad-files-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; font-size:13px;">
                    <strong>📂 CADデータダウンロード:</strong>
                    <?php if (count($cad_files) > 0): ?>
                        <ul style="margin:5px 0 0 0; padding-left:20px;">
                            <?php foreach ($cad_files as $file): ?>
                                <li style="margin-bottom:3px;">
                                    <a href="<?= htmlspecialchars($file['drive_file_id'], ENT_QUOTES) ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">
                                        📄 <?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?> <span class="badge" style="background:#555; color:white; font-size:10px; padding:1px 4px; border-radius:3px;">V<?= $file['version'] ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span style="color:#999; font-size:12px; margin-left:10px;">登録されているCADデータはありません。</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>