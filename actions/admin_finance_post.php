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
        // 既存の入金値を取得
        $stmtGetOld = $pdo->prepare("SELECT deposit_amount_50, deposit_amount_rem, additional_deposits FROM projects WHERE id = :id");
        $stmtGetOld->execute(['id' => $project_id]);
        $old_finance = $stmtGetOld->fetch();
        $old_dep_50 = $old_finance ? (int)$old_finance['deposit_amount_50'] : 0;
        $old_dep_rem = $old_finance ? (int)$old_finance['deposit_amount_rem'] : 0;
        $old_add_deps = json_decode($old_finance['additional_deposits'] ?? '[]', true) ?: [];
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
        
        $stmt->execute([
            'initial_amt' => $_POST['initial_est_amount'] === '' ? null : (int)$_POST['initial_est_amount'],
            'initial_date' => $_POST['initial_est_date'] === '' ? null : $_POST['initial_est_date'],
            'formal_amt' => $_POST['formal_est_amount'] === '' ? null : (int)$_POST['formal_est_amount'],
            'formal_date' => $_POST['formal_est_date'] === '' ? null : $_POST['formal_est_date'],
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
            'billing_name' => $_POST['billing_company_name'] ?? null,
            'id' => $project_id
        ]);

        // 入金増加のチェックと自動チャット送信
        $diff_dep_50 = $dep_50 - $old_dep_50;
        $diff_dep_rem = $dep_rem - $old_dep_rem;
        $diff_add_dep = $total_add_dep - $old_add_deps_total;

        if ($diff_dep_50 > 0) {
            $msg_text = "（経理担当）" . number_format($diff_dep_50) . "円ご入金ありがとうございました。入金確認いたしました";
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
        if ($diff_dep_rem > 0) {
            $msg_text = "（経理担当）" . number_format($diff_dep_rem) . "円ご入金ありがとうございました。入金確認いたしました";
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
        if ($diff_add_dep > 0) {
            $msg_text = "（経理担当）追加入金 " . number_format($diff_add_dep) . "円ご入金ありがとうございました。入金確認いたしました";
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

        header("Location: ../project_detail.php?id=" . $project_id . "&t=" . time());
        exit;
    } catch (Exception $e) {
        die("更新に失敗しました: " . $e->getMessage());
    }
}
