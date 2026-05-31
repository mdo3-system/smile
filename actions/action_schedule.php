<?php
// actions/action_schedule.php

// 管理者による一次回答期日設定
if ($action === 'set_primary_due_date') {
    if ($is_admin) {
        $due_date = $_POST['primary_due_date'] ?? null;
        if ($due_date) {
            $projectRepo->updatePrimaryDueDate($project_id, $due_date);
            
            // 設計着手・スケジュール確定のステータスへ進める
            // primary_prep → contracted (スケジュール確定済み)
            if (($project_info['status'] ?? '') === 'primary_prep') {
                $projectRepo->updateStatus($project_id, 'contracted');
            }
            
            // Auto message
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => "【通知】一次回答の基準日（期日）が {$due_date} に設定され、スケジュールが確定しました。左パネルのスケジュール表をご確認ください。"
            ]);
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

// スケジュール実施日（実績）の更新
if ($action === 'update_schedule_actual') {
    if ($is_admin) {
        $step_idx = $_POST['step_idx'] ?? '';
        $actual_date = $_POST['actual_date'] ?? '';
        if ($step_idx !== '') {
            $projData = $projectRepo->findById($project_id);
            $current_actuals_json = $projData ? $projData['schedule_actuals'] : '{}';
            $actuals = json_decode($current_actuals_json ?? '{}', true) ?: [];
            
            if (empty($actual_date)) {
                unset($actuals[$step_idx]);
            } else {
                $actuals[$step_idx] = $actual_date;
            }
            $stmt = $pdo->prepare("UPDATE projects SET schedule_actuals = :act WHERE id = :pid");
            $stmt->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
