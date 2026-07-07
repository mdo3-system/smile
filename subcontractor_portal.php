<?php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'subcontractor', 'accountant']);

// 追加メールアドレスの取得
$stmtAddEmails = $pdo->prepare("SELECT email FROM user_notification_emails WHERE user_id = :uid ORDER BY id ASC");
$stmtAddEmails->execute(['uid' => $_SESSION['user_id']]);
$additional_emails_list = $stmtAddEmails->fetchAll(PDO::FETCH_COLUMN) ?: [];
$additional_emails_str = implode("\n", $additional_emails_list);

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$is_accountant = ($_SESSION['role'] === 'accountant');
$has_finance_access = ($is_admin || $is_accountant);

// 表示対象の協力業者IDを決定
$target_sub_id = 0;
if ($is_admin || $is_accountant) {
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

// ログインユーザーの専門分野を取得
$stmtMe = $pdo->prepare("SELECT sub_specialty FROM users WHERE id = :id");
$stmtMe->execute(['id' => $user_id]);
$my_specialty = $stmtMe->fetchColumn() ?: 'both';

// 初期表示アクティブタブ決定
$active_tab = $_GET['tab'] ?? '';
if ($active_tab === '') {
    if ($my_specialty === 'structural') {
        $active_tab = 'structural';
    } else {
        $active_tab = 'design';
    }
}

$has_parent = !empty($p_id);

// CSVエクスポート処理
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $export_type = $_GET['type'] ?? 'design'; // design or structural
    $sub_view_mode = $_SESSION['sub_view_mode'] ?? 'all';
    
    if ($has_parent && $sub_view_mode === 'personal') {
        $parent_id = $target_sub_id;
        $stmtExport = $pdo->prepare("
            SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, u_std.contact_name as staff_name
            FROM subcontractor_orders o 
            JOIN projects p ON o.project_id = p.id 
            LEFT JOIN users u_std ON o.subcontractor_id = u_std.id
            WHERE (
                o.subcontractor_id = :user_id 
                AND (
                    (:otype = 'design' AND o.order_type = 'design')
                    OR (:otype = 'structural' AND o.order_type IN ('struct', 'structural'))
                )
            ) OR (
                o.status = 'requested'
                AND o.subcontractor_id = :parent_id
                AND (
                    (:specialty = 'design' AND :otype = 'design' AND o.order_type = 'design')
                    OR (:specialty = 'structural' AND :otype = 'structural' AND o.order_type IN ('struct', 'structural'))
                    OR (:specialty = 'both' AND (
                        (:otype = 'design' AND o.order_type = 'design')
                        OR (:otype = 'structural' AND o.order_type IN ('struct', 'structural'))
                    ))
                )
            )
            ORDER BY o.id DESC
        ");
        $stmtExport->execute([
            'user_id' => $user_id,
            'parent_id' => $parent_id,
            'otype' => $export_type,
            'specialty' => $my_specialty
        ]);
    } else {
        $stmtExport = $pdo->prepare("
            SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, u_std.contact_name as staff_name
            FROM subcontractor_orders o 
            JOIN projects p ON o.project_id = p.id 
            LEFT JOIN users u_std ON o.subcontractor_id = u_std.id
            WHERE (o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2))
              AND (
                  (:otype = 'design' AND o.order_type = 'design')
                  OR (:otype = 'structural' AND o.order_type IN ('struct', 'structural'))
              )
            ORDER BY o.id DESC
        ");
        $stmtExport->execute([
            'sub_id_1' => $target_sub_id,
            'sub_id_2' => $target_sub_id,
            'otype' => $export_type
        ]);
    }
    $export_orders = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $filename = ($export_type === 'structural' ? 'structural_orders_' : 'design_orders_') . date('YmdHis') . '.csv';
    header('Content-Type: text/csv; charset=shift_jis');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    
    $headers = ['管理番号', '物件名', '受付日', '納期', '納品日', '延床面積(㎡)', '請負金額(円)', '担当者', '請求月', '現在の状況'];
    mb_convert_variables('SJIS-win', 'UTF-8', $headers);
    fputcsv($fp, $headers);
    
    $status_labels = [
        'requested' => '承諾待ち',
        'accepted' => '進行中',
        'cb_requested' => '修正対応中',
        'delivered' => '納品検収中',
        'completed' => '完了',
        'cancelled' => 'キャンセル'
    ];

    foreach ($export_orders as $o) {
        $date_str = $o['completed_at'] ?? $o['updated_at'] ?? $o['created_at'];
        $ts = strtotime($date_str);
        $y = (int)date('Y', $ts);
        $m = (int)date('m', $ts);
        if ((int)date('d', $ts) >= 26) {
            $m++;
            if ($m > 12) { $m = 1; $y++; }
        }

        $row = [
            'S' . sprintf('%04d', $o['id']),
            $o['project_name'],
            !empty($o['created_at']) ? date('m/d', strtotime($o['created_at'])) : '',
            !empty($o['due_date']) ? date('m/d', strtotime($o['due_date'])) : '',
            ($o['status'] === 'completed' && !empty($o['completed_at'])) ? date('m/d', strtotime($o['completed_at'])) : (($o['status'] === 'delivered' && !empty($o['updated_at'])) ? date('m/d', strtotime($o['updated_at'])) : ''),
            $o['floor_area'],
            $o['order_amount'],
            $o['staff_name'] ?: '未指定',
            sprintf('%04d年%02d月', $y, $m),
            $status_labels[$o['status']] ?? $o['status']
        ];
        mb_convert_variables('SJIS-win', 'UTF-8', $row);
        fputcsv($fp, $row);
    }
    
    fclose($fp);
    exit;
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
            $drive_file_id = upload_to_google_drive($file_tmp, $file_name, $mime_type, null, $pdo, $target_sub_id);
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

            // 協力業者またはそのスタッフから管理者に送信された場合、メール通知
            if ($_SESSION['role'] === 'subcontractor') {
                try {
                    $stmtSubName = $pdo->prepare("SELECT company_name, contact_name FROM users WHERE id = :id");
                    $stmtSubName->execute(['id' => $target_sub_id]);
                    $sub_info = $stmtSubName->fetch();
                    $sub_company = $sub_info ? $sub_info['company_name'] : '協力業者';

                    $stmtAdmins = $pdo->prepare("SELECT email FROM users WHERE role = 'admin'");
                    $stmtAdmins->execute();
                    $admin_emails = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($admin_emails as $admin_email) {
                        if ($admin_email && filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                            $subject = "【設計サポート】全体連絡チャットに協力業者から新着メッセージがあります";
                            $body  = "協力業者「{$sub_company}」様より全体連絡チャットにメッセージが届きました。\n\n";
                            $body .= "送信メッセージ:\n{$message_text}\n\n";
                            $body .= "▼全体連絡チャットを確認する:\n";
                            $body .= "https://system.thanks.work/system/subcontractor_portal.php?sub_id={$target_sub_id}\n\n";
                            $body .= "------\n";
                            $body .= "※このメールに返信いただいてもお返事できません。";
                            sendSystemEmail($admin_email, $subject, $body);
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Failed to send global chat email notification: " . $e->getMessage());
                }
            }
        }
        header("Location: subcontractor_portal.php" . (($is_admin || $is_accountant) ? "?sub_id=" . $target_sub_id : ""));
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
                // UPSERT処理 (is_archivedを1にして保存)
                $stmt = $pdo->prepare("
                    INSERT INTO subcontractor_payments (subcontractor_id, target_month, paid_amount, paid_at, note, is_archived) 
                    VALUES (:sub_id, :t_month, :amt, NOW(), :note, 1)
                    ON DUPLICATE KEY UPDATE paid_amount = :amt_update, paid_at = NOW(), note = :note_update, is_archived = 1
                ");
                $stmt->execute([
                    'sub_id' => $target_sub_id,
                    't_month' => $target_month,
                    'amt' => $paid_amount,
                    'note' => $note,
                    'amt_update' => $paid_amount,
                    'note_update' => $note
                ]);

                // === [連動処理] 該当月の完了発注タスクを「支払済」に更新 ===
                $stmtOrders = $pdo->prepare("
                    SELECT id, completed_at, updated_at, created_at 
                    FROM subcontractor_orders 
                    WHERE (subcontractor_id = :sub_id_1 OR subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2)) 
                      AND status = 'completed' 
                      AND payment_status != 'paid'
                ");
                $stmtOrders->execute([
                    'sub_id_1' => $target_sub_id,
                    'sub_id_2' => $target_sub_id
                ]);
                $candidate_orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

                foreach ($candidate_orders as $co) {
                    $date_str = $co['completed_at'] ?? $co['updated_at'] ?? $co['created_at'];
                    $ts = strtotime($date_str);
                    
                    $y = (int)date('Y', $ts);
                    $m = (int)date('m', $ts);
                    $d = (int)date('d', $ts);
                    
                    // 25日締めルール
                    if ($d >= 26) {
                        $m++;
                        if ($m > 12) {
                            $m = 1;
                            $y++;
                        }
                    }
                    $co_month = sprintf("%04d-%02d", $y, $m);
                    
                    if ($co_month === $target_month) {
                        $stmtUp = $pdo->prepare("
                            UPDATE subcontractor_orders 
                            SET payment_status = 'paid', payment_date = :p_date 
                            WHERE id = :id
                        ");
                        $stmtUp->execute([
                            'p_date' => date('Y-m-d'),
                            'id' => $co['id']
                        ]);
                    }
                }

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
                die("支払記録 of 保存に失敗しました: " . $e->getMessage());
            }
        }
        header("Location: subcontractor_portal.php?sub_id=" . $target_sub_id);
        exit;
    }

    // アーカイブから戻す
    if ($action === 'unarchive_sub_payment' && $has_finance_access) {
        $target_month = $_POST['target_month'] ?? '';
        if ($target_month !== '') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE subcontractor_payments 
                    SET is_archived = 0, paid_amount = 0
                    WHERE subcontractor_id = :sub_id AND target_month = :t_month
                ");
                $stmt->execute([
                    'sub_id' => $target_sub_id,
                    't_month' => $target_month
                ]);

                // === [連動処理] 該当月の支払済発注タスクを「未払い」に戻す ===
                $stmtPaidOrders = $pdo->prepare("
                    SELECT id, completed_at, updated_at, created_at 
                    FROM subcontractor_orders 
                    WHERE (subcontractor_id = :sub_id_1 OR subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2)) 
                      AND status = 'completed' 
                      AND payment_status = 'paid'
                ");
                $stmtPaidOrders->execute([
                    'sub_id_1' => $target_sub_id,
                    'sub_id_2' => $target_sub_id
                ]);
                $paid_candidate_orders = $stmtPaidOrders->fetchAll(PDO::FETCH_ASSOC);

                foreach ($paid_candidate_orders as $pco) {
                    $date_str = $pco['completed_at'] ?? $pco['updated_at'] ?? $pco['created_at'];
                    $ts = strtotime($date_str);
                    
                    $y = (int)date('Y', $ts);
                    $m = (int)date('m', $ts);
                    $d = (int)date('d', $ts);
                    
                    // 25日締めルール
                    if ($d >= 26) {
                        $m++;
                        if ($m > 12) {
                            $m = 1;
                            $y++;
                        }
                    }
                    $co_month = sprintf("%04d-%02d", $y, $m);
                    
                    if ($co_month === $target_month) {
                        $stmtUp = $pdo->prepare("
                            UPDATE subcontractor_orders 
                            SET payment_status = 'unpaid', payment_date = NULL 
                            WHERE id = :id
                        ");
                        $stmtUp->execute([
                            'id' => $pco['id']
                        ]);
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                die("支払記録の戻し処理に失敗しました: " . $e->getMessage());
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
        header("Location: subcontractor_portal.php" . (($is_admin || $is_accountant) ? "?sub_id=" . $target_sub_id : ""));
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
    // 親ID（代表ID）を取得
    $parent_id = $target_sub_id;
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, p.schedule_actuals, p.req_permit, p.req_wall, p.req_skin, p.req_sky, p.req_opt_kisohari, u_std.contact_name as staff_name
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        LEFT JOIN users u_std ON o.subcontractor_id = u_std.id
        WHERE o.subcontractor_id = :user_id
           OR (
               o.status = 'requested' 
               AND o.subcontractor_id = :parent_id 
               AND (
                   (:specialty = 'design' AND o.order_type = 'design')
                   OR (:specialty = 'structural' AND o.order_type IN ('struct', 'structural'))
                   OR (:specialty = 'both')
               )
           )
        ORDER BY ISNULL(p.last_manual_chat_at) ASC, p.last_manual_chat_at DESC, ISNULL(p.primary_due_date) ASC, p.primary_due_date ASC, FIELD(p.status, 'quote_req', 'doc_submitted', 'primary_prep', 'contracted', 'structural_dwg', 'submission', 'submitting', 'correction', 'completed') ASC, p.project_name ASC
    ");
    $stmtTasks->execute([
        'user_id' => $user_id,
        'parent_id' => $parent_id,
        'specialty' => $my_specialty
    ]);
} else {
    // 業者全体（本アカウント宛て ＋ スタッフ宛て）またはメインアカウントの場合
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, p.schedule_actuals, p.req_permit, p.req_wall, p.req_skin, p.req_sky, p.req_opt_kisohari, u_std.contact_name as staff_name
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        LEFT JOIN users u_std ON o.subcontractor_id = u_std.id
        WHERE (o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2))
        ORDER BY ISNULL(p.last_manual_chat_at) ASC, p.last_manual_chat_at DESC, ISNULL(p.primary_due_date) ASC, p.primary_due_date ASC, FIELD(p.status, 'quote_req', 'doc_submitted', 'primary_prep', 'contracted', 'structural_dwg', 'submission', 'submitting', 'correction', 'completed') ASC, p.project_name ASC
    ");
    $stmtTasks->execute([
        'sub_id_1' => $target_sub_id,
        'sub_id_2' => $target_sub_id
    ]);
}
$tasks = $stmtTasks->fetchAll();

// 意匠図 (design) と 構造図 (structural) へのタブ別 data 分類
$design_tasks = [];
$structural_tasks = [];

foreach ($tasks as $t) {
    if (in_array($t['order_type'], ['struct', 'structural'])) {
        $structural_tasks[] = $t;
    } else {
        $design_tasks[] = $t;
    }
}

// それぞれを 3つのステータス層に分類するヘルパー
if (!function_exists('categorizeTasks')) {
    function categorizeTasks(array $tasks): array {
        $cat = [
            'requested' => [],
            'active' => [],
            'done' => []
        ];
        foreach ($tasks as $t) {
            $st = $t['status'] ?? '';
            if ($st === 'requested') {
                $cat['requested'][] = $t;
            } elseif ($st === 'accepted' || $st === 'cb_requested') {
                $cat['active'][] = $t;
            } elseif ($st === 'delivered' || $st === 'completed') {
                $cat['done'][] = $t;
            }
        }
        return $cat;
    }
}

$design_cat = categorizeTasks($design_tasks);
$structural_cat = categorizeTasks($structural_tasks);

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
        .container { max-width: 1800px; width: 98%; margin: 0 auto; display: flex; gap: 20px; }
        .col-main { flex: 1.8; }
        .col-side { flex: 1.2; display: flex; flex-direction: column; gap: 20px; }
        .box { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        h2, h3 { margin-top: 0; }
        .task-card { border: 1px solid #e2e8f0; border-left: 4px solid #3b82f6; padding: 15px; border-radius: 4px; margin-bottom: 10px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; }
        
        /* トグルスイッチ用スタイル */
        .switch { position: relative; display: inline-block; width: 34px; height: 18px; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 18px; }
        .slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(16px); }
        
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
            <?php if (!$is_admin && !empty($_SESSION['user_id'])): ?>
                <div style="position:relative; display:inline-block;">
                    <a href="#" class="notif-setting-link" onclick="toggleNotificationPopup(event); return false;" style="font-size:12px; color:#2563eb; font-weight:bold; text-decoration:none; display:inline-flex; align-items:center; gap:2px; margin-right:10px;" title="クリックしてメール通知の受信設定を変更します">
                        🔔 メール通知設定
                    </a>
                    
                    <!-- 通知設定ポップアップ (協力業者用) -->
                    <div id="myAccountPopup" style="display:none; position:absolute; background:white; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); border:1px solid #cbd5e1; padding:12px; z-index:1000; width:220px; font-size:11px; top:25px; right:0; text-align:left;">
                        <div style="font-weight:bold; border-bottom:1px solid #edf2f7; padding-bottom:5px; margin-bottom:8px; color:#1e293b; display:flex; justify-content:space-between; align-items:center;">
                            <span>⚙️ 通知設定</span>
                            <span style="cursor:pointer; color:#94a3b8; font-size:12px;" onclick="closeMyAccountPopup()">✕</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <span style="font-weight:600; color:#475569;">メール通知の受け取り</span>
                            <label class="switch" style="position:relative; display:inline-block; width:34px; height:18px;">
                                 <input type="checkbox" id="user_notification_toggle" style="opacity:0; width:0; height:0;" 
                                       <?= ($_SESSION['email_notification_enabled'] ?? 1) ? 'checked' : '' ?>
                                       onchange="updateNotificationSetting(this.checked, document.getElementById('additional_emails_input').value, false)">
                                <span class="slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#cbd5e1; transition:.3s; border-radius:18px;"></span>
                            </label>
                        </div>
                        <div style="margin-top:10px; margin-bottom:8px;">
                            <span style="font-weight:600; color:#475569; display:block; margin-bottom:4px;">追加の通知メールアドレス:</span>
                            <textarea id="additional_emails_input" style="width:100%; height:60px; padding:4px; border:1px solid #cbd5e1; border-radius:4px; font-size:10px; resize:vertical; box-sizing:border-box;" placeholder="example@test.com&#10;another@test.com&#10;(改行またはカンマ区切り)"><?= htmlspecialchars($additional_emails_str, ENT_QUOTES) ?></textarea>
                        </div>
                        <div style="text-align:right; margin-bottom:8px;">
                            <button onclick="updateNotificationSetting(document.getElementById('user_notification_toggle').checked, document.getElementById('additional_emails_input').value, true)" style="background:#10b981; border:none; padding:4px 10px; border-radius:4px; font-size:10px; cursor:pointer; color:white; font-weight:bold;">設定を保存</button>
                        </div>
                        <div style="font-size:9px; color:#94a3b8; line-height:1.3; font-weight:normal;">
                            ※新規発注や修正依頼メッセージが入った際の通知メールを制御します。
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '' ?></div>
            <a href="completed_sub_orders.php<?= $target_sub_id ? '?sub_id=' . intval($target_sub_id) : '' ?>" style="font-weight:bold; color:white; background:#3b82f6; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:5px;">📂 支払済アーカイブDB</a>
            <?php if ($is_admin || $is_accountant): ?>
                <a href="subcontractors_list.php" style="color:#0056b3; font-weight:bold; text-decoration:none;">➔ 業者一覧に戻る</a>
            <?php else: ?>
                <a href="logout.php" style="color:#c0392b; font-weight:bold; text-decoration:none;">ログアウト</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <!-- 左カラム：案件スケジュール (エクセル風タブ＆テーブルアコーディオン) -->
        <div class="col-main box" style="flex:2.0;">
            <!-- タブメニュー -->
            <div class="tab-menu" style="display:flex; border-bottom:2px solid #cbd5e1; margin-bottom:15px; gap:5px;">
                <a href="subcontractor_portal.php?tab=design<?= $target_sub_id ? '&sub_id=' . $target_sub_id : '' ?>" class="tab-item" style="padding:10px 20px; font-weight:bold; text-decoration:none; font-size:13px; border-top-left-radius:6px; border-top-right-radius:6px; <?= $active_tab === 'design' ? 'background:#3b82f6; color:white;' : 'background:#e2e8f0; color:#475569;' ?>">✍️ 意匠図（トレース）案件一覧</a>
                <a href="subcontractor_portal.php?tab=structural<?= $target_sub_id ? '&sub_id=' . $target_sub_id : '' ?>" class="tab-item" style="padding:10px 20px; font-weight:bold; text-decoration:none; font-size:13px; border-top-left-radius:6px; border-top-right-radius:6px; <?= $active_tab === 'structural' ? 'background:#8b5cf6; color:white;' : 'background:#e2e8f0; color:#475569;' ?>">🏗️ 構造図案件一覧</a>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; gap:15px; align-items:center;">
                    <h3 style="margin:0; font-size:16px; color:#1e293b;">
                        <?= $active_tab === 'structural' ? '🏗️ 構造図 案件管理スケジュール' : '✍️ 意匠図（トレース）案件管理スケジュール' ?>
                    </h3>
                    <?php if ($has_parent): ?>
                        <div style="display: flex; gap: 5px; font-size: 12px; background:#f1f5f9; padding:3px; border-radius:4px; border:1px solid #e2e8f0;">
                            <a href="subcontractor_portal.php?tab=<?= $active_tab ?>&sub_view_mode=all<?= $target_sub_id ? '&sub_id=' . $target_sub_id : '' ?>" style="padding: 3px 8px; border-radius: 3px; text-decoration: none; font-weight: bold; <?= $sub_view_mode === 'all' ? 'background:#fff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,0.05);' : 'color:#64748b;' ?>">全体</a>
                            <a href="subcontractor_portal.php?tab=<?= $active_tab ?>&sub_view_mode=personal<?= $target_sub_id ? '&sub_id=' . $target_sub_id : '' ?>" style="padding: 3px 8px; border-radius: 3px; text-decoration: none; font-weight: bold; <?= $sub_view_mode === 'personal' ? 'background:#fff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,0.05);' : 'color:#64748b;' ?>">マイ案件</a>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="subcontractor_portal.php?action=export_csv&type=<?= $active_tab ?><?= $target_sub_id ? '&sub_id=' . $target_sub_id : '' ?>" class="btn-download" style="background:#059669; color:white; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none; display:inline-flex; align-items:center; gap:5px; box-shadow:0 2px 4px rgba(5,150,105,0.3); border:none; cursor:pointer;">
                    📥 エクセル用CSVダウンロード
                </a>
            </div>

            <?php
            $current_cat = ($active_tab === 'structural') ? $structural_cat : $design_cat;
            $section_titles = [
                'requested' => ['title' => '📥 ① 依頼の承認待ち (承諾する)', 'badge_color' => '#d97706', 'open' => true],
                'active'    => ['title' => '⚡ ② 依頼案件進行中', 'badge_color' => '#2563eb', 'open' => true],
                'done'      => ['title' => '💮 ③ 納品済みの案件', 'badge_color' => '#059669', 'open' => false]
            ];

            foreach ($section_titles as $key => $info):
                $items = $current_cat[$key];
            ?>
                <details style="border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; padding: 12px; margin-bottom:15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" <?= $info['open'] ? 'open' : '' ?>>
                    <summary style="cursor: pointer; font-size: 13px; font-weight: bold; color: #1e293b; display: flex; justify-content: space-between; align-items: center; user-select:none;">
                        <span><?= $info['title'] ?> <span class="badge" style="background: <?= $info['badge_color'] ?>; color: white; margin-left: 5px;"><?= count($items) ?> 件</span></span>
                    </summary>
                    <div style="margin-top: 12px; overflow-x: auto;">
                        <?php if (empty($items)): ?>
                            <p style="color:#64748b; font-size:12px; margin: 10px 0 0 0;">該当する案件はありません。</p>
                        <?php else: ?>
                            <table style="width:100%; border-collapse:collapse; font-size:11px; text-align:left; border:1px solid #cbd5e1; min-width:850px;">
                                <thead>
                                    <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1; color:#475569;">
                                        <th style="padding:6px; border:1px solid #cbd5e1; width:70px; text-align:center;">管理番号</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1;">物件名</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:center; width:65px;">受付日</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:center; width:65px;">納期</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:center; width:65px;">納品日</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:right; width:90px;">延床面積(㎡)</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:right; width:95px;">請負金額(円)</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; width:80px;">担当者</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:center; width:75px;">状況</th>
                                        <th style="padding:6px; border:1px solid #cbd5e1; text-align:center; width:130px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $o): 
                                        $date_str = $o['completed_at'] ?? $o['updated_at'] ?? $o['created_at'];
                                        $ts = strtotime($date_str);
                                        $y = (int)date('Y', $ts);
                                        $m = (int)date('m', $ts);
                                        if ((int)date('d', $ts) >= 26) {
                                            $m++;
                                            if ($m > 12) { $m = 1; $y++; }
                                        }
                                    ?>
                                        <tr style="border-bottom:1px solid #cbd5e1; hover:background:#f8fafc;">
                                            <td style="padding:6px; border:1px solid #cbd5e1; font-weight:bold; color:#475569; text-align:center;">S<?= sprintf('%04d', $o['id']) ?></td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; font-weight:bold; color:#1e3b8a; white-space:nowrap; text-overflow:ellipsis; overflow:hidden; max-width:220px;">
                                                <a href="project_subcontractor.php?id=<?= $o['project_id'] ?>" style="text-decoration:none; color:#1e3b8a;">🏠 <?= htmlspecialchars($o['project_name']) ?></a>
                                            </td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:center;"><?= !empty($o['created_at']) ? date('m/d', strtotime($o['created_at'])) : '-' ?></td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:center; color:#ef4444; font-weight:bold;"><?= !empty($o['due_date']) ? date('m/d', strtotime($o['due_date'])) : '-' ?></td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:center;"><?= ($o['status'] === 'completed' && !empty($o['completed_at'])) ? date('m/d', strtotime($o['completed_at'])) : (($o['status'] === 'delivered' && !empty($o['updated_at'])) ? date('m/d', strtotime($o['updated_at'])) : '-') ?></td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:right; font-weight:bold;"><?= number_format($o['floor_area'], 1) ?> ㎡</td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:right; font-weight:bold; color:#10b981;"><?= number_format($o['order_amount']) ?> 円</td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; color:#334155; font-weight:bold;"><?= htmlspecialchars($o['staff_name'] ?: '未指定') ?></td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:center;">
                                                <?php 
                                                    if ($o['status'] === 'requested') echo '<span class="badge" style="background:#f59e0b; padding:1px 4px; font-size:9px; border-radius:3px;">承諾待ち</span>';
                                                    elseif ($o['status'] === 'accepted') echo '<span class="badge" style="background:#3b82f6; padding:1px 4px; font-size:9px; border-radius:3px;">作業中</span>';
                                                    elseif ($o['status'] === 'delivered') echo '<span class="badge" style="background:#fd7e14; padding:1px 4px; font-size:9px; border-radius:3px;">検収中</span>';
                                                    elseif ($o['status'] === 'cb_requested') echo '<span class="badge" style="background:#ef4444; padding:1px 4px; font-size:9px; border-radius:3px;">修正対応</span>';
                                                    elseif ($o['status'] === 'completed') echo '<span class="badge" style="background:#059669; padding:1px 4px; font-size:9px; border-radius:3px;">完了</span>';
                                                ?>
                                            </td>
                                            <td style="padding:6px; border:1px solid #cbd5e1; text-align:center;">
                                                <div style="display:flex; justify-content:center; gap:3px; align-items:center; flex-wrap:wrap;">
                                                    <?php if ($o['status'] === 'requested' && !$is_admin): ?>
                                                        <form method="POST" action="project_subcontractor.php" style="margin:0; display:flex; gap:2px; align-items:center; background:#fef3c7; padding:2px; border:1px solid #fde68a; border-radius:3px;">
                                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                                            <input type="date" name="expected_delivery_date" required style="padding:1px; font-size:9px; width:75px;">
                                                            <button type="submit" style="background:#10b981; color:white; border:none; padding:2px 4px; border-radius:2px; font-size:9px; cursor:pointer; font-weight:bold;">承諾</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <a href="project_subcontractor.php?id=<?= $o['project_id'] ?>" style="background:#3b82f6; color:white; padding:3px 6px; border-radius:3px; text-decoration:none; font-size:9px; font-weight:bold; white-space:nowrap;">詳細・納品</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
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
                
                <?php 
                $active_months = [];
                $archived_months = [];
                foreach ($monthly_totals as $month => $total) {
                    $payment = $payments[$month] ?? null;
                    $paid_amount = $payment ? intval($payment['paid_amount']) : 0;
                    $balance = $total - $paid_amount;
                    
                    $is_archived = $payment ? intval($payment['is_archived']) : 0;
                    
                    // 未払残高が 0 以下の場合は、自動的にアーカイブ扱いにする
                    if ($balance <= 0) {
                        $is_archived = 1;
                    }
                    
                    if ($is_archived) {
                        $archived_months[$month] = $total;
                    } else {
                        $active_months[$month] = $total;
                    }
                }
                ?>

                <?php if (count($active_months) > 0 || count($archived_months) > 0): ?>
                    <div style="display:flex; flex-direction:column; gap:15px;">
                        
                        <!-- アクティブリスト -->
                        <?php if (count($active_months) > 0): ?>
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <?php foreach ($active_months as $month => $total): 
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
                        <?php endif; ?>

                        <!-- アーカイブされた月次リスト (アコーディオン) -->
                        <?php if (count($archived_months) > 0): ?>
                            <details style="border: 1px solid #cbd5e1; border-radius: 6px; background: #f1f5f9; padding: 10px;" open>
                                <summary style="cursor: pointer; font-size: 13px; font-weight: bold; color: #475569;">
                                    📂 支払済アーカイブ (全 <?= count($archived_months) ?> 件)
                                </summary>
                                <div style="display:flex; flex-direction:column; gap:10px; margin-top: 10px;">
                                    <?php foreach ($archived_months as $month => $total): 
                                        $payment = $payments[$month] ?? null;
                                        $paid_amount = $payment ? intval($payment['paid_amount']) : 0;
                                    ?>
                                        <div style="border:1px solid #cbd5e1; border-radius:6px; padding:10px; background:#fff;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:5px; margin-bottom:5px;">
                                                <strong style="font-size:14px; color:#64748b;"><?= $month ?> 納品分</strong>
                                                <span class="badge" style="background:#64748b;">アーカイブ済</span>
                                            </div>
                                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:3px; color:#64748b;">
                                                <span>ご請求額:</span>
                                                <strong><?= number_format($total) ?> 円</strong>
                                            </div>
                                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:3px; color:#10b981;">
                                                <span>支払済額:</span>
                                                <strong><?= number_format($paid_amount) ?> 円</strong>
                                            </div>

                                            <?php if (!empty($payment['invoice_file_path'])): 
                                                $inv_url = (strpos($payment['invoice_file_path'], 'uploads/') === 0) 
                                                    ? $payment['invoice_file_path'] 
                                                    : 'https://drive.google.com/file/d/' . htmlspecialchars($payment['invoice_file_path'], ENT_QUOTES) . '/view?usp=drivesdk';
                                            ?>
                                                <div style="margin-top: 6px; padding: 4px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 4px; font-size: 11px; display: flex; justify-content: space-between; align-items: center;">
                                                    <span style="color: #64748b; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; max-width: 180px;">
                                                        📄 <?= htmlspecialchars($payment['invoice_file_name'], ENT_QUOTES) ?>
                                                    </span>
                                                    <a href="<?= $inv_url ?>" target="_blank" style="color: #0284c7; font-weight: bold;">ダウンロード</a>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($has_finance_access): ?>
                                                <form method="POST" style="margin-top:8px; border-top:1px dashed #cbd5e1; padding-top:8px; display:flex; justify-content: flex-end;">
                                                    <input type="hidden" name="action" value="unarchive_sub_payment">
                                                    <input type="hidden" name="target_month" value="<?= $month ?>">
                                                    <button type="submit" style="background:#64748b; color:white; border:none; padding:4px 8px; border-radius:3px; font-size:11px; cursor:pointer; font-weight:bold;">↩ アクティブに戻す</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                    </div>
                <?php else: ?>
                    <div style="background:#f8f9fa; border:1px solid #ddd; height:80px; border-radius:4px; display:flex; justify-content:center; align-items:center; color:#999; font-size:13px;">
                        納品済みの案件がありません。
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    function toggleNotificationPopup(event) {
        event.stopPropagation();
        const popup = document.getElementById('myAccountPopup');
        popup.style.display = (popup.style.display === 'none' || popup.style.display === '') ? 'block' : 'none';
    }

    function closeMyAccountPopup() {
        document.getElementById('myAccountPopup').style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        const popup = document.getElementById('myAccountPopup');
        if (popup && !popup.contains(e.target) && !e.target.closest('.notif-setting-link') && !e.target.closest('#myAccountPopup')) {
            popup.style.display = 'none';
        }
    });

    function updateNotificationSetting(checked, additionalEmails, showAlert) {
        fetch('api_update_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                enabled: checked ? 1 : 0,
                additional_emails: additionalEmails
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                console.log("Notification setting updated:", data.enabled, data.emails);
                if (showAlert) {
                    alert("通知設定を保存しました。");
                }
            }
        })
        .catch(err => {
            console.error(err);
            alert("設定の更新に失敗しました。");
        });
    }
    </script>
</body>
</html>
