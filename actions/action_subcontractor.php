<?php
// actions/action_subcontractor.php

// 新規発注依頼の保存
if ($action === 'order_subcontractor') {
    $pdo->beginTransaction();
    try {
        // 納期計算: 依頼日+3営業日 (簡易的に+3 day)
        // TODO: functions.php の営業日計算関数を使うのが理想だが、ここでは一旦直接+3 day
        $due_date = date('Y-m-d', strtotime('+3 weekdays'));

        $stmt = $pdo->prepare("INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status, due_date, order_type, floor_area, opt_kiso, opt_yuka) VALUES (:pid, :sub_id, :task, :amount, 'requested', :due, :type, :area, :kiso, :yuka)");
        $stmt->execute([
            'pid' => $project_id,
            'sub_id' => $_POST['subcontractor_id'],
            'task' => $_POST['task_title'],
            'amount' => $_POST['order_amount'],
            'due' => $due_date,
            'type' => $_POST['order_type'] ?? 'design',
            'area' => $_POST['floor_area'] ?? 0,
            'kiso' => isset($_POST['opt_kiso']) ? 1 : 0,
            'yuka' => isset($_POST['opt_yuka']) ? 1 : 0
        ]);

        // 案件のステータスを自動更新 (発注種類に応じて)
        $order_type = $_POST['order_type'] ?? 'design';
        if ($order_type === 'design') {
            // 意匠図作図依頼の場合は「構造仕様ヒアリング・確認・作図」などのステータスへ
            $projectRepo->updateStatus($project_id, 'primary_prep');
            
            // 単価の復元 (階層別)
            $area = (float)($_POST['floor_area'] ?? 0);
            if ($area > 200) {
                $formula = "(50円×100㎡ + 40円×100㎡ + 30円×" . ($area - 200) . "㎡)";
            } else if ($area > 100) {
                $formula = "(50円×100㎡ + 40円×" . ($area - 100) . "㎡)";
            } else {
                $formula = "(50円×{$area}㎡)";
            }
        } else {
            // 構造図作図依頼の場合は「構造図作成中」
            $projectRepo->updateStatus($project_id, 'structural_dwg');
            
            $area = (float)($_POST['floor_area'] ?? 0);
            if ($area > 200) {
                $formula = "(60円×100㎡ + 50円×100㎡ + 40円×" . ($area - 200) . "㎡)";
            } else if ($area > 100) {
                $formula = "(60円×100㎡ + 50円×" . ($area - 100) . "㎡)";
            } else {
                $formula = "(60円×{$area}㎡)";
            }
            
            $optAmount = 0;
            if (isset($_POST['opt_kiso'])) $optAmount += 1000;
            if (isset($_POST['opt_yuka'])) $optAmount += 1000;
            if ($optAmount > 0) $formula .= " + オプション: {$optAmount}円";
        }
        
        // チャットへ発注通知と計算式を送信
        $order_amount_formatted = number_format($_POST['order_amount']);
        $msg = "【新規発注のお知らせ】\n";
        $msg .= "業務: " . $_POST['task_title'] . "\n";
        $msg .= "発注額: {$order_amount_formatted} 円\n";
        $msg .= "計算式: {$formula}\n\n";
        $msg .= "上記の通り発注いたしました。よろしくお願いいたします。";
        
        $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'sub_admin', :msg)");
        $stmtMsg->execute([
            'pid' => $project_id,
            'sid' => $_SESSION['user_id'],
            'msg' => $msg
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("発注処理に失敗しました: " . $e->getMessage());
    }
    header("Location: project_subcontractor.php?id=" . $project_id . "&t=" . time()); exit;
}

// 納品承認処理
if ($action === 'approve_delivery') {
    $order_id = intval($_POST['order_id']);
    $pdo->beginTransaction();
    try {
        // 1. 発注ステータスを completed に更新し、納品完了日を記録
        $stmt = $pdo->prepare("UPDATE subcontractor_orders SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute(['id' => $order_id]);

        // 2. 案件ステータスを「提出済・確認中 (submission)」に更新
        $projectRepo->updateStatus($project_id, 'submission');

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("承認処理に失敗しました: " . $e->getMessage());
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
