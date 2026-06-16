<?php
// admin_sales.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'accountant']); // 経理用・管理者用

$current_month = $_GET['m'] ?? date('Y-m');
$msg = $_GET['msg'] ?? '';

// ==========================================
// 1. POSTデータ処理 (更新保存処理)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_deposit') {
        // 依頼主の入金・ステータス更新
        $project_id = intval($_POST['project_id'] ?? 0);
        $deposit_amount = intval($_POST['deposit_amount'] ?? 0);
        $deposit_date = !empty($_POST['deposit_date']) ? $_POST['deposit_date'] : null;
        $deposit_status = $_POST['deposit_status'] ?? 'unpaid';
        $status = $_POST['status'] ?? ''; // 案件自体のステータス変更も許可する
        
        try {
            $pdo->beginTransaction();
            
            // 変更前データの取得
            $stmtOld = $pdo->prepare("SELECT deposit_amount, deposit_date, deposit_status, status FROM projects WHERE id = :id");
            $stmtOld->execute(['id' => $project_id]);
            $old_data = $stmtOld->fetch(PDO::FETCH_ASSOC);
            
            // 入金情報更新
            $stmt = $pdo->prepare("
                UPDATE projects 
                SET deposit_amount = :dep_amt, 
                    deposit_date = :dep_date, 
                    deposit_status = :dep_status
                WHERE id = :id
            ");
            $stmt->execute([
                'dep_amt' => $deposit_amount,
                'dep_date' => $deposit_date,
                'dep_status' => $deposit_status,
                'id' => $project_id
            ]);
            
            // 案件ステータスの更新（選択されている場合）
            if (!empty($status)) {
                $stmtStatus = $pdo->prepare("UPDATE projects SET status = :status WHERE id = :id");
                $stmtStatus->execute(['status' => $status, 'id' => $project_id]);
            }
            
            // チャットへの自動通知挿入
            if ($old_data) {
                $status_labels_local = [
                    'unpaid' => '未入金',
                    'partially_paid' => '一部入金',
                    'paid' => '完済'
                ];
                $proj_status_labels = [
                    'quote_req' => '見積依頼', 
                    'quote_sent' => '見積送付済', 
                    'doc_submitted' => '図書提出済', 
                    'primary_prep' => '一次回答準備中', 
                    'contracted' => '受注済', 
                    'structural_dwg' => '構造図作成中', 
                    'submission' => '提出済・確認中', 
                    'correction' => '補正対応中', 
                    'completed' => '完了'
                ];
                
                $changes = [];
                if (intval($old_data['deposit_amount']) !== $deposit_amount || $old_data['deposit_status'] !== $deposit_status || $old_data['deposit_date'] !== $deposit_date) {
                    $old_status_text = $status_labels_local[$old_data['deposit_status']] ?? $old_data['deposit_status'];
                    $new_status_text = $status_labels_local[$deposit_status] ?? $deposit_status;
                    $changes[] = "・入金状況: {$old_status_text} ➔ {$new_status_text}\n  入金額: " . number_format($deposit_amount) . "円\n  入金日: " . ($deposit_date ?: '未設定');
                }
                
                if (!empty($status) && $old_data['status'] !== $status) {
                    $old_p_status = $proj_status_labels[$old_data['status']] ?? $old_data['status'];
                    $new_p_status = $proj_status_labels[$status] ?? $status;
                    $changes[] = "・案件ステータス: {$old_p_status} ➔ {$new_p_status}";
                }
                
                if (!empty($changes)) {
                    $sender_id = $_SESSION['user_id'] ?? 1;
                    $msg_text = "【経理情報更新】\n経理担当（または管理者）が案件情報を更新しました。\n" . implode("\n", $changes);
                    
                    $stmtMsg = $pdo->prepare("
                        INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                        VALUES (:pid, :sid, 'client_admin', :msg)
                    ");
                    $stmtMsg->execute([
                        'pid' => $project_id,
                        'sid' => $sender_id,
                        'msg' => $msg_text
                    ]);
                }
            }
            
            $pdo->commit();
            header("Location: admin_sales.php?m=" . $current_month . "&msg=deposit_updated");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "入金情報の更新に失敗しました: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_payment') {
        // 協力業者への支払い状況更新
        $order_id = intval($_POST['order_id'] ?? 0);
        $payment_status = $_POST['payment_status'] ?? 'unpaid';
        $payment_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        
        try {
            $pdo->beginTransaction();
            
            // 変更前データの取得
            $stmtOrder = $pdo->prepare("SELECT project_id, subcontractor_id, task_title, payment_status FROM subcontractor_orders WHERE id = :id");
            $stmtOrder->execute(['id' => $order_id]);
            $order_info = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            
            // 支払い状況更新
            $stmt = $pdo->prepare("
                UPDATE subcontractor_orders 
                SET payment_status = :pay_status,
                    payment_date = :pay_date
                WHERE id = :id
            ");
            $stmt->execute([
                'pay_status' => $payment_status,
                'pay_date' => $payment_date,
                'id' => $order_id
            ]);
            
            // チャットへの自動通知挿入
            if ($order_info && $order_info['payment_status'] !== $payment_status) {
                $pay_status_labels = [
                    'unpaid' => '未払',
                    'paid' => '支払済'
                ];
                $old_p_text = $pay_status_labels[$order_info['payment_status']] ?? $order_info['payment_status'];
                $new_p_text = $pay_status_labels[$payment_status] ?? $payment_status;
                
                $sender_id = $_SESSION['user_id'] ?? 1;
                $msg_text = "【お支払い状況更新】\n経理担当がタスク「{$order_info['task_title']}」のお支払い状況を更新しました。\n・支払状況: {$old_p_text} ➔ {$new_p_text}\n・支払日: " . ($payment_date ?: '未設定');
                
                $stmtMsg = $pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $order_info['project_id'],
                    'sid' => $sender_id,
                    'msg' => $msg_text
                ]);
            }
            
            $pdo->commit();
            header("Location: admin_sales.php?m=" . $current_month . "&msg=payment_updated");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "支払い情報の更新に失敗しました: " . $e->getMessage();
        }
    }
}

// ==========================================
// 2. データ集計・取得ロジック
// ==========================================

// 締め日の設定 (前月26日〜当月25日)
$dt = new DateTime($current_month . '-25 23:59:59');
$end_date = $dt->format('Y-m-d H:i:s');
$dt->modify('-1 month')->modify('+1 day')->setTime(0, 0, 0);
$start_date = $dt->format('Y-m-d H:i:s');

// A. 当月内の「実際に入金された」総額 (実績)
$stmtActualDeposit = $pdo->prepare("
    SELECT SUM(deposit_amount) as total 
    FROM projects 
    WHERE DATE_FORMAT(deposit_date, '%Y-%m') = :m
");
$stmtActualDeposit->execute(['m' => $current_month]);
$actual_deposit_total = $stmtActualDeposit->fetch()['total'] ?? 0;

// B. 当月内の「実際に支払われた」総額 (支払済実績)
$stmtActualPayment = $pdo->prepare("
    SELECT SUM(order_amount) as total 
    FROM subcontractor_orders 
    WHERE DATE_FORMAT(payment_date, '%Y-%m') = :m AND payment_status = 'paid'
");
$stmtActualPayment->execute(['m' => $current_month]);
$actual_payment_total = $stmtActualPayment->fetch()['total'] ?? 0;

// C. 当月締め対象の「支払予定総額（買掛金）」(今月締め分の全タスク)
$stmtExpectedPayment = $pdo->prepare("
    SELECT SUM(order_amount) as total 
    FROM subcontractor_orders 
    WHERE status = 'completed'
      AND completed_at >= :sd AND completed_at <= :ed
");
$stmtExpectedPayment->execute(['sd' => $start_date, 'ed' => $end_date]);
$expected_payment_total = $stmtExpectedPayment->fetch()['total'] ?? 0;


// --- 案件一覧 (売上・入金・残金管理) ---
// 対象月に登録された案件、または「現在未収金がある（残金 > 0）」アクティブな案件を対象とする
$stmtProjects = $pdo->prepare("
    SELECT p.*, u.company_name, u.contact_name,
           (SELECT total_price FROM estimates e WHERE e.project_id = p.id ORDER BY e.id DESC LIMIT 1) as formal_estimate
    FROM projects p
    JOIN users u ON p.client_id = u.id
    WHERE DATE_FORMAT(p.created_at, '%Y-%m') = :m 
       OR (p.deposit_status != 'paid' AND p.status != 'completed')
    ORDER BY p.created_at DESC
");
$stmtProjects->execute(['m' => $current_month]);
$projects = $stmtProjects->fetchAll();

$total_sales = 0;
$total_deposit = 0;
$total_balance = 0;

$sales_list = [];
foreach ($projects as $p) {
    $est = $p['formal_estimate'] ?? 0;
    $add = $p['additional_amount'] ?? 0;
    $dep = $p['deposit_amount'] ?? 0;
    $req = $est + $add;
    $bal = $req - $dep;
    
    // 集計は当月作成された案件のみを対象にする (過年度や別月の未回収案件は除外してサマリーを出すため)
    if (date('Y-m', strtotime($p['created_at'])) === $current_month) {
        $total_sales += $req;
        $total_deposit += $dep;
        $total_balance += $bal;
    }
    
    $sales_list[] = array_merge($p, [
        'req_total' => $req,
        'balance' => $bal,
        'is_current_month' => (date('Y-m', strtotime($p['created_at'])) === $current_month)
    ]);
}


// --- 協力業者 支払管理 (25日締め) ---
$stmtSubs = $pdo->prepare("
    SELECT o.*, u.contact_name, p.project_name
    FROM subcontractor_orders o
    JOIN users u ON o.subcontractor_id = u.id
    JOIN projects p ON o.project_id = p.id
    WHERE o.status = 'completed'
      AND o.completed_at >= :sd AND o.completed_at <= :ed
    ORDER BY u.contact_name, o.completed_at ASC
");
$stmtSubs->execute(['sd' => $start_date, 'ed' => $end_date]);
$sub_orders = $stmtSubs->fetchAll();

$payments_by_sub = [];
foreach ($sub_orders as $o) {
    $sub_name = $o['contact_name'];
    if (!isset($payments_by_sub[$sub_name])) {
        $payments_by_sub[$sub_name] = ['total' => 0, 'tasks' => []];
    }
    $payments_by_sub[$sub_name]['total'] += $o['order_amount'];
    $payments_by_sub[$sub_name]['tasks'][] = $o;
}

$status_labels = [
    'quote_req'      => '見積依頼',
    'contracted'     => '受注済',
    'primary_prep'   => '一次回答中',
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
                        <th style="width: 10%;">入金状況</th>
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
                                    <select name="deposit_status" class="form-control" style="font-weight:bold;">
                                        <option value="unpaid" <?= $deposit_status === 'unpaid' ? 'selected' : '' ?>>未入金</option>
                                        <option value="partially_paid" <?= $deposit_status === 'partially_paid' ? 'selected' : '' ?>>一部入金</option>
                                        <option value="paid" <?= $deposit_status === 'paid' ? 'selected' : '' ?>>完済</option>
                                    </select>
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
                        <tr><td colspan="9" style="text-align:center; color:#94a3b8; padding:30px 0;">対象の案件はありません。</td></tr>
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
                        <th style="width: 15%;">協力業者</th>
                        <th style="width: 40%;">対象タスク (案件名)</th>
                        <th style="width: 10%;" class="num">支払額 (税込)</th>
                        <th style="width: 12%;">支払予定日</th>
                        <th style="width: 12%;">実際の支払日</th>
                        <th style="width: 10%;">支払状況</th>
                        <th style="width: 5%;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments_by_sub as $sub => $data): ?>
                        <tr>
                            <td style="font-weight:bold; vertical-align:top; border-bottom:2px solid #cbd5e1; padding-top:15px;">
                                👷 <?= htmlspecialchars($sub, ENT_QUOTES) ?> 様
                            </td>
                            <td colspan="5" style="padding:0; border-bottom:2px solid #cbd5e1;">
                                <table class="sub-tasks-table">
                                    <?php foreach ($data['tasks'] as $t): 
                                        $pay_status = $t['payment_status'] ?: 'unpaid';
                                    ?>
                                        <tr>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="update_payment">
                                                <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                                
                                                <td style="width: 45%;">
                                                    <span style="color:#475569; font-weight:600;">[<?= htmlspecialchars($t['project_name'], ENT_QUOTES) ?>]</span><br>
                                                    <span style="font-size:11px; color:#64748b;"><?= htmlspecialchars($t['task_title'], ENT_QUOTES) ?></span> (完了: <?= date('m/d H:i', strtotime($t['completed_at'])) ?>)
                                                </td>
                                                <td style="width: 15%;" class="num">
                                                    <?= number_format($t['order_amount']) ?>円
                                                </td>
                                                <td style="width: 15%; color:#64748b;">
                                                    <?= date('Y-m-d', strtotime($current_month . '-25 +1 month')) ?> (翌月25日)
                                                </td>
                                                <td style="width: 15%;">
                                                    <input type="date" name="payment_date" value="<?= htmlspecialchars($t['payment_date'] ?? '') ?>" class="form-control">
                                                </td>
                                                <td style="width: 10%;">
                                                    <select name="payment_status" class="form-control" style="font-weight:bold;">
                                                        <option value="unpaid" <?= $pay_status === 'unpaid' ? 'selected' : '' ?>>未払</option>
                                                        <option value="paid" <?= $pay_status === 'paid' ? 'selected' : '' ?>>支払済</option>
                                                    </select>
                                                </td>
                                                <td style="width: 10%; text-align:center;">
                                                    <button type="submit" class="btn-save">保存</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            </td>
                            <td style="text-align:right; font-weight:bold; color:#f97316; vertical-align:top; border-bottom:2px solid #cbd5e1; padding-top:15px; font-size:14px;">
                                合計: <?= number_format($data['total']) ?>円
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($payments_by_sub)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:30px 0;">この期間に完了（支払対象）となったタスクはありません。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
