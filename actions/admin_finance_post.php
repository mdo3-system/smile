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

        // 全体の入金総額と入金日の自動連動
        $dep_50 = isset($_POST['deposit_amount_50']) && $_POST['deposit_amount_50'] !== '' ? (int)$_POST['deposit_amount_50'] : 0;
        $dep_rem = isset($_POST['deposit_amount_rem']) && $_POST['deposit_amount_rem'] !== '' ? (int)$_POST['deposit_amount_rem'] : 0;
        $total_dep = $dep_50 + $dep_rem;
        
        $dep_date_50 = !empty($_POST['deposit_date_50']) ? $_POST['deposit_date_50'] : null;
        $dep_date_rem = !empty($_POST['deposit_date_rem']) ? $_POST['deposit_date_rem'] : null;
        $total_dep_date = !empty($dep_date_rem) ? $dep_date_rem : (!empty($dep_date_50) ? $dep_date_50 : null);

        // 追加費用の合計と最終追加日の算出
        $total_add_est = 0;
        $last_add_date = null;
        foreach ($add_estimates_data as $ae) {
            $total_add_est += $ae['amount'];
            if ($ae['date']) {
                $last_add_date = $ae['date'];
            }
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
            'billing_name' => $_POST['billing_company_name'] ?? null,
            'id' => $project_id
        ]);

        header("Location: ../project_detail.php?id=" . $project_id . "&t=" . time());
        exit;
    } catch (Exception $e) {
        die("更新に失敗しました: " . $e->getMessage());
    }
}
