<?php
// admin_sales.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin']); // 経理用・管理者用

$current_month = $_GET['m'] ?? date('Y-m');
$cutoff_day = 25;

// === 1. 依頼主向けの売上・入金・残金一覧 (月別) ===
// 月別のプロジェクト情報を取得（見積承認などの明確な基準がないため、ここでは created_at の月か、あるいはすべてを表示するか。
// 依頼者の要望: "依頼者別の売上合計" "残金がいくらかわかる形" "月別の合計金額"
// => 売上は「その月に作成されたプロジェクト」または「現在アクティブなプロジェクト」で集計
$stmtSales = $pdo->prepare("
    SELECT p.id, p.project_name, p.created_at, p.deposit_amount, p.additional_amount, u.company_name, u.contact_name,
           (SELECT total_price FROM estimates e WHERE e.project_id = p.id ORDER BY e.id DESC LIMIT 1) as formal_estimate
    FROM projects p
    JOIN users u ON p.client_id = u.id
    WHERE DATE_FORMAT(p.created_at, '%Y-%m') = :m
    ORDER BY p.created_at DESC
");
$stmtSales->execute(['m' => $current_month]);
$sales = $stmtSales->fetchAll();

$total_sales = 0;
$total_deposit = 0;
$total_balance = 0;

$sales_by_client = [];
foreach ($sales as $s) {
    $est = $s['formal_estimate'] ?? 0;
    $add = $s['additional_amount'] ?? 0;
    $dep = $s['deposit_amount'] ?? 0;
    $req = $est + $add;
    $bal = $req - $dep;
    
    $total_sales += $req;
    $total_deposit += $dep;
    $total_balance += $bal;
    
    $c_name = trim($s['company_name'] . ' ' . $s['contact_name']);
    if (!isset($sales_by_client[$c_name])) {
        $sales_by_client[$c_name] = ['req' => 0, 'dep' => 0, 'bal' => 0, 'projects' => []];
    }
    $sales_by_client[$c_name]['req'] += $req;
    $sales_by_client[$c_name]['dep'] += $dep;
    $sales_by_client[$c_name]['bal'] += $bal;
    $sales_by_client[$c_name]['projects'][] = [
        'name' => $s['project_name'],
        'req' => $req,
        'dep' => $dep,
        'bal' => $bal
    ];
}

// === 2. 協力業者向けの支払管理 (25日締め) ===
// 締め期間の計算: 前月26日 00:00:00 〜 当月25日 23:59:59
$dt = new DateTime($current_month . '-25 23:59:59');
$end_date = $dt->format('Y-m-d H:i:s');
$dt->modify('-1 month')->modify('+1 day')->setTime(0, 0, 0);
$start_date = $dt->format('Y-m-d H:i:s');

$stmtSubs = $pdo->prepare("
    SELECT o.id, o.task_title, o.order_amount, o.completed_at, u.contact_name, p.project_name
    FROM subcontractor_orders o
    JOIN users u ON o.subcontractor_id = u.id
    JOIN projects p ON o.project_id = p.id
    WHERE o.status = 'completed'
      AND o.completed_at >= :sd AND o.completed_at <= :ed
    ORDER BY u.contact_name, o.completed_at ASC
");
$stmtSubs->execute(['sd' => $start_date, 'ed' => $end_date]);
$sub_orders = $stmtSubs->fetchAll();

$total_payable = 0;
$payments_by_sub = [];
foreach ($sub_orders as $o) {
    $total_payable += $o['order_amount'];
    $sub_name = $o['contact_name'];
    if (!isset($payments_by_sub[$sub_name])) {
        $payments_by_sub[$sub_name] = ['total' => 0, 'tasks' => []];
    }
    $payments_by_sub[$sub_name]['total'] += $o['order_amount'];
    $payments_by_sub[$sub_name]['tasks'][] = $o;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>経理・売上管理 | 構造設計サポート・ポータル</title>
    <style>
        body { font-family: 'Noto Sans JP', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; border-top: 4px solid #3b82f6; }
        .card.card-orange { border-top-color: #e67e22; }
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        .table th, .table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .num { text-align: right !important; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; background: #eee; }
        .month-selector { display:flex; gap:10px; align-items:center; background:white; padding:10px 20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05); margin-bottom:20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0; font-size:24px;">📊 経理・売上管理ダッシュボード</h1>
        <a href="index.php" style="color:#0056b3; text-decoration:none; font-weight:bold;">➔ 案件一覧に戻る</a>
    </div>

    <div class="month-selector">
        <strong>対象月を選択:</strong>
        <form method="GET" style="display:flex; gap:10px;">
            <input type="month" name="m" value="<?= htmlspecialchars($current_month) ?>" style="padding:5px;">
            <button type="submit" style="background:#3b82f6; color:white; border:none; padding:5px 15px; border-radius:4px; cursor:pointer;">表示</button>
        </form>
    </div>

    <!-- 依頼者別 売上・入金・残金 -->
    <div class="card">
        <h2 style="margin-top:0; font-size:18px;">💼 【<?= htmlspecialchars($current_month) ?> 登録案件】依頼主別 売上・残金一覧</h2>
        
        <div style="display:flex; gap:20px; margin-bottom:15px; background:#f8f9fa; padding:15px; border-radius:6px;">
            <div><strong>合計売上(請求額):</strong> <span style="font-size:18px; color:#d32f2f;"><?= number_format($total_sales) ?>円</span></div>
            <div><strong>合計入金済額:</strong> <span style="font-size:18px; color:#28a745;"><?= number_format($total_deposit) ?>円</span></div>
            <div><strong>合計残金(未収金):</strong> <span style="font-size:18px; font-weight:bold;"><?= number_format($total_balance) ?>円</span></div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>依頼主</th>
                    <th>内訳（案件名）</th>
                    <th class="num">請求総額</th>
                    <th class="num">入金済額</th>
                    <th class="num">残金</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_by_client as $client => $data): ?>
                    <tr style="background:#fdfdfd;">
                        <td style="font-weight:bold;"><?= htmlspecialchars($client, ENT_QUOTES) ?></td>
                        <td>
                            <?php foreach ($data['projects'] as $p): ?>
                                <div style="font-size:12px; color:#555;">・<?= htmlspecialchars($p['name'], ENT_QUOTES) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="num" style="font-weight:bold;"><?= number_format($data['req']) ?>円</td>
                        <td class="num" style="color:#28a745;"><?= number_format($data['dep']) ?>円</td>
                        <td class="num" style="color:#d32f2f; font-weight:bold;"><?= number_format($data['bal']) ?>円</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($sales_by_client)): ?>
                    <tr><td colspan="5" style="text-align:center; color:#999;">この月に登録された案件はありません。</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 協力業者 支払管理 -->
    <div class="card card-orange">
        <h2 style="margin-top:0; font-size:18px;">🤝 【<?= htmlspecialchars($current_month) ?> 25日締め分】協力業者 支払額（買掛金）一覧</h2>
        <div style="font-size:12px; color:#666; margin-bottom:10px;">
            ※集計期間: <?= date('Y年m月d日', strtotime($start_date)) ?> 〜 <?= date('Y年m月d日', strtotime($end_date)) ?><br>
            ※管理者が「納品確認・承認」を行った日（納品完了日）を基準に集計しています。
        </div>

        <div style="display:flex; gap:20px; margin-bottom:15px; background:#fff9f0; padding:15px; border-radius:6px;">
            <div><strong>支払予定総額:</strong> <span style="font-size:18px; color:#e67e22; font-weight:bold;"><?= number_format($total_payable) ?>円</span></div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>協力業者様</th>
                    <th>対象タスク（案件名）</th>
                    <th>納品完了日</th>
                    <th class="num">支払額</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments_by_sub as $sub => $data): ?>
                    <tr>
                        <td style="font-weight:bold; vertical-align:top; border-bottom:2px solid #ddd;"><?= htmlspecialchars($sub, ENT_QUOTES) ?></td>
                        <td colspan="2" style="padding:0; border-bottom:2px solid #ddd;">
                            <table style="width:100%; font-size:12px; border-collapse:collapse;">
                                <?php foreach ($data['tasks'] as $t): ?>
                                    <tr>
                                        <td style="padding:5px; border-bottom:1px solid #eee;">
                                            <span style="color:#666;">[<?= htmlspecialchars($t['project_name'], ENT_QUOTES) ?>]</span><br>
                                            <?= htmlspecialchars($t['task_title'], ENT_QUOTES) ?>
                                        </td>
                                        <td style="padding:5px; border-bottom:1px solid #eee; width:120px;">
                                            <?= date('m/d H:i', strtotime($t['completed_at'])) ?>
                                        </td>
                                        <td class="num" style="padding:5px; border-bottom:1px solid #eee; width:100px;">
                                            <?= number_format($t['order_amount']) ?>円
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </td>
                        <td class="num" style="font-weight:bold; color:#e67e22; vertical-align:top; border-bottom:2px solid #ddd;">
                            合計: <?= number_format($data['total']) ?>円
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($payments_by_sub)): ?>
                    <tr><td colspan="4" style="text-align:center; color:#999;">この期間に完了したタスクはありません。</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
