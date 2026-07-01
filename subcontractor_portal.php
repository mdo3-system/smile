<?php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'subcontractor', 'accountant']);

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$is_accountant = ($_SESSION['role'] === 'accountant');
$has_finance_access = ($is_admin || $is_accountant);

// 表示対象の協力業者IDを決定
$target_sub_id = 0;
if ($is_admin) {
    $target_sub_id = intval($_GET['sub_id'] ?? 0);
    if ($target_sub_id === 0) {
        die("業者IDが指定されていません。");
    }
} else {
    $target_sub_id = $user_id;
    $stmtParent = $pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
    $stmtParent->execute(['id' => $user_id]);
    $p_id = $stmtParent->fetchColumn();
    if ($p_id) {
        $target_sub_id = $p_id;
    }
}

// 業者情報を取得
$stmtSub = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'subcontractor'");
$stmtSub->execute(['id' => $target_sub_id]);
$subcontractor = $stmtSub->fetch();
if (!$subcontractor) {
    die("指定された業者は存在しません。");
}

// POST処理（チャット送信・支払い記録）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // グローバルチャット送信
    if ($action === 'send_global_message') {
        $message_text = trim($_POST['message_text'] ?? '');
        $drive_file_id = '';
        $file_type = '';

        if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK) {
            require_once 'google_drive_client.php';
            $file_tmp = $_FILES['chat_file']['tmp_name'];
            $file_name = $_FILES['chat_file']['name'];
            $mime_type = $_FILES['chat_file']['type'];
            $drive_file_id = upload_to_google_drive($file_tmp, $file_name, $mime_type);
            $file_type = (strpos($mime_type, 'image') === 0) ? 'image' : 'file';
        }

        if ($message_text !== '' || $drive_file_id !== '') {
            $stmt = $pdo->prepare("INSERT INTO global_messages (subcontractor_id, sender_id, message_text, file_path, file_type) VALUES (:sub_id, :sid, :msg, :fpath, :ftype)");
            $stmt->execute([
                'sub_id' => $target_sub_id,
                'sid' => $user_id,
                'msg' => $message_text,
                'fpath' => $drive_file_id,
                'ftype' => $file_type
            ]);
        }
        header("Location: subcontractor_portal.php" . ($is_admin ? "?sub_id=" . $target_sub_id : ""));
        exit;
    }
    
    // 支払い記録の保存 (管理者・経理)
    if ($action === 'log_sub_payment' && $has_finance_access) {
        $target_month = $_POST['target_month'] ?? '';
        $paid_amount = intval($_POST['paid_amount'] ?? 0);
        $note = $_POST['note'] ?? '';
        
        if ($target_month !== '') {
            $pdo->beginTransaction();
            try {
                // UPSERT処理
                $stmt = $pdo->prepare("
                    INSERT INTO subcontractor_payments (subcontractor_id, target_month, paid_amount, paid_at, note) 
                    VALUES (:sub_id, :t_month, :amt, NOW(), :note)
                    ON DUPLICATE KEY UPDATE paid_amount = :amt_update, paid_at = NOW(), note = :note_update
                ");
                $stmt->execute([
                    'sub_id' => $target_sub_id,
                    't_month' => $target_month,
                    'amt' => $paid_amount,
                    'note' => $note,
                    'amt_update' => $paid_amount,
                    'note_update' => $note
                ]);

                // 協力業者チャット（global_messages）へ自動通知メッセージを投稿
                $payment_msg = "【お支払い完了のお知らせ】\n";
                $payment_msg .= "{$target_month} 納品完了分につきまして、お支払いが完了いたしました。\n\n";
                $payment_msg .= "支払金額: " . number_format($paid_amount) . " 円\n";
                $payment_msg .= "支払日時: " . date('Y年m月d日 H:i') . "\n";
                if (!empty($note)) {
                    $payment_msg .= "備考: {$note}\n";
                }
                $payment_msg .= "\nご確認のほど、よろしくお願い申し上げます。";

                $stmtMsg = $pdo->prepare("
                    INSERT INTO global_messages (subcontractor_id, sender_id, message_text) 
                    VALUES (:sub_id, :sender_id, :msg)
                ");
                $stmtMsg->execute([
                    'sub_id' => $target_sub_id,
                    'sender_id' => $user_id,
                    'msg' => $payment_msg
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                die("支払記録の保存に失敗しました: " . $e->getMessage());
            }
        }
        header("Location: subcontractor_portal.php?sub_id=" . $target_sub_id);
        exit;
    }

    // 請求書のアップロード (協力業者)
    if ($action === 'upload_sub_invoice') {
        $target_month = $_POST['target_month'] ?? '';
        
        if ($target_month !== '' && isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            require_once 'google_drive_client.php';
            $file_tmp = $_FILES['invoice_file']['tmp_name'];
            $file_name = $_FILES['invoice_file']['name'];
            $mime_type = $_FILES['invoice_file']['type'];
            
            try {
                $pdo->beginTransaction();
                
                // 協力業者フォルダの取得・作成
                $folder_id = get_or_create_subcontractor_drive_folder($pdo, $target_sub_id);
                // アップロード
                $drive_file_id = upload_to_google_drive_folder($file_tmp, $file_name, $mime_type, $folder_id);
                
                // subcontractor_payments テーブルの更新 (paid_amountは既存がある場合に上書きされないよう ON DUPLICATE では指定しない)
                $stmt = $pdo->prepare("
                    INSERT INTO subcontractor_payments (subcontractor_id, target_month, invoice_file_path, invoice_file_name, paid_amount)
                    VALUES (:sub_id, :t_month, :fpath, :fname, 0)
                    ON DUPLICATE KEY UPDATE invoice_file_path = :fpath_update, invoice_file_name = :fname_update
                ");
                $stmt->execute([
                    'sub_id'        => $target_sub_id,
                    't_month'       => $target_month,
                    'fpath'         => $drive_file_id,
                    'fname'         => $file_name,
                    'fpath_update'  => $drive_file_id,
                    'fname_update'  => $file_name
                ]);
                
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                die("請求書のアップロードに失敗しました: " . $e->getMessage());
            }
        }
        header("Location: subcontractor_portal.php" . ($is_admin ? "?sub_id=" . $target_sub_id : ""));
        exit;
    }
}

// 表示モードの判定（担当者ベース or 業者全体）
$sub_view_mode = $_SESSION['sub_view_mode'] ?? 'all';
if (isset($_GET['sub_view_mode'])) {
    $sub_view_mode = ($_GET['sub_view_mode'] === 'personal') ? 'personal' : 'all';
    $_SESSION['sub_view_mode'] = $sub_view_mode;
}

// スケジュール（進行中のタスク一覧）
// 招待されたスタッフ（parent_id がある）かつ「自分の案件のみ（personal）」の場合は自分宛てのタスクに制限する
$stmtUserParent = $pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
$stmtUserParent->execute(['id' => $user_id]);
$has_parent = (bool)$stmtUserParent->fetchColumn();

if ($has_parent && $sub_view_mode === 'personal') {
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, p.schedule_actuals, p.req_permit, p.req_wall, p.req_skin, p.req_sky, p.req_opt_kisohari 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :user_id AND o.payment_status != 'paid'
        ORDER BY ISNULL(p.last_manual_chat_at) ASC, p.last_manual_chat_at DESC, ISNULL(p.primary_due_date) ASC, p.primary_due_date ASC, FIELD(p.status, 'quote_req', 'doc_submitted', 'primary_prep', 'contracted', 'structural_dwg', 'submission', 'submitting', 'correction', 'completed') ASC, p.project_name ASC
    ");
    $stmtTasks->execute(['user_id' => $user_id]);
} else {
    // 業者全体（本アカウント宛て ＋ スタッフ宛て）またはメインアカウントの場合
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, p.schedule_actuals, p.req_permit, p.req_wall, p.req_skin, p.req_sky, p.req_opt_kisohari 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE (o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2)) AND o.payment_status != 'paid'
        ORDER BY ISNULL(p.last_manual_chat_at) ASC, p.last_manual_chat_at DESC, ISNULL(p.primary_due_date) ASC, p.primary_due_date ASC, FIELD(p.status, 'quote_req', 'doc_submitted', 'primary_prep', 'contracted', 'structural_dwg', 'submission', 'submitting', 'correction', 'completed') ASC, p.project_name ASC
    ");
    $stmtTasks->execute([
        'sub_id_1' => $target_sub_id,
        'sub_id_2' => $target_sub_id
    ]);
}
$tasks = $stmtTasks->fetchAll();

// 案件（物件）ごとのグルーピング
$project_tasks = [];
foreach ($tasks as $t) {
    $pid = $t['project_id'];
    if (!isset($project_tasks[$pid])) {
        $project_tasks[$pid] = [
            'project_name' => $t['project_name'],
            'project_id' => $pid,
            'project_status' => $t['project_status'],
            'primary_due_date' => $t['primary_due_date'],
            'schedule_actuals' => $t['schedule_actuals'],
            'req_permit' => $t['req_permit'],
            'req_wall' => $t['req_wall'],
            'req_skin' => $t['req_skin'],
            'req_sky' => $t['req_sky'],
            'req_opt_kisohari' => $t['req_opt_kisohari'],
            'items' => []
        ];
    }
    $project_tasks[$pid]['items'][] = $t;
}

// 月次集計データの作成 (25日締め)
$monthly_totals = [];
foreach ($tasks as $t) {
    if ($t['status'] === 'completed') {
        // completed_at を最優先、無ければ updated_at, created_at を完了日として月を判定
        $date_str = $t['completed_at'] ?? $t['updated_at'] ?? $t['created_at'];
        $ts = strtotime($date_str);
        
        $y = (int)date('Y', $ts);
        $m = (int)date('m', $ts);
        $d = (int)date('d', $ts);
        
        // 26日以降なら翌月分としてカウント
        if ($d >= 26) {
            $m++;
            if ($m > 12) {
                $m = 1;
                $y++;
            }
        }
        $month = sprintf("%04d-%02d", $y, $m);
        
        if (!isset($monthly_totals[$month])) {
            $monthly_totals[$month] = 0;
        }
        $monthly_totals[$month] += intval($t['order_amount']);
    }
}
krsort($monthly_totals); // 最新月順にソート

// 支払い記録の取得
$stmtPayments = $pdo->prepare("SELECT * FROM subcontractor_payments WHERE subcontractor_id = :sub_id");
$stmtPayments->execute(['sub_id' => $target_sub_id]);
$payments = [];
foreach ($stmtPayments->fetchAll() as $p) {
    $payments[$p['target_month']] = $p;
}

// グローバルチャット履歴の取得
$stmtChat = $pdo->prepare("
    SELECT m.*, u.contact_name, u.role 
    FROM global_messages m 
    JOIN users u ON m.sender_id = u.id 
    WHERE m.subcontractor_id = :sub_id 
    ORDER BY m.created_at ASC
");
$stmtChat->execute(['sub_id' => $target_sub_id]);
$global_messages = $stmtChat->fetchAll();


?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>協力業者専用ポータル - <?= htmlspecialchars($subcontractor['contact_name']) ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; display: flex; gap: 20px; }
        .col-main { flex: 2; }
        .col-side { flex: 1; display: flex; flex-direction: column; gap: 20px; }
        .box { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        h2, h3 { margin-top: 0; }
        .task-card { border: 1px solid #e2e8f0; border-left: 4px solid #3b82f6; padding: 15px; border-radius: 4px; margin-bottom: 10px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; }
        
        /* 招待リンク用ツールチップ */
        .tooltip-btn-container {
            position: relative;
            display: inline-block;
        }
        .tooltip-btn-container .tooltip-text {
            visibility: hidden;
            width: 320px;
            background-color: #1e293b;
            color: #fff;
            text-align: left;
            border-radius: 6px;
            padding: 12px;
            position: absolute;
            z-index: 100;
            top: 125%;
            left: 50%;
            margin-left: -160px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 11px;
            line-height: 1.4;
            font-weight: normal;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            pointer-events: none;
        }
        .tooltip-btn-container .tooltip-text::after {
            content: "";
            position: absolute;
            bottom: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: transparent transparent #1e293b transparent;
        }
        .tooltip-btn-container:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
            .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 15px; margin-top: 15px; }
        .card { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; border-left: 5px solid #ccc; min-height: 180px; }
        .card h3 { margin: 0 0 10px 0; font-size: 15px; color: #1e3a8a; }
    </style>
</head>
<body>
    <div style="max-width:1200px; margin: 0 auto 15px; display:flex; justify-content:space-between; align-items:center;">
        <h2><?= htmlspecialchars($subcontractor['contact_name']) ?> 様 - 協力業者ポータル</h2>
        <div style="display:flex; align-items:center; gap:15px;">
            <?php if (!$is_admin): ?>
                <?php
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $script_dir = dirname($_SERVER['SCRIPT_NAME']);
                $script_dir = str_replace('\\', '/', $script_dir);
                $script_dir = rtrim($script_dir, '/');
                $invite_url_sub = "{$protocol}://{$host}{$script_dir}/register.php?invite_parent_id=" . $target_sub_id;
                ?>
                <div class="tooltip-btn-container">
                    <button onclick="navigator.clipboard.writeText('<?= $invite_url_sub ?>'); alert('スタッフ招待リンクをコピーしました！\nこのリンクから登録したスタッフは、貴社宛の全案件へ自動的に権限が付与されます。');" style="background:#8b5cf6; color:white; padding:5px 12px; border-radius:4px; border:none; font-size:12px; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:5px; box-shadow:0 2px 4px rgba(139,92,246,0.3);">
                        👥 スタッフを招待する
                    </button>
                    <span class="tooltip-text">
                        このボタンを押すとこのダッシュボードへの招待リンクをコピーします。<br>
                        招待者へメールを作成し、本文に招待リンクを貼り付けてアクセスしてもらってください。
                    </span>
                </div>
            <?php endif; ?>
            <div style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '' ?></div>
            <a href="completed_sub_orders.php" style="font-weight:bold; color:white; background:#3b82f6; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:5px;">📂 支払済アーカイブDB</a>
            <?php if ($is_admin): ?>
                <a href="subcontractors_list.php" style="color:#0056b3; font-weight:bold; text-decoration:none;">➔ 業者一覧に戻る</a>
            <?php else: ?>
                <a href="logout.php" style="color:#c0392b; font-weight:bold; text-decoration:none;">ログアウト</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <!-- 左カラム：案件スケジュール -->
        <div class="col-main box">
            <h3>📋 担当案件・スケジュール</h3>
            <?php if ($has_parent): ?>
                <div style="margin-bottom: 15px; display: flex; gap: 10px; font-size: 13px;">
                    <span style="font-weight: bold; align-self: center;">表示範囲:</span>
                    <a href="subcontractor_portal.php?sub_view_mode=all" style="padding: 5px 10px; border-radius: 4px; text-decoration: none; font-weight: bold; <?= $sub_view_mode === 'all' ? 'background:#3b82f6; color:white;' : 'background:#e2e8f0; color:#333;' ?>">業者全体 (すべての案件)</a>
                    <a href="subcontractor_portal.php?sub_view_mode=personal" style="padding: 5px 10px; border-radius: 4px; text-decoration: none; font-weight: bold; <?= $sub_view_mode === 'personal' ? 'background:#3b82f6; color:white;' : 'background:#e2e8f0; color:#333;' ?>">担当者ベース (自分の案件のみ)</a>
                </div>
            <?php endif; ?>
            <?php if (count($project_tasks) > 0): ?>
                <div class="grid">
                    <?php foreach ($project_tasks as $pid => $proj): 
                        $project_dummy = [
                            'id' => $proj['project_id'],
                            'status' => $proj['project_status'],
                            'primary_due_date' => $proj['primary_due_date'],
                            'schedule_actuals' => $proj['schedule_actuals'],
                            'req_permit' => $proj['req_permit'],
                            'req_wall' => $proj['req_wall'],
                            'req_skin' => $proj['req_skin'],
                            'req_sky' => $proj['req_sky'],
                            'req_opt_kisohari' => $proj['req_opt_kisohari']
                        ];
                        $ball = \App\Helpers\StatusHelper::getBallStatus($project_dummy, $pdo, 'subcontractor');
                    ?>
                        <div class="card" style="border-left: 5px solid <?= $ball['color'] ?>;">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:5px;">
                                    <span class="badge" style="background-color: <?= $ball['color'] ?>; color: white; font-weight: bold; margin:0;"><?= htmlspecialchars($ball['label'], ENT_QUOTES) ?></span>
                                </div>
                                <h3 style="font-size:15px; color:#1e3a8a; margin:0 0 12px 0;">🏠 <?= htmlspecialchars($proj['project_name']) ?></h3>
                                
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <?php foreach ($proj['items'] as $t): ?>
                                        <?php if ($t['status'] === 'cancelled'): ?>
                                            <div style="font-size:11px; color:#94a3b8; text-decoration:line-through;">
                                                ❌ <?= htmlspecialchars($t['task_title']) ?> (キャンセル済)
                                            </div>
                                        <?php else: ?>
                                            <div style="font-size:12px; background:#f8fafc; border:1px solid #e2e8f0; padding:8px; border-radius:4px; display:flex; flex-direction:column; gap:4px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <span style="font-weight:bold; color:#334155;"><?= htmlspecialchars($t['task_title']) ?></span>
                                                    <?php 
                                                        if ($t['status'] === 'requested') echo '<span class="badge" style="background:#f59e0b; padding:2px 5px; font-size:10px; margin:0;">承諾待ち</span>';
                                                        elseif ($t['status'] === 'accepted' || $t['status'] === 'in_progress') echo '<span class="badge" style="background:#3b82f6; padding:2px 5px; font-size:10px; margin:0;">作業中</span>';
                                                        elseif ($t['status'] === 'delivered') echo '<span class="badge" style="background:#fd7e14; padding:2px 5px; font-size:10px; margin:0;">一次納品</span>';
                                                        elseif ($t['status'] === 'cb_requested') echo '<span class="badge" style="background:#ef4444; padding:2px 5px; font-size:10px; margin:0;">修正依頼</span>';
                                                        elseif ($t['status'] === 'completed') echo '<span class="badge" style="background:#059669; padding:2px 5px; font-size:10px; margin:0;">完了</span>';
                                                        elseif ($t['status'] === 'rejected') echo '<span class="badge" style="background:#ef4444; padding:2px 5px; font-size:10px; margin:0;">辞退済</span>';
                                                    ?>
                                                </div>
                                                <div style="display:flex; justify-content:space-between; font-size:11px; color:#64748b;">
                                                    <span>発注額: <?= number_format($t['order_amount']) ?>円</span>
                                                    <span>希望納期: <?= !empty($t['due_date']) ? date('m/d', strtotime($t['due_date'])) : '-' ?></span>
                                                </div>
                                                
                                                <?php if ($t['status'] === 'requested' && !$is_admin): ?>
                                                    <div style="margin-top:5px; display:flex; gap:5px; align-items:center;">
                                                        <form method="POST" action="project_subcontractor.php" style="background:#fff3cd; padding:5px; border-radius:4px; border:1px solid #ffeeba; display:flex; gap:5px; align-items:center; margin:0; flex-wrap:wrap; width:100%; justify-content:space-between;">
                                                            <input type="hidden" name="order_id" value="<?= $t['id'] ?>">
                                                            <span style="font-size:10px; font-weight:bold; color:#856404;">予定日:</span>
                                                            <input type="date" name="expected_delivery_date" required style="padding:2px; font-size:10px;">
                                                            <button type="submit" style="background:#28a745; color:white; border:none; padding:3px 6px; border-radius:3px; font-size:10px; cursor:pointer; font-weight:bold;">承諾</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div style="margin-top:12px;">
                                <a href="project_subcontractor.php?id=<?= $proj['project_id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>; color:#fff; text-decoration:none; font-size:12px; font-weight:bold; display:block; text-align:center; padding:8px; border-radius:4px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">詳細・DL・納品 ➔</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:#777; font-size:14px;">現在担当している案件はありません。</p>
            <?php endif; ?>
        </div>

        <!-- 右カラム：グローバルチャット & 月次請求 -->
        <div class="col-side">
            <!-- 💬 グローバルチャット -->
            <div class="box" style="display:flex; flex-direction:column; height:calc(100vh - 220px); min-height:450px;">
                <h3>💬 業務連絡チャット <span style="font-size:10px; font-weight:normal; margin-left:10px; color:#c0392b;">※チェックバックは添付ファイルを添えてチャットにUPして下さい。</span></h3>
                <p style="font-size:12px; color:#666; margin-top:0;">案件に紐付かない、一般的な業務連絡や支払いに関するやり取りを行います。</p>
                
                <div style="flex:1; overflow-y:auto; background:#f8f9fa; border:1px solid #ddd; border-radius:4px; padding:10px; margin-bottom:10px; display:flex; flex-direction:column; gap:10px;">
                    <?php if (count($global_messages) > 0): ?>
                        <?php foreach ($global_messages as $msg): 
                            $is_mine = ($msg['sender_id'] == $user_id);
                        ?>
                            <div style="display:flex; flex-direction:column; align-items: <?= $is_mine ? 'flex-end' : 'flex-start' ?>;">
                                <div style="font-size:10px; color:#777; margin-bottom:2px;"><?= htmlspecialchars($msg['contact_name']) ?> - <?= date('m/d H:i', strtotime($msg['created_at'])) ?></div>
                                <?php if (!empty($msg['message_text'])): ?>
                                    <div style="max-width:80%; padding:8px 12px; border-radius:12px; font-size:13px; line-height:1.5; white-space:pre-wrap; <?= $is_mine ? 'background:#3b82f6; color:white; border-bottom-right-radius:2px;' : 'background:#e2e8f0; color:#333; border-bottom-left-radius:2px;' ?>">
                                        <?= htmlspecialchars($msg['message_text']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($msg['file_path'])): 
                                    $furl = (strpos($msg['file_path'], 'uploads/') !== 0 && strlen($msg['file_path']) > 15 && strpos($msg['file_path'], '/') === false) 
                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($msg['file_path'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                        : htmlspecialchars($msg['file_path'], ENT_QUOTES);
                                ?>
                                    <div style="max-width:80%; padding:5px 10px; border-radius:8px; font-size:12px; margin-top:4px; <?= $is_mine ? 'background:#3b82f6;' : 'background:#e2e8f0;' ?>">
                                        <a href="<?= $furl ?>" target="_blank" style="color:<?= $is_mine ? '#fff' : '#0056b3' ?>; text-decoration:none;">
                                            <?php if (($msg['file_type'] ?? '') === 'image'): ?>
                                                🖼 画像を見る
                                            <?php else: ?>
                                                📄 添付ファイルを見る
                                            <?php endif; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; color:#aaa; font-size:12px; margin-top:20px;">まだメッセージはありません。</div>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:5px;">
                    <input type="hidden" name="action" value="send_global_message">
                    <div style="background:#fff; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <textarea name="message_text" rows="4" style="width:100%; box-sizing:border-box; border:none; resize:vertical; font-family:inherit; font-size:13px; outline:none; display:block; margin-bottom:8px;" placeholder="メッセージを入力..."></textarea>
                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #eee; padding-top:5px;">
                            <div>
                                <input type="file" name="chat_file" id="global_chat_file" style="display:none;" onchange="document.getElementById('global_file_label').style.color='#28a745'">
                                <label for="global_chat_file" id="global_file_label" style="cursor:pointer; font-size:18px; color:#6c757d; padding:5px;" title="ファイルを添付">📎</label>
                            </div>
                            <button type="submit" style="background:#10b981; color:white; border:none; padding:6px 16px; border-radius:4px; font-weight:bold; cursor:pointer;">送信</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- 💰 月次報酬・お受け取り状況 -->
            <div class="box">
                <h3>💰 月次報酬・お受け取り状況</h3>
                <p style="font-size:12px; color:#666; margin-top:0;">納品完了した案件の報酬額（月別）などのお受け取り状況を管理します。</p>
                
                <?php if (count($monthly_totals) > 0): ?>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($monthly_totals as $month => $total): 
                            $payment = $payments[$month] ?? null;
                            $paid_amount = $payment ? intval($payment['paid_amount']) : 0;
                            $balance = $total - $paid_amount;
                        ?>
                            <div style="border:1px solid #cbd5e1; border-radius:6px; padding:10px; background:#f8fafc;">
                                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:5px; margin-bottom:5px;">
                                    <strong style="font-size:15px; color:#1e293b;"><?= $month ?> 納品分</strong>
                                    <?php if ($balance <= 0): ?>
                                        <span class="badge" style="background:#10b981;">お受け取り完了</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#ef4444;">支払期日前</span>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:3px;">
                                    <span>ご請求額:</span>
                                    <strong><?= number_format($total) ?> 円</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:3px; color:#10b981;">
                                    <span>弊社支払額:</span>
                                    <strong><?= number_format($paid_amount) ?> 円</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; font-size:13px; color:#ef4444;">
                                    <span>未払残高:</span>
                                    <strong><?= number_format($balance) ?> 円</strong>
                                </div>

                                <!-- 📄 アップロード済み請求書の表示 -->
                                <?php if (!empty($payment['invoice_file_path'])): 
                                    $inv_url = (strpos($payment['invoice_file_path'], 'uploads/') === 0) 
                                        ? $payment['invoice_file_path'] 
                                        : 'https://drive.google.com/file/d/' . htmlspecialchars($payment['invoice_file_path'], ENT_QUOTES) . '/view?usp=drivesdk';
                                ?>
                                    <div style="margin-top: 8px; padding: 6px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 4px; font-size: 12px; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #0369a1; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px;">
                                            📄 <?= htmlspecialchars($payment['invoice_file_name'], ENT_QUOTES) ?>
                                        </span>
                                        <a href="<?= $inv_url ?>" target="_blank" style="color: #0284c7; text-decoration: underline; font-weight: bold;">ダウンロード</a>
                                    </div>
                                <?php endif; ?>

                                <!-- 協力業者自身による請求書アップロード枠 -->
                                <?php if (!$is_admin): ?>
                                    <form method="POST" enctype="multipart/form-data" style="margin-top: 8px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                                        <input type="hidden" name="action" value="upload_sub_invoice">
                                        <input type="hidden" name="target_month" value="<?= $month ?>">
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <span style="font-size: 11px; font-weight: bold; color: #475569;">
                                                <?= !empty($payment['invoice_file_path']) ? '🔄 請求書を差し替える:' : '📤 請求書(PDF)をアップロード:' ?>
                                            </span>
                                            <div style="display: flex; gap: 5px; align-items: center;">
                                                <input type="file" name="invoice_file" accept=".pdf" required style="font-size: 11px; max-width: 170px;">
                                                <button type="submit" style="background: #10b981; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; cursor: pointer;">送信</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($has_finance_access): ?>
                                    <form method="POST" style="margin-top:10px; border-top:1px dashed #cbd5e1; padding-top:10px;">
                                        <input type="hidden" name="action" value="log_sub_payment">
                                        <input type="hidden" name="target_month" value="<?= $month ?>">
                                        <div style="display:flex; gap:5px; align-items:center;">
                                            <input type="number" name="paid_amount" value="<?= $total ?>" style="width:100px; padding:4px; font-size:12px;"> 円を
                                            <button type="submit" style="background:#3b82f6; color:white; border:none; padding:4px 8px; border-radius:3px; font-size:11px; cursor:pointer;">支払記録として保存</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="background:#f8f9fa; border:1px solid #ddd; height:80px; border-radius:4px; display:flex; justify-content:center; align-items:center; color:#999; font-size:13px;">
                        納品済みの案件がありません。
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
