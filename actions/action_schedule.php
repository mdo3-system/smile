<?php
// actions/action_schedule.php

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
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
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

            // チャットへ自動通知メッセージを投稿
            $base_days = getScheduleBaseDays($project_info);
            if ($schedule_type === 'permit') {
                $steps = getScheduleSteps($base_days);
            } elseif ($schedule_type === 'wall') {
                $steps = getScheduleStepsWall($base_days);
            } elseif ($schedule_type === 'skin') {
                $steps = getScheduleStepsSkin($base_days);
            } else {
                $steps = getScheduleStepsSky($base_days);
            }
            
            $step_name = $steps[$step_idx]['name'] ?? "工程 #{$step_idx}";
            $action_desc = empty($actual_date) ? "削除" : "「{$actual_date}」に設定";
            $chat_msg = "【スケジュール実績更新】\n{$step_name} の実施日が{$action_desc}されました。";
            
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $chat_msg
            ]);
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
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
            
            $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);
            
            $file_category = 'calc_doc'; // 構造計算書
            
            // 既存の同カテゴリファイルを is_latest=0 にする
            $stmtOld = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
            $stmtOld->execute(['pid' => $project_id, 'cat' => $file_category]);
            
            // バージョン番号
            $stmtVer = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
            $stmtVer->execute(['pid' => $project_id, 'cat' => $file_category]);
            $next_ver = intval($stmtVer->fetchColumn()) + 1;
            
            $stmtNewFile = $pdo->prepare("
                INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                VALUES (:pid, :cat, :name, :fid, :ver, 1)
            ");
            $stmtNewFile->execute([
                'pid'  => $project_id,
                'cat'  => $file_category,
                'name' => $file_name,
                'fid'  => $drive_file_id,
                'ver'  => $next_ver
            ]);

            // C. ステータスを submission（提出済・確認中）に更新
            $projectRepo->updateStatus($project_id, 'submission');

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

            // F. 一次回答の提示完了チャット通知を追加
            $msg = "【一次回答の提示】\n一次回答の計算図書「{$file_name}」をアップロードいたしました。ファイル一覧（成果物）よりご確認ください。\n何卒よろしくお願いいたします。";
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $msg
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("一次回答の登録に失敗しました: " . $e->getMessage());
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
