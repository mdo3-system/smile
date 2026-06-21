<?php
// actions/action_schedule.php

if (isset($project_id) && (!isset($project_info) || !$project_info)) {
    $stmtProj = $pdo->prepare("
        SELECT p.*, s.*, u.company_name, u.contact_name as client_name, u.phone_number as client_phone
        FROM projects p 
        LEFT JOIN project_specs s ON p.id = s.project_id 
        LEFT JOIN users u ON p.client_id = u.id
        WHERE p.id = :id
    ");
    $stmtProj->execute(['id' => $project_id]);
    $project_info = $stmtProj->fetch();
}

// 管理者による一次回答期日設定
if ($action === 'set_primary_due_date') {
    if ($is_admin) {
        $due_date = $_POST['primary_due_date'] ?? null;
        if ($due_date) {
            // 外皮計算依頼（req_skin = 1）の場合、仕様書(spec_doc)がアップロードされているかチェック
            if (($project_info['req_skin'] ?? 0) == 1) {
                $stmtCheckSpec = $pdo->prepare("
                    SELECT COUNT(*) FROM project_files 
                    WHERE project_id = :pid 
                    AND file_category = 'spec_doc' 
                    AND is_latest = 1
                ");
                $stmtCheckSpec->execute(['pid' => $project_id]);
                if ((int)$stmtCheckSpec->fetchColumn() === 0) {
                    die("処理に失敗しました: 一次回答期日の設定には仕様書のアップロードが必須です。依頼主にアップロードを依頼するか、仕様書をアップロードした後に再度設定してください。");
                }
            }

            $projectRepo->updatePrimaryDueDate($project_id, $due_date);
            
            // スケジュール実績JSONのインデックス 1 (着手基準日・一次回答) に同日を保存
            $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
            $stmtAct->execute(['id' => $project_id]);
            $act_row = $stmtAct->fetch(PDO::FETCH_ASSOC);

            if ($act_row) {
                $colsToUpdate = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                foreach ($colsToUpdate as $col) {
                    $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
                    $actuals[1] = $due_date;
                    $stmtUpdateAct = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                    $stmtUpdateAct->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
                }
            }
            
            // 設計着手・スケジュール確定のステータスへ進める
            // primary_prep → contracted (スケジュール確定済み)
            if (($project_info['status'] ?? '') === 'primary_prep') {
                $projectRepo->updateStatus($project_id, 'contracted');
            }
            
            // Auto message
            $msg = "【通知】一次回答の基準日（期日）が {$due_date} に設定され、スケジュールが確定しました。左パネルのスケジュール表をご確認ください。\n\n";
            $msg .= "【今後の進め方について】\n";
            $msg .= "・図書の修正やご要望などがある場合は、こちらのチャットにメッセージの入力やファイルの添付をお願いいたします。\n";
            $msg .= "・修正がなくそのまま進める場合は、チャット欄に「GO」とご返信ください。ご返信を確認後、申請図書一式を作成いたします。";

            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $msg
            ]);
        }
    }
    try {
        $calendarService = new \App\Services\GoogleCalendarService($pdo);
        $calendarService->syncProjectEvents($project_id);
    } catch (Exception $cal_err) {}
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

// スケジュール予定日の個別上書き
if ($action === 'update_schedule_override') {
    if ($is_admin) {
        $step_idx = $_POST['step_idx'] ?? '';
        $override_date = $_POST['override_date'] ?? '';
        $schedule_type = $_POST['schedule_type'] ?? 'permit';
        
        $col_map = [
            'permit' => 'schedule_overrides',
            'wall' => 'schedule_overrides_wall',
            'skin' => 'schedule_overrides_skin',
            'sky' => 'schedule_overrides_sky',
        ];
        $db_col = $col_map[$schedule_type] ?? 'schedule_overrides';

        if ($step_idx !== '') {
            $stmtCol = $pdo->prepare("SELECT {$db_col} FROM projects WHERE id = :id");
            $stmtCol->execute(['id' => $project_id]);
            $current_overrides_json = $stmtCol->fetchColumn();
            
            $overrides = json_decode($current_overrides_json ?? '{}', true) ?: [];
            
            if (empty($override_date)) {
                unset($overrides[$step_idx]);
            } else {
                $overrides[$step_idx] = $override_date;
            }
            $stmt = $pdo->prepare("UPDATE projects SET {$db_col} = :act WHERE id = :pid");
            $stmt->execute(['act' => json_encode($overrides), 'pid' => $project_id]);
            
            // もし一次回答予定日 (idx=1) が上書きされた場合は、primary_due_date カラムも同期する
            if ($step_idx == 1 && !empty($override_date)) {
                $projectRepo->updatePrimaryDueDate($project_id, $override_date);
            }

            // チャットへ自動通知メッセージを投稿
            $base_days = getScheduleBaseDays($project_info);
            if ($schedule_type === 'permit') {
                $is_koyou_or_kisohari = (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1);
                $steps = getScheduleSteps($base_days, $is_koyou_or_kisohari);
            } elseif ($schedule_type === 'wall') {
                $steps = getScheduleStepsWall($base_days);
            } elseif ($schedule_type === 'skin') {
                $steps = getScheduleStepsSkin($base_days);
            } else {
                $steps = getScheduleStepsSky($base_days);
            }
            
            $step_name = $steps[$step_idx]['name'] ?? "工程 #{$step_idx}";
            $formatted_date = !empty($override_date) ? date('Y/m/d', strtotime($override_date)) : '未確定';
            $chat_msg = "【スケジュール予定日更新】\n{$step_name} の予定日が「{$formatted_date}」に変更されました。";

            $thread_type = ($schedule_type === 'permit') ? 'client_admin_permit' : 'client_admin_' . $schedule_type;
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'] ?? 1,
                'thread' => $thread_type,
                'msg' => $chat_msg
            ]);
        }
    }
    try {
        $calendarService = new \App\Services\GoogleCalendarService($pdo);
        $calendarService->syncProjectEvents($project_id);
    } catch (Exception $cal_err) {}
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($schedule_type) . "&t=" . time()); exit;
}

// スケジュール実施日（実績）の更新
if ($action === 'update_schedule_actual') {
    if ($is_admin) {
        $step_idx = $_POST['step_idx'] ?? '';
        $actual_date = $_POST['actual_date'] ?? '';
        $schedule_type = $_POST['schedule_type'] ?? 'permit';
        
        $col_map = [
            'permit' => 'schedule_actuals',
            'wall' => 'schedule_actuals_wall',
            'skin' => 'schedule_actuals_skin',
            'sky' => 'schedule_actuals_sky',
        ];
        $db_col = $col_map[$schedule_type] ?? 'schedule_actuals';

        if ($step_idx !== '') {
            $stmtCol = $pdo->prepare("SELECT {$db_col} FROM projects WHERE id = :id");
            $stmtCol->execute(['id' => $project_id]);
            $current_actuals_json = $stmtCol->fetchColumn();
            
            $actuals = json_decode($current_actuals_json ?? '{}', true) ?: [];
            
            if (empty($actual_date)) {
                unset($actuals[$step_idx]);
            } else {
                $actuals[$step_idx] = $actual_date;
            }
            $stmt = $pdo->prepare("UPDATE projects SET {$db_col} = :act WHERE id = :pid");
            $stmt->execute(['act' => json_encode($actuals), 'pid' => $project_id]);

            // 「申請図書一式UP」の実施日が設定された場合、案件ステータスを「申請中」(submitting) に自動遷移
            if (!empty($actual_date)) {
                $is_submitting_step = false;
                if ($schedule_type === 'permit' && $step_idx == 7) $is_submitting_step = true;
                if ($schedule_type === 'wall' && $step_idx == 4) $is_submitting_step = true;
                if ($schedule_type === 'skin' && $step_idx == 4) $is_submitting_step = true;
                if ($schedule_type === 'sky' && $step_idx == 3) $is_submitting_step = true;

                if ($is_submitting_step) {
                    $stmtStatusUpd = $pdo->prepare("UPDATE projects SET status = 'submitting' WHERE id = :pid");
                    $stmtStatusUpd->execute(['pid' => $project_id]);
                }
            }

            // チャットへ自動通知メッセージを投稿 (実績日が空の場合は通知しない)
            if (!empty($actual_date)) {
                $base_days = getScheduleBaseDays($project_info);
                if ($schedule_type === 'permit') {
                    $is_koyou_or_kisohari = (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1);
                    $steps = getScheduleSteps($base_days, $is_koyou_or_kisohari);
                } elseif ($schedule_type === 'wall') {
                    $steps = getScheduleStepsWall($base_days);
                } elseif ($schedule_type === 'skin') {
                    $steps = getScheduleStepsSkin($base_days);
                } else {
                    $steps = getScheduleStepsSky($base_days);
                }
                
                $step_name = $steps[$step_idx]['name'] ?? "工程 #{$step_idx}";
                $action_desc = "「{$actual_date}」に設定";
                $chat_msg = "【スケジュール実績更新】\n{$step_name} の実施日が{$action_desc}されました。";

                // 「補正対応」の実施日が設定された場合、取引条件のチャット通知を追加
                $is_correction_step = false;
                if ($schedule_type === 'permit' && $step_idx == 9) $is_correction_step = true;
                if ($schedule_type === 'wall' && $step_idx == 6) $is_correction_step = true;
                if ($schedule_type === 'skin' && $step_idx == 6) $is_correction_step = true;
                if ($schedule_type === 'sky' && $step_idx == 6) $is_correction_step = true;

                if ($is_correction_step) {
                    $chat_msg .= "\n審査完了しましたら、審査完了にしていただき、1週間以内の残金のご清算をお願いします。初回見積もり時に、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算が、お取引条件となります。";
                }
                
                $thread_type = ($schedule_type === 'permit') ? 'client_admin_permit' : 'client_admin_' . $schedule_type;
                $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                $stmtMsg->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'thread' => $thread_type,
                    'msg' => $chat_msg
                ]);
            }
        }
    }
    try {
        $calendarService = new \App\Services\GoogleCalendarService($pdo);
        $calendarService->syncProjectEvents($project_id);
    } catch (Exception $cal_err) {}
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($schedule_type) . "&t=" . time()); exit;
}

// 設計開始 (start_design)
if ($action === 'start_design') {
    if ($is_admin) {
        $projectRepo->updateStatus($project_id, 'structural_dwg');
        
        // 自動メッセージ
        $proj_name = $project_info['project_name'] ?? '案件';
        $due_date = $project_info['primary_due_date'] ?? '-';
        $msg = "【通知】案件「{$proj_name}」の構造計算・設計に着手いたしました。一次回答期日（{$due_date}）に向けて作業を進めてまいります。何卒よろしくお願いいたします。";
        
        $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
        $stmtMsg->execute([
            'pid' => $project_id,
            'sid' => $_SESSION['user_id'],
            'msg' => $msg
        ]);
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

// 一次回答 ＆ 50%請求書の発行 (submit_primary_response)
if ($action === 'submit_primary_response') {
    if ($is_admin) {
        $formal_amt = $_POST['formal_est_amount'] ?? '';
        $formal_date = $_POST['formal_est_date'] ?? '';
        
        if (empty($formal_amt) || intval($formal_amt) <= 0) {
            die("処理に失敗しました: 本見積額を正しく入力してください。");
        }
        if (empty($_FILES['primary_file']['name']) || $_FILES['primary_file']['error'] !== UPLOAD_ERR_OK) {
            die("処理に失敗しました: 一次回答ファイルのアップロードに失敗しました。");
        }

        $pdo->beginTransaction();
        try {
            // A. 本見積額の保存 (projects テーブル)
            $stmtUpdateProj = $pdo->prepare("
                UPDATE projects 
                SET formal_est_amount = :amt, formal_est_date = :dt 
                WHERE id = :pid
            ");
            $stmtUpdateProj->execute([
                'amt' => $formal_amt,
                'dt' => $formal_date,
                'pid' => $project_id
            ]);

            // B. 一次回答ファイルのアップロード (Google Drive ＋ project_files 登録)
            require_once 'google_drive_client.php';
            
            $file_name = $_FILES['primary_file']['name'];
            $tmp_name  = $_FILES['primary_file']['tmp_name'];
            $mime_type = $_FILES['primary_file']['type'];
            
            $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type, $project_id, $pdo);
            
            $tab = $_POST['tab'] ?? '';
            $file_category = 'calc_doc'; // 構造計算書
            if ($tab === 'wall') {
                $file_category = 'wall_calc_doc';
            } elseif ($tab === 'skin') {
                $file_category = 'skin_calc_doc';
            } elseif ($tab === 'sky') {
                $file_category = 'sky_calc_doc';
            }
            
            // C. ステータス更新処理を削除（一次回答時点では contracted 状態を維持）


            // D. スケジュール実績 JSON の更新（インデックス 2: 構造計算・図面 初回提示 に今日の日付を設定）
            $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
            $stmtAct->execute(['id' => $project_id]);
            $act_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
            $today = date('Y-m-d');
            if ($act_row) {
                $colsToUpdate = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                foreach ($colsToUpdate as $col) {
                    $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
                    $actuals[2] = $today; // 初回提示
                    $stmtUpdateAct = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                    $stmtUpdateAct->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
                }
            }

            // E. 一次請求書(50%)の自動発行＆チャット通知 (共通ヘルパーの呼び出し)
            require_once __DIR__ . '/action_issue_invoice_helper.php';
            issuePrimaryInvoiceHelper($pdo, $project_id, $_SESSION['user_id']);

            // F. 一次回答の提示完了チャット通知を追加 (計算書ファイルをチャットにUP)
            $msg = "【一次回答の提示】\n一次回答の計算図書「{$file_name}」をアップロードいたしました。ファイル一覧（成果物）よりご確認ください。\n何卒よろしくお願いいたします。";
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text, file_path) VALUES (:pid, :sid, 'client_admin', :msg, :fpath)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $msg,
                'fpath' => $drive_file_id
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("一次回答の登録に失敗しました: " . $e->getMessage());
        }
    }
    try {
        $calendarService = new \App\Services\GoogleCalendarService($pdo);
        $calendarService->syncProjectEvents($project_id);
    } catch (Exception $cal_err) {}
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

// 審査完了 (complete_review) - 依頼主または管理者による操作
if ($action === 'complete_review') {
    $projectRepo->updateStatus($project_id, 'completed');
    
    // スケジュール実績 JSON の更新（審査完了時の実績日設定）
    $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
    $stmtAct->execute(['id' => $project_id]);
    $act_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');
    if ($act_row) {
        $cols_to_steps = [
            'schedule_actuals' => [8, 9],
            'schedule_actuals_wall' => [5, 6],
            'schedule_actuals_skin' => [5, 6],
            'schedule_actuals_sky' => [5, 6],
        ];
        foreach ($cols_to_steps as $col => $steps) {
            $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
            foreach ($steps as $step_idx) {
                if (empty($actuals[$step_idx])) {
                    $actuals[$step_idx] = $today;
                }
            }
            $stmtUpdateAct = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
            $stmtUpdateAct->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
        }
    }
    
    // 自動メッセージ
    $msg = "【通知】確認機関の「審査完了（審査合格）」が登録されました。ステータスが「完了」に変更されました。\nこれに伴い、設計業務を完了とし、残金のご精算（完了後7日以内）の手続きを開始いたします。";
    $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
    $stmtMsg->execute([
        'pid' => $project_id,
        'sid' => $_SESSION['user_id'],
        'msg' => $msg
    ]);

    // Googleカレンダー連携が有効な場合はカレンダーへも適宜反映されるようにする
    try {
        if (class_exists('App\Services\GoogleCalendarService')) {
            $calendarService = new \App\Services\GoogleCalendarService($pdo);
            $calendarService->syncProjectEvents($project_id);
        }
    } catch (Exception $cal_err) {
        // カレンダー連携エラーはログに記録するか無視して進行
    }

    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

