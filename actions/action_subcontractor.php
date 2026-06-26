<?php
// actions/action_subcontractor.php

// 新規発注依頼の保存
if ($action === 'order_subcontractor') {
    $pdo->beginTransaction();
    try {
        // 納期
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+3 weekdays'));

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
            // 構造図作図依頼の場合は「申請図書作成中」
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
        $msg .= "希望納品日: " . ($due_date ? date('Y/m/d', strtotime($due_date)) : '-') . "\n";
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

// 納品承認または修正依頼処理
if ($action === 'approve_delivery') {
    $order_id = intval($_POST['order_id']);
    
    // 修正依頼の場合
    if (isset($_POST['reject_delivery'])) {
        $pdo->beginTransaction();
        try {
            // ステータスを作業中 (accepted) に戻す
            $stmtOrder = $pdo->prepare("UPDATE subcontractor_orders SET status = 'accepted' WHERE id = :id");
            $stmtOrder->execute(['id' => $order_id]);
            
            // チャットへ修正依頼を自動投稿 (sub_admin)
            $msg = "【修正依頼】\n提出いただいた成果品に修正事項があります。お手数ですが、修正の上、再アップロードをお願いいたします。";
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'sub_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $msg
            ]);
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("修正依頼処理に失敗しました: " . $e->getMessage());
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }
    
    // 承認処理
    $pdo->beginTransaction();
    try {
        // 0. 発注タスクの情報を取得
        $stmtOrderInfo = $pdo->prepare("SELECT order_type FROM subcontractor_orders WHERE id = :id");
        $stmtOrderInfo->execute(['id' => $order_id]);
        $order_info = $stmtOrderInfo->fetch();
        $order_type = $order_info ? $order_info['order_type'] : 'design';

        if ($order_type === 'struct') {
            // 1. サブコントラクターがアップロードした PDF を取得
            $stmtGetPdf = $pdo->prepare("
                SELECT * FROM project_files 
                WHERE project_id = :pid AND file_category = 'sub_structural_pdf' AND is_latest = 1
            ");
            $stmtGetPdf->execute(['pid' => $project_id]);
            $pdf_file = $stmtGetPdf->fetch();

            if ($pdf_file) {
                // 既存の依頼主向け structural_dwg を非最新にする
                $stmtUpdate = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = 'structural_dwg'");
                $stmtUpdate->execute(['pid' => $project_id]);

                // 新しい structural_dwg としてバージョンを上げて挿入
                $stmtVer = $pdo->prepare("SELECT MAX(version) as max_v FROM project_files WHERE project_id = :pid AND file_category = 'structural_dwg'");
                $stmtVer->execute(['pid' => $project_id]);
                $new_v = ($stmtVer->fetch()['max_v'] ?? 0) + 1;

                $stmtInsert = $pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest)
                    VALUES (:pid, 'structural_dwg', :fname, :fpath, :ver, 1)
                ");
                $stmtInsert->execute([
                    'pid' => $project_id,
                    'fname' => $pdf_file['file_name'],
                    'fpath' => $pdf_file['drive_file_id'],
                    'ver' => $new_v
                ]);
                
                // スケジュール自動入力処理
                $today = date('Y-m-d');
                $stmtAct = $pdo->prepare("SELECT schedule_actuals FROM projects WHERE id = :id");
                $stmtAct->execute(['id' => $project_id]);
                $current_actuals_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
                if ($current_actuals_row) {
                    $actuals = json_decode($current_actuals_row['schedule_actuals'] ?? '{}', true) ?: [];
                    if (empty($actuals[4])) { // 4: 構造図UP
                        $actuals[4] = $today;
                        $stmtUpdateSchedule = $pdo->prepare("UPDATE projects SET schedule_actuals = :act WHERE id = :pid");
                        $stmtUpdateSchedule->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
                    }
                }
            }
        }

        // 2. 発注ステータスを completed に更新し、納品完了日を記録
        $completed_at = $_POST['completed_at'] ?? '';
        if (empty($completed_at) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_at)) {
            $completed_at = date('Y-m-d H:i:s');
        } else {
            $completed_at = $completed_at . ' ' . date('H:i:s');
        }
        $stmtOrder = $pdo->prepare("UPDATE subcontractor_orders SET status = 'completed', completed_at = :completed_at WHERE id = :id");
        $stmtOrder->execute(['completed_at' => $completed_at, 'id' => $order_id]);
        // 3. 案件ステータスを「提出済・確認中 (submission)」に更新
        $projectRepo->updateStatus($project_id, 'submission');

        // 4. チャット通知メッセージの送信
        $stmtTask = $pdo->prepare("SELECT task_title FROM subcontractor_orders WHERE id = :id");
        $stmtTask->execute(['id' => $order_id]);
        $task_title = $stmtTask->fetchColumn() ?: '作図業務';

        $notify_sub_msg = "【自動通知】納品タスク「{$task_title}」の承認（納品完了）処理が行われました。";
        $stmtChatSub = $pdo->prepare("
            INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
            VALUES (:pid, :sid, 'sub_admin', :msg)
        ");
        $stmtChatSub->execute([
            'pid' => $project_id,
            'sid' => $_SESSION['user_id'],
            'msg' => $notify_sub_msg
        ]);

        if ($order_type === 'struct') {
            $notify_client_msg = "【自動通知】成果物（構造図）の納品が完了いたしました。成果物パネルよりご確認ください。";
            $stmtChatClient = $pdo->prepare("
                INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                VALUES (:pid, :sid, 'client_admin', :msg)
            ");
            $stmtChatClient->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $notify_client_msg
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("承認処理に失敗しました: " . $e->getMessage());
    }
    header("Location: project_subcontractor.php?id=" . $project_id . "&t=" . time()); exit;
}

// チェックバック（修正指示）の保存・更新、および修正依頼ステータスへの変更
if ($action === 'submit_checkback') {
    if ($is_admin) {
        $order_id = intval($_POST['order_id']);
        $checkback_text = trim($_POST['checkback_text'] ?? '');

        // ファイルアップロード処理
        $drive_file_id = null;
        $file_uploaded = false;
        if (isset($_FILES['checkback_file']) && $_FILES['checkback_file']['error'] === UPLOAD_ERR_OK) {
            require_once __DIR__ . '/../google_drive_client.php';
            $file_tmp = $_FILES['checkback_file']['tmp_name'];
            $file_name = $_FILES['checkback_file']['name'];
            $mime_type = $_FILES['checkback_file']['type'];
            
            // Google Drive にアップロード
            $drive_file_id = upload_to_google_drive($file_tmp, $file_name, $mime_type, $project_id, $pdo);
            $file_uploaded = true;
        }

        $pdo->beginTransaction();
        try {
            // ステータスを 'cb_requested'（修正依頼）に更新し、チェックバックを保存
            if ($file_uploaded) {
                $stmt = $pdo->prepare("
                    UPDATE subcontractor_orders 
                    SET status = 'cb_requested', 
                        checkback_text = :text, 
                        checkback_file_path = :file_path,
                        checkback_updated_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'text' => $checkback_text,
                    'file_path' => $drive_file_id,
                    'id' => $order_id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE subcontractor_orders 
                    SET status = 'cb_requested', 
                        checkback_text = :text, 
                        checkback_updated_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'text' => $checkback_text,
                    'id' => $order_id
                ]);
            }

            // チャットへ修正依頼（チェックバック）を自動投稿 (sub_admin)
            $msg = "【修正依頼（チェックバック修正指示）】\n" . $checkback_text;
            if ($file_uploaded) {
                $msg .= "\n\n指示ファイルがアップロードされました。";
            }
            $msg .= "\n\n指示内容をご確認の上、修正データの再アップロードをお願いいたします。";

            if ($file_uploaded) {
                $stmtMsg = $pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text, file_path, file_type) 
                    VALUES (:pid, :sid, 'sub_admin', :msg, :fpath, 'file')
                ");
                $stmtMsg->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => $msg,
                    'fpath' => $drive_file_id
                ]);
            } else {
                $stmtMsg = $pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => $msg
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("チェックバックの保存に失敗しました: " . $e->getMessage());
        }
        header("Location: project_subcontractor.php?id=" . $project_id . "&t=" . time()); exit;
    }
}

