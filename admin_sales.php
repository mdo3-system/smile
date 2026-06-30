<?php
// admin_sales.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'accountant']); // 経理用・管理者用

$current_month = $_GET['m'] ?? date('Y-m');
$msg = $_GET['msg'] ?? '';

// ==========================================
// 1. サービスの初期化
// ==========================================
$financeService = new \App\Services\SalesFinanceService($pdo);

// ==========================================
// 2. POSTデータ処理 (更新保存処理)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sender_id = $_SESSION['user_id'] ?? 1;
    
    if ($action === 'update_deposit') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $deposit_amount = intval($_POST['deposit_amount'] ?? 0);
        $deposit_date = !empty($_POST['deposit_date']) ? $_POST['deposit_date'] : null;
        $status = $_POST['status'] ?? '';
        
        try {
            $financeService->updateDeposit($project_id, $deposit_amount, $deposit_date, $status, $sender_id);
            header("Location: admin_sales.php?m=" . $current_month . "&msg=deposit_updated");
            exit;
        } catch (Exception $e) {
            $error = "入金情報の更新に失敗しました: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_payment') {
        $order_id = intval($_POST['order_id'] ?? 0);
        $payment_status = $_POST['payment_status'] ?? 'unpaid';
        $payment_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        
        try {
            $financeService->updateSubcontractorPayment($order_id, $payment_status, $payment_date, $sender_id);
            header("Location: admin_sales.php?m=" . $current_month . "&msg=payment_updated");
            exit;
        } catch (Exception $e) {
            $error = "支払い情報の更新に失敗しました: " . $e->getMessage();
        }
    }
}

// ==========================================
// 3. データ集計・取得ロジック
// ==========================================
$period = $financeService->getClosingPeriod($current_month);
$start_date = $period['start_date'];
$end_date = $period['end_date'];

$summary = $financeService->getSalesSummary($current_month);
$actual_deposit_total = $summary['actual_deposit_total'];
$actual_payment_total = $summary['actual_payment_total'];
$expected_payment_total = $summary['expected_payment_total'];
$total_sales = $summary['total_sales'];
$total_deposit = $summary['total_deposit'];
$total_balance = $summary['total_balance'];
$sales_list = $summary['sales_list'];

$payments_by_sub = $financeService->getSubcontractorPayments($current_month);

$status_labels = [
    'quote_req'      => '見積依頼',
    'contracted'     => '受注済',
    'primary_prep'   => '一次回答中',
    'structural_dwg' => '申請図書作成中',
    'submission'     => '提出済・確認中',
    'submitting'     => '申請中',
    'correction'     => '補正対応中',
    'completed'      => '完了'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>経理・売上・支払い管理 | 構造設計サポート・ポータル</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif; background: #f8fafc; margin: 0; padding: 20px; color: #1e293b; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; }
        .logo-title { font-size: 24px; font-weight: bold; color: #1e3a8a; display: flex; align-items: center; gap: 10px; }
        .btn-back { color: #2563eb; text-decoration: none; font-weight: bold; font-size: 14px; padding: 8px 16px; border: 1px solid #2563eb; border-radius: 6px; transition: all 0.2s; background: white; }
        .btn-back:hover { background: #eff6ff; }
        
        /* メッセージバッジ */
        .alert-msg { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 12px 20px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; font-weight: bold; }
        
        /* キャッシュフローサマリーカード */
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .summary-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.1); border-left: 6px solid #cbd5e1; }
        .summary-card.card-blue { border-left-color: #3b82f6; }
        .summary-card.card-green { border-left-color: #10b981; }
        .summary-card.card-orange { border-left-color: #f97316; }
        .summary-card.card-red { border-left-color: #ef4444; }
        .summary-card h3 { margin: 0 0 10px 0; font-size: 13px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .summary-value { font-size: 24px; font-weight: bold; color: #0f172a; }
        .summary-desc { font-size: 11px; color: #94a3b8; margin-top: 5px; }

        .month-selector { display:flex; gap:12px; align-items:center; background:white; padding:15px 20px; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:25px; border: 1px solid #e2e8f0; }
        .month-selector select, .month-selector input { padding:8px 12px; border: 1px solid #cbd5e1; border-radius:6px; font-size:14px; color: #334155; }
        .btn-show { background:#2563eb; color:white; border:none; padding:8px 20px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:14px; transition: background 0.2s; }
        .btn-show:hover { background:#1d4ed8; }

        /* メインテーブルカード */
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 25px; border: 1px solid #e2e8f0; }
        .card-title { margin-top:0; margin-bottom:15px; font-size:18px; font-weight:bold; color:#1e293b; display:flex; align-items:center; gap:8px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; text-align: left; }
        .table th { background: #f8fafc; padding: 12px 10px; font-weight: 700; color: #475569; border-bottom: 2px solid #e2e8f0; }
        .table td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .table tr:hover { background-color: #f8fafc; }
        
        .num { text-align: right !important; font-family: 'Courier New', Courier, monospace; font-weight: bold; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; background: #e2e8f0; color: #475569; }
        .badge.badge-success { background: #dcfce7; color: #166534; }
        .badge.badge-warning { background: #fef9c3; color: #854d0e; }
        .badge.badge-danger { background: #fee2e2; color: #991b1b; }
        
        /* 更新フォームコントロール */
        .form-control { width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box; background-color: #fff; color: #334155; }
        .form-control:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1); }
        .btn-save { background: #0f172a; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: bold; transition: background 0.2s; }
        .btn-save:hover { background: #334155; }

        .sub-tasks-table { width: 100%; border-collapse: collapse; margin: 5px 0; background: #f8fafc; border-radius: 6px; overflow: hidden; }
        .sub-tasks-table td { padding: 8px 10px; border-bottom: 1px dashed #e2e8f0; font-size: 12px; }
        .sub-tasks-table tr:last-child td { border-bottom: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-title">📊 経理・売上・支払い管理システム</div>
            <a href="index.php" class="btn-back">➔ 案件一覧に戻る</a>
        </div>

        <?php if ($msg === 'deposit_updated'): ?>
            <div class="alert-msg">✅ 依頼主の入金・ステータス情報を更新しました。</div>
        <?php elseif ($msg === 'payment_updated'): ?>
            <div class="alert-msg">✅ 協力業者への支払い状況を更新しました。</div>
        <?php endif; ?>

        <div class="month-selector">
            <strong>対象月を選択:</strong>
            <form method="GET" style="display:flex; gap:10px; align-items:center;">
                <input type="month" name="m" value="<?= htmlspecialchars($current_month) ?>">
                <button type="submit" class="btn-show">表示切替</button>
            </form>
        </div>

        <!-- キャッシュフローサマリー -->
        <div class="summary-grid">
            <div class="summary-card card-blue">
                <h3>💼 【<?= htmlspecialchars($current_month) ?>】売上確定総額 (本見積＋追加)</h3>
                <div class="summary-value"><?= number_format($total_sales) ?>円</div>
                <div class="summary-desc">※当月登録案件の確定請求総額の合計</div>
            </div>
            <div class="summary-card card-green">
                <h3>💵 【<?= htmlspecialchars($current_month) ?>】実際のご入金総額 (実績)</h3>
                <div class="summary-value"><?= number_format($actual_deposit_total) ?>円</div>
                <div class="summary-desc">※当月に入金日 (`deposit_date`) が記録された額の合計</div>
            </div>
            <div class="summary-card card-orange">
                <h3>🤝 【<?= htmlspecialchars($current_month) ?>】実際のご支払総額 (実績)</h3>
                <div class="summary-value"><?= number_format($actual_payment_total) ?>円</div>
                <div class="summary-desc">※当月に支払日 (`payment_date`) が記録された協力業者支払の合計</div>
            </div>
            <div class="summary-card card-red">
                <h3>💰 【<?= htmlspecialchars($current_month) ?>】支払予定額 (今月締め分)</h3>
                <div class="summary-value"><?= number_format($expected_payment_total) ?>円</div>
                <div class="summary-desc">※<?= htmlspecialchars(date('m/d', strtotime($start_date))) ?> 〜 <?= htmlspecialchars(date('m/d', strtotime($end_date))) ?> 締め期間中に完了した外注予定総額</div>
            </div>
        </div>

        <div style="margin-bottom: 20px; display:flex; gap:10px;">
            <a href="completed_projects.php" style="background:#10b981; color:white; padding:8px 16px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:13px;">📂 完了案件DB (アーカイブ)</a>
            <a href="completed_sub_orders.php" style="background:#3b82f6; color:white; padding:8px 16px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:13px;">📂 協力業者 支払済アーカイブDB</a>
        </div>

        <!-- 1. 依頼主別 売上・入金・ステータス管理 -->
        <div class="card">
            <h2 class="card-title">💼 依頼主別 売上・入金・案件ステータス一覧</h2>
            <div style="font-size:12px; color:#64748b; margin-bottom:15px;">
                ※当月に登録された案件、および現在未入金（残金がある）案件が表示されます。（黄背景行は他月登録の未回収案件）
            </div>
            
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 15%;">依頼主</th>
                        <th style="width: 20%;">案件名 (登録日)</th>
                        <th style="width: 10%;" class="num">請求総額</th>
                        <th style="width: 10%;" class="num">入金済額</th>
                        <th style="width: 10%;" class="num">残金(未収)</th>
                        <th style="width: 12%;">入金日</th>
                        <th style="width: 10%;">案件状況</th>
                        <th style="width: 5%;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales_list as $data): 
                        $tr_style = $data['is_current_month'] ? '' : 'background-color: #fefcbf;';
                        $deposit_status = $data['deposit_status'] ?: 'unpaid';
                        
                        $dep_badge_class = 'badge-danger';
                        if ($deposit_status === 'paid') $dep_badge_class = 'badge-success';
                        elseif ($deposit_status === 'partially_paid') $dep_badge_class = 'badge-warning';
                    ?>
                        <tr style="<?= $tr_style ?>">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_deposit">
                                <input type="hidden" name="project_id" value="<?= $data['id'] ?>">
                                
                                <td>
                                    <strong><?= htmlspecialchars($data['company_name'], ENT_QUOTES) ?></strong><br>
                                    <span style="font-size:11px; color:#64748b;"><?= htmlspecialchars($data['contact_name'], ENT_QUOTES) ?></span>
                                </td>
                                <td>
                                    <a href="project_detail.php?id=<?= $data['id'] ?>" target="_blank" style="color:#2563eb; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($data['project_name'], ENT_QUOTES) ?></a><br>
                                    <span style="font-size:11px; color:#64748b;"><?= date('Y-m-d', strtotime($data['created_at'])) ?></span>
                                </td>
                                <td class="num"><?= number_format($data['req_total']) ?>円</td>
                                <td class="num">
                                    <input type="number" name="deposit_amount" value="<?= htmlspecialchars($data['deposit_amount']) ?>" class="form-control num" style="width:90px; display:inline-block;" required>円
                                </td>
                                <td class="num" style="color:<?= $data['balance'] > 0 ? '#ef4444' : '#10b981' ?>; font-weight:bold;"><?= number_format($data['balance']) ?>円</td>
                                <td>
                                    <input type="date" name="deposit_date" value="<?= htmlspecialchars($data['deposit_date'] ?? '') ?>" class="form-control">
                                </td>
                                <td>
                                    <select name="status" class="form-control">
                                        <?php foreach ($status_labels as $key => $lbl): ?>
                                            <option value="<?= $key ?>" <?= $data['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button type="submit" class="btn-save">保存</button>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sales_list)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:30px 0;">対象の案件はありません。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 2. 協力業者 支払・買掛金管理 (25日締め) -->
        <div class="card" style="border-top: 4px solid #f97316;">
            <h2 class="card-title" style="color:#c2410c;">🤝 協力業者 支払・買掛金状況（<?= htmlspecialchars($current_month) ?> 25日締め分）</h2>
            <div style="font-size:12px; color:#64748b; margin-bottom:15px;">
                ※集計期間: <?= date('Y年m月d日', strtotime($start_date)) ?> 〜 <?= date('Y年m月d日', strtotime($end_date)) ?><br>
                ※管理者が「納品確認・承認」を行い「完了（確認済）」となった協力業者タスクが表示されます。
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 12%;">協力業者</th>
                        <th style="width: 30%;">対象タスク (案件名)</th>
                        <th style="width: 10%;" class="num">支払額 (税込)</th>
                        <th style="width: 11%;">支払予定日</th>
                        <th style="width: 14%;">実際の支払日</th>
                        <th style="width: 10%;">支払状況</th>
                        <th style="width: 7%;">操作</th>
                        <th style="width: 8%;">合計金額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments_by_sub as $sub => $data): ?>
                        <tr>
                            <td style="font-weight:bold; vertical-align:top; border-bottom:2px solid #cbd5e1; padding-top:15px;">
                                👷 <?= htmlspecialchars($sub, ENT_QUOTES) ?> 様
                            </td>
                            <td colspan="6" style="padding:0; border-bottom:2px solid #cbd5e1;">
                                <table class="sub-tasks-table" style="width:100%;">
                                    <?php foreach ($data['tasks'] as $t): 
                                        $pay_status = $t['payment_status'] ?: 'unpaid';
                                    ?>
                                        <tr>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="update_payment">
                                                <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                                
                                                <td style="width: 36%; padding: 5px;">
                                                    <span style="color:#475569; font-weight:600;">[<?= htmlspecialchars($t['project_name'], ENT_QUOTES) ?>]</span><br>
                                                    <span style="font-size:11px; color:#64748b;"><?= htmlspecialchars($t['task_title'], ENT_QUOTES) ?></span> <span style="font-size:10px; color:#94a3b8;">(完了: <?= date('m/d', strtotime($t['completed_at'])) ?>)</span>
                                                </td>
                                                <td style="width: 12%; padding: 5px; white-space: nowrap;" class="num">
                                                    <?= number_format($t['order_amount']) ?>円
                                                </td>
                                                <td style="width: 14%; padding: 5px; color:#64748b; white-space: nowrap;">
                                                    <?= date('Y-m-d', strtotime($current_month . '-25 +1 month')) ?>
                                                </td>
                                                <td style="width: 17%; padding: 5px; white-space: nowrap;">
                                                    <input type="date" name="payment_date" value="<?= htmlspecialchars($t['payment_date'] ?? '') ?>" class="form-control" style="width:105px; font-size:11px; padding:2px;">
                                                </td>
                                                <td style="width: 12%; padding: 5px; white-space: nowrap;">
                                                    <select name="payment_status" class="form-control" style="font-weight:bold; font-size:11px; padding:2px;">
                                                        <option value="unpaid" <?= $pay_status === 'unpaid' ? 'selected' : '' ?>>未払</option>
                                                        <option value="paid" <?= $pay_status === 'paid' ? 'selected' : '' ?>>支払済</option>
                                                    </select>
                                                </td>
                                                <td style="width: 9%; padding: 5px; text-align:center; white-space: nowrap;">
                                                    <button type="submit" class="btn-save" style="font-size:11px; padding:3px 8px;">保存</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </td>
                            <td style="text-align:right; font-weight:bold; color:#f97316; vertical-align:top; border-bottom:2px solid #cbd5e1; padding-top:15px; font-size:14px; white-space:nowrap;">
                                <?= number_format($data['total']) ?>円
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($payments_by_sub)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:30px 0;">この期間に完了（支払対象）となったタスクはありません。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
