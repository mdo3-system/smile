<?php
require_once 'auth.php';
check_auth(['admin', 'accountant']);

$is_admin = ($_SESSION['role'] === 'admin');

// 協力業者の一覧を取得
$stmt = $pdo->query("
    SELECT u.id, u.company_name, u.contact_name, u.phone_number,
           (SELECT COUNT(*) FROM subcontractor_orders WHERE subcontractor_id = u.id AND status != 'delivered') as active_tasks
    FROM users u
    WHERE u.role = 'subcontractor'
    ORDER BY u.id ASC
");
$subcontractors = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>協力業者一覧 - 管理者ポータル</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #f8f9fa; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 14px; }
        th { background: #f1f5f9; font-weight: bold; }
        a.btn { display: inline-block; background: #3b82f6; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; }
        a.btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>👷 協力業者一覧 (マスター)</h2>
            <a href="index.php" style="color:#0056b3; font-weight:bold; text-decoration:none;">➔ 案件一覧に戻る</a>
        </div>
        
        <div class="card">
            <p style="font-size:14px; color:#555; margin-top:0;">登録されている協力業者の一覧です。業者を選択して、案件横断のステータス確認やチャットを行えます。</p>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>会社名</th>
                        <th>担当者名</th>
                        <th>進行中のタスク</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($subcontractors) > 0): ?>
                        <?php foreach ($subcontractors as $sub): ?>
                            <tr>
                                <td><?= $sub['id'] ?></td>
                                <td><?= htmlspecialchars($sub['company_name'] ?? '未設定') ?></td>
                                <td><?= htmlspecialchars($sub['contact_name']) ?></td>
                                <td><?= $sub['active_tasks'] ?> 件</td>
                                <td>
                                    <a href="subcontractor_portal.php?sub_id=<?= $sub['id'] ?>" class="btn">ポータルを開く</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; color:#999;">協力業者が登録されていません。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
