<?php
session_start();
require_once '../db_connect.php';
require_once '../functions.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'accountant'])) {
    die("権限がありません。");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'] ?? null;
    if (!$project_id) {
        die("Invalid project ID.");
    }

    try {
        // 複数追加見積の構築
        $add_estimates_data = [];
        $add_amounts = $_POST['add_est_amounts'] ?? [];
        $add_dates = $_POST['add_est_dates'] ?? [];
        for ($i = 0; $i < count($add_amounts); $i++) {
            $amt = trim($add_amounts[$i]);
            $dt = trim($add_dates[$i] ?? '');
            if ($amt !== '') {
                $add_estimates_data[] = [
                    'amount' => (int)$amt,
                    'date' => $dt !== '' ? $dt : null
                ];
            }
        }
        $add_estimates_json = count($add_estimates_data) > 0 ? json_encode($add_estimates_data, JSON_UNESCAPED_UNICODE) : null;
        
        // 追加入金履歴の構築
        $additional_deposits_data = [];
        $add_dep_amounts = $_POST['add_dep_amounts'] ?? [];
        $add_dep_dates = $_POST['add_dep_dates'] ?? [];
        $add_dep_notes = $_POST['add_dep_notes'] ?? [];
        for ($i = 0; $i < count($add_dep_amounts); $i++) {
            $amt = trim($add_dep_amounts[$i]);
            $dt = trim($add_dep_dates[$i] ?? '');
            $nt = trim($add_dep_notes[$i] ?? '');
            if ($amt !== '') {
                $additional_deposits_data[] = [
                    'amount' => (int)$amt,
                    'date' => $dt !== '' ? $dt : null,
                    'note' => $nt !== '' ? $nt : null
                ];
            }
        }
        $additional_deposits_json = count($additional_deposits_data) > 0 ? json_encode($additional_deposits_data, JSON_UNESCAPED_UNICODE) : null;

        // 全体の入金総額と入金日の自動連動
        $dep_50 = isset($_POST['deposit_amount_50']) && $_POST['deposit_amount_50'] !== '' ? (int)$_POST['deposit_amount_50'] : 0;
        $dep_rem = isset($_POST['deposit_amount_rem']) && $_POST['deposit_amount_rem'] !== '' ? (int)$_POST['deposit_amount_rem'] : 0;
        
        $total_add_dep = 0;
        foreach ($additional_deposits_data as $ad) {
            $total_add_dep += $ad['amount'];
        }
        $total_dep = $dep_50 + $dep_rem + $total_add_dep;
        
        $dep_date_50 = !empty($_POST['deposit_date_50']) ? $_POST['deposit_date_50'] : null;
        $dep_date_rem = !empty($_POST['deposit_date_rem']) ? $_POST['deposit_date_rem'] : null;
        
        // 最終入金日の判定
        $total_dep_date = null;
        $all_dates = array_filter([$dep_date_50, $dep_date_rem]);
        foreach ($additional_deposits_data as $ad) {
            if ($ad['date']) $all_dates[] = $ad['date'];
        }
        if (!empty($all_dates)) {
            usort($all_dates, function($a, $b) { return strtotime($a) - strtotime($b); });
            $total_dep_date = end($all_dates);
        }

        // 追加費用の合計と最終追加日の算出
        $total_add_est = 0;
        $last_add_date = null;
        foreach ($add_estimates_data as $ae) {
            $total_add_est += $ae['amount'];
            if ($ae['date']) {
                $last_add_date = $ae['date'];
            }
        }
        // 既存の財務データを取得
        $stmtGetOld = $pdo->prepare("
            SELECT initial_est_amount, initial_est_date, formal_est_amount, formal_est_date, 
                   add_est_amount, add_est_date, deposit_amount, deposit_date, 
                   deposit_amount_50, deposit_amount_rem, deposit_date_50, deposit_date_rem, 
                   additional_estimates, additional_deposits, billing_company_name 
            FROM projects WHERE id = :id
        ");
        $stmtGetOld->execute(['id' => $project_id]);
        $old_finance = $stmtGetOld->fetch(PDO::FETCH_ASSOC);

        $old_dep_50 = $old_finance ? (int)$old_finance['deposit_amount_50'] : 0;
        $old_dep_rem = $old_finance ? (int)$old_finance['deposit_amount_rem'] : 0;
        $old_add_deps_json = $old_finance ? $old_finance['additional_deposits'] : '[]';
        $old_add_deps = json_decode($old_add_deps_json ?? '[]', true) ?: [];
        $old_add_deps_total = 0;
        foreach ($old_add_deps as $oad) {
            $old_add_deps_total += (int)$oad['amount'];
        }

        $stmt = $pdo->prepare("
            UPDATE projects 
            SET 
                initial_est_amount = :initial_amt,
                initial_est_date = :initial_date,
                formal_est_amount = :formal_amt,
                formal_est_date = :formal_date,
                add_est_amount = :add_amt,
                add_est_date = :add_date,
                deposit_amount = :dep_amt,
                deposit_date = :dep_date,
                deposit_amount_50 = :dep_50,
                deposit_amount_rem = :dep_rem,
                deposit_date_50 = :dep_date_50,
                deposit_date_rem = :dep_date_rem,
                additional_estimates = :add_estimates_json,
                additional_deposits = :additional_deposits_json,
                billing_company_name = :billing_name
            WHERE id = :id
        ");
        
        $new_initial_amt = $_POST['initial_est_amount'] === '' ? null : (int)$_POST['initial_est_amount'];
        $new_initial_date = $_POST['initial_est_date'] === '' ? null : $_POST['initial_est_date'];
        $new_formal_amt = $_POST['formal_est_amount'] === '' ? null : (int)$_POST['formal_est_amount'];
        $new_formal_date = $_POST['formal_est_date'] === '' ? null : $_POST['formal_est_date'];
        $new_billing_name = $_POST['billing_company_name'] ?? null;

        $stmt->execute([
            'initial_amt' => $new_initial_amt,
            'initial_date' => $new_initial_date,
            'formal_amt' => $new_formal_amt,
            'formal_date' => $new_formal_date,
            'add_amt' => $total_add_est,
            'add_date' => $last_add_date,
            'dep_amt' => $total_dep,
            'dep_date' => $total_dep_date,
            'dep_50' => $dep_50,
            'dep_rem' => $dep_rem,
            'dep_date_50' => $dep_date_50,
            'dep_date_rem' => $dep_date_rem,
            'add_estimates_json' => $add_estimates_json,
            'additional_deposits_json' => $additional_deposits_json,
            'billing_name' => $new_billing_name,
            'id' => $project_id
        ]);

        // 差分検知と詳細チャットメッセージ構築
        if ($old_finance) {
            $changes = [];

            // 初期見積額/日
            if ((int)$old_finance['initial_est_amount'] !== (int)$new_initial_amt || $old_finance['initial_est_date'] !== $new_initial_date) {
                $old_val = $old_finance['initial_est_amount'] ? number_format($old_finance['initial_est_amount']) . "円" : "未設定";
                $new_val = $new_initial_amt ? number_format($new_initial_amt) . "円" : "未設定";
                $old_dt = $old_finance['initial_est_date'] ?: "未設定";
                $new_dt = $new_initial_date ?: "未設定";
                $changes[] = "・初期見積額: {$old_val} ({$old_dt}) ➔ {$new_val} ({$new_dt})";
            }

            // 本見積額/日
            if ((int)$old_finance['formal_est_amount'] !== (int)$new_formal_amt || $old_finance['formal_est_date'] !== $new_formal_date) {
                $old_val = $old_finance['formal_est_amount'] ? number_format($old_finance['formal_est_amount']) . "円" : "未設定";
                $new_val = $new_formal_amt ? number_format($new_formal_amt) . "円" : "未設定";
                $old_dt = $old_finance['formal_est_date'] ?: "未設定";
                $new_dt = $new_formal_date ?: "未設定";
                $changes[] = "・本見積額: {$old_val} ({$old_dt}) ➔ {$new_val} ({$new_dt})";
            }

            // 追加見積の変動
            if (($old_finance['additional_estimates'] ?? '[]') !== ($add_estimates_json ?? '[]')) {
                $old_add_total = 0;
                $old_add_arr = json_decode($old_finance['additional_estimates'] ?? '[]', true) ?: [];
                foreach ($old_add_arr as $oae) $old_add_total += (int)$oae['amount'];
                $changes[] = "・追加見積合計: " . number_format($old_add_total) . "円 ➔ " . number_format($total_add_est) . "円";
            }

            $is_deposit_updated = false;

            // 50%着手金入金
            if ($old_dep_50 !== $dep_50 || $old_finance['deposit_date_50'] !== $dep_date_50) {
                $old_val = $old_dep_50 ? number_format($old_dep_50) . "円" : "未設定";
                $new_val = $dep_50 ? number_format($dep_50) . "円" : "未設定";
                $old_dt = $old_finance['deposit_date_50'] ?: "未設定";
                $new_dt = $dep_date_50 ?: "未設定";
                $changes[] = "・50%着手金入金: {$old_val} ({$old_dt}) ➔ {$new_val} ({$new_dt})";
                if ($dep_50 > $old_dep_50 || (!empty($dep_date_50) && empty($old_finance['deposit_date_50']))) {
                    $is_deposit_updated = true;
                }
            }

            // 残金入金
            if ($old_dep_rem !== $dep_rem || $old_finance['deposit_date_rem'] !== $dep_date_rem) {
                $old_val = $old_dep_rem ? number_format($old_dep_rem) . "円" : "未設定";
                $new_val = $dep_rem ? number_format($dep_rem) . "円" : "未設定";
                $old_dt = $old_finance['deposit_date_rem'] ?: "未設定";
                $new_dt = $dep_date_rem ?: "未設定";
                $changes[] = "・残金入金: {$old_val} ({$old_dt}) ➔ {$new_val} ({$new_dt})";
                if ($dep_rem > $old_dep_rem || (!empty($dep_date_rem) && empty($old_finance['deposit_date_rem']))) {
                    $is_deposit_updated = true;
                }
            }

            // 追加入金の変動
            if ($old_add_deps_json !== ($additional_deposits_json ?? '[]')) {
                $changes[] = "・追加入金合計: " . number_format($old_add_deps_total) . "円 ➔ " . number_format($total_add_dep) . "円";
                if ($total_add_dep > $old_add_deps_total) {
                    $is_deposit_updated = true;
                }
            }

            // 宛名
            if (($old_finance['billing_company_name'] ?? '') !== ($new_billing_name ?? '')) {
                $old_name = $old_finance['billing_company_name'] ?: "未設定";
                $new_name = $new_billing_name ?: "未設定";
                $changes[] = "・宛名: 「{$old_name}」 ➔ 「{$new_name}」";
            }

            if (!empty($changes)) {
                $prefix = "";
                if ($is_deposit_updated) {
                    $prefix = "ご入金ありがとうございました。入金確認いたしました。\n\n";
                }
                $msg_text = $prefix . "【経理情報更新】\n経理担当（または管理者）が金銭データを更新しました。\n" . implode("\n", $changes);
                $stmtChat = $pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text, created_at)
                    VALUES (:pid, :sid, 'client_admin', :msg, NOW())
                ");
                $stmtChat->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => $msg_text
                ]);
            }
        }

        // 協力業者支払情報の更新
        $order_payment_statuses = $_POST['order_payment_statuses'] ?? [];
        $order_payment_dates = $_POST['order_payment_dates'] ?? [];
        foreach ($order_payment_statuses as $order_id => $pay_status) {
            $pay_date = !empty($order_payment_dates[$order_id]) ? $order_payment_dates[$order_id] : null;
            $stmtSubOrder = $pdo->prepare("
                UPDATE subcontractor_orders 
                SET payment_status = :pay_status,
                    payment_date = :pay_date
                WHERE id = :id
            ");
            $stmtSubOrder->execute([
                'pay_status' => $pay_status,
                'pay_date' => $pay_date,
                'id' => (int)$order_id
            ]);
        }

        // 一次請求書(50%)の自動発行処理
        if (isset($_POST['issue_primary_invoice']) && $_POST['issue_primary_invoice'] === '1') {
            require_once __DIR__ . '/action_issue_invoice_helper.php';
            issuePrimaryInvoiceHelper($pdo, $project_id, $_SESSION['user_id']);
        }

        header("Location: ../project_detail.php?id=" . $project_id . "&t=" . time());
        exit;
    } catch (Exception $e) {
        die("更新に失敗しました: " . $e->getMessage());
    }
}
