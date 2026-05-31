<?php
// ==========================================
// POST処理（発注依頼の登録など）
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
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

    // 成果物アップロード処理 (管理者専用)
    if ($action === 'upload_artifact' && $is_admin) {
        $file_category = trim($_POST['file_category'] ?? '');
        if (!empty($file_category) && isset($_FILES['artifact_file']) && $_FILES['artifact_file']['error'] === UPLOAD_ERR_OK) {
            require_once 'google_drive_client.php';
            try {
                $file_name = $_FILES['artifact_file']['name'];
                $tmp_name  = $_FILES['artifact_file']['tmp_name'];
                $mime_type = $_FILES['artifact_file']['type'];
                
                $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);
                
                $pdo->beginTransaction();
                // 既存の同カテゴリファイルを履歴に落とす
                $stmtOld = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
                $stmtOld->execute(['pid' => $project_id, 'cat' => $file_category]);
                
                // バージョン番号の決定
                $stmtVer = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
                $stmtVer->execute(['pid' => $project_id, 'cat' => $file_category]);
                $next_ver = intval($stmtVer->fetchColumn()) + 1;
                
                // 新規ファイルを登録
                $stmtNew = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :fname, :fid, :ver, 1)");
                $stmtNew->execute([
                    'pid' => $project_id,
                    'cat' => $file_category,
                    'fname' => $file_name,
                    'fid' => $drive_file_id,
                    'ver' => $next_ver
                ]);
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                die("アップロードに失敗しました: " . $e->getMessage());
            }
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }

    // チャットメッセージの送信
    if ($action === 'send_message') {
        $message_text = trim($_POST['message_text'] ?? '');
        $target_file = trim($_POST['target_file'] ?? '');
        
        if ($message_text !== '') {
            $thread_type = 'client_admin'; // 対依頼主チャット
            
            // 対象ファイルが選択されている場合は先頭にタグを付ける
            if ($target_file !== '') {
                $message_text = "【" . $target_file . " について】\n" . $message_text;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                VALUES (:pid, :sid, :thread, :msg)
            ");
            $stmt->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'thread' => $thread_type,
                'msg' => $message_text
            ]);

            // 管理者が送信した場合、依頼主のEmail通知先があればメールを飛ばす
            if ($is_admin) {
                $stmtNotify = $pdo->prepare("SELECT message_text FROM messages WHERE project_id = :pid AND message_text LIKE '%【見積完了時の通知先（SMS/Email）】%' ORDER BY id ASC LIMIT 1");
                $stmtNotify->execute(['pid' => $project_id]);
                $notifyMsg = $stmtNotify->fetchColumn();
                $to_email = '';
                if ($notifyMsg) {
                    preg_match('/【見積完了時の通知先（SMS\/Email）】\n([^\n]+)/', $notifyMsg, $matches);
                    if (!empty($matches[1]) && filter_var(trim($matches[1]), FILTER_VALIDATE_EMAIL)) {
                        $to_email = trim($matches[1]);
                    }
                }
                if ($to_email) {
                    $project_name = $project_info['project_name'];
                    $subject = "【設計サポート】案件「{$project_name}」に新着メッセージがあります";
                    $body = "案件「{$project_name}」にて、管理者から新着メッセージが届きました。\n\n";
                    $body .= "以下のURLよりダッシュボードにログインしてご確認ください。\n";
                    $body .= "https://thanks.work/system/project_detail.php?id={$project_id}\n\n";
                    $body .= "※本メールは送信専用です。";
                    sendSystemEmail($to_email, $subject, $body);
                }
            }
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }

    // 基本情報の更新処理 (update_client_info)
    if ($action === 'update_client_info') {
        $project_name = trim($_POST['project_name'] ?? '');
        $billing_company_name = trim($_POST['billing_company_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        
        $pdo->beginTransaction();
        try {
            if ($project_name !== '') {
                $stmt = $pdo->prepare("UPDATE projects SET project_name = :name, billing_company_name = :billing, billing_phone_number = :b_phone WHERE id = :pid");
                $stmt->execute(['name' => $project_name, 'billing' => $billing_company_name, 'b_phone' => $phone_number, 'pid' => $project_id]);
            }
            if ($phone_number !== '') {
                $stmtPhone = $pdo->prepare("UPDATE users SET phone_number = :phone WHERE id = :uid");
                $stmtPhone->execute(['phone' => $phone_number, 'uid' => $_SESSION['user_id']]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("基本情報の更新に失敗しました: " . $e->getMessage());
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }

    // 仕様保存・一括ファイルアップロード処理
    if ($action === 'save_client_specs_draft' || $action === 'request_design_start' || $action === 'replace_documents') {
        $pdo->beginTransaction();
        try {
            // Update upload mode
            $upload_mode = $_POST['upload_mode'] ?? 'individual';
            $projectRepo->updateUploadMode($project_id, $upload_mode);

            // Save JSON specs
            // Save JSON specs from the new form
            $wood_details = [
                'dodai'    => $_POST['spec_dodai'] ?? '',
                'obiki'    => $_POST['spec_obiki'] ?? '',
                'hashira'  => $_POST['spec_hashira'] ?? '',
                'hari'     => $_POST['spec_hari'] ?? '',
                'koya'     => $_POST['spec_koya'] ?? ''
            ];
            $wall_details = [
                'type' => $_POST['spec_wall'] ?? ''
            ];
            $hardware_details = [
                'type' => $_POST['spec_kanamono'] ?? ''
            ];

            $stmtSpecs = $pdo->prepare("
                UPDATE project_specs 
                SET wood_details = :wood, wall_details = :wall, hardware_details = :hw, client_notes_extra = :notes, soil_status = :soil
                WHERE project_id = :pid
            ");
            $stmtSpecs->execute([
                'wood' => json_encode($wood_details, JSON_UNESCAPED_UNICODE),
                'wall' => json_encode($wall_details, JSON_UNESCAPED_UNICODE),
                'hw' => json_encode($hardware_details, JSON_UNESCAPED_UNICODE),
                'notes' => trim($_POST['client_notes_extra'] ?? ''),
                'soil' => $_POST['soil_status'] ?? null,
                'pid' => $project_id
            ]);

            // Process multi file uploads
            require_once 'google_drive_client.php';
            
            // 既存アップロード済の同カテゴリを最新(is_latest=1)から外すためのユーティリティ
            $disableOldFiles = function($cat) use ($pdo, $project_id) {
                $stmt = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
                $stmt->execute(['pid' => $project_id, 'cat' => $cat]);
            };

            // 個別ファイルアップロード (配列対応)
            if (!empty($_FILES['upload_files']['name'])) {
                foreach ($_FILES['upload_files']['name'] as $cat => $file_names) {
                    if (is_array($file_names)) {
                        // 複数ファイル (配列)
                        // アップロードがある場合のみ既存ファイルを非アクティブにする
                        $has_upload = false;
                        foreach ($file_names as $idx => $f_name) {
                            if ($_FILES['upload_files']['error'][$cat][$idx] === UPLOAD_ERR_OK && $f_name !== '') {
                                $has_upload = true;
                                break;
                            }
                        }
                        if ($has_upload) {
                            $disableOldFiles($cat);
                        }

                        foreach ($file_names as $idx => $file_name) {
                            if ($_FILES['upload_files']['error'][$cat][$idx] === UPLOAD_ERR_OK && $file_name !== '') {
                                $tmp_name = $_FILES['upload_files']['tmp_name'][$cat][$idx];
                                $mime_type = $_FILES['upload_files']['type'][$cat][$idx];
                                try {
                                    $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);
                                    
                                    // Get version
                                    $stmtVersion = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
                                    $stmtVersion->execute(['pid' => $project_id, 'cat' => $cat]);
                                    $max_version = (int)$stmtVersion->fetchColumn();
                                    
                                    $stmtInsert = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) VALUES (:pid, :cat, :name, :drive_id, :ver, 1, :reason)");
                                    $stmtInsert->execute(['pid' => $project_id, 'cat' => $cat, 'name' => $file_name, 'drive_id' => $drive_file_id, 'ver' => $max_version + 1, 'reason' => $_POST['update_reason'][$cat] ?? null]);
                                } catch (Exception $e) {
                                    error_log("Multi upload error (Array): " . $e->getMessage());
                                }
                            }
                        }
                    } else {
                        // 単一ファイル
                        $file_name = $file_names;
                        if ($_FILES['upload_files']['error'][$cat] === UPLOAD_ERR_OK && $file_name !== '') {
                            $tmp_name = $_FILES['upload_files']['tmp_name'][$cat];
                            $mime_type = $_FILES['upload_files']['type'][$cat];
                            try {
                                $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);
                                $disableOldFiles($cat);
                                
                                $stmtVersion = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
                                $stmtVersion->execute(['pid' => $project_id, 'cat' => $cat]);
                                $max_version = (int)$stmtVersion->fetchColumn();
                                
                                $stmtInsert = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) VALUES (:pid, :cat, :name, :drive_id, :ver, 1, :reason)");
                                $stmtInsert->execute(['pid' => $project_id, 'cat' => $cat, 'name' => $file_name, 'drive_id' => $drive_file_id, 'ver' => $max_version + 1, 'reason' => $_POST['update_reason'][$cat] ?? null]);
                            } catch (Exception $e) {
                                error_log("Multi upload error (Single): " . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Handle "Included in another file" checkboxes
            if (!empty($_POST['included_in_other'])) {
                foreach ($_POST['included_in_other'] as $cat => $val) {
                    if ($val == '1') {
                        $disableOldFiles($cat);
                        $stmtVersion = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
                        $stmtVersion->execute(['pid' => $project_id, 'cat' => $cat]);
                        $max_version = (int)$stmtVersion->fetchColumn();
                        
                        $stmtInsert = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) VALUES (:pid, :cat, :name, NULL, :ver, 1, :reason)");
                        $stmtInsert->execute(['pid' => $project_id, 'cat' => $cat, 'name' => '【他ファイルに記載】', 'ver' => $max_version + 1, 'reason' => $_POST['update_reason'][$cat] ?? null]);
                    }
                }
            }

            // Only execute validation and status change if action is request_design_start
            if ($action === 'replace_documents') {
                $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                $stmtMsg->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => "【図書追加・差し替え通知】\n不足図書の追加、または既存ファイルの差し替えが行われました。ファイル一覧をご確認ください。"
                ]);
            }

            if ($action === 'request_design_start') {
                // Backend validation for drawing change report
                $drawing_changed = $_POST['drawing_changed'] ?? '';
                $drawing_change_notes = trim($_POST['drawing_change_notes'] ?? '');
                
                if (empty($drawing_changed)) {
                    throw new Exception("見積時からの図面変更の有無を選択してください。");
                }
                if ($drawing_changed === 'yes' && empty($drawing_change_notes)) {
                    throw new Exception("図面変更がある場合は、変更箇所を入力してください。");
                }

                // Save drawing change report to messages
                $change_msg = "【図面変更の有無報告】\n";
                $change_msg .= ($drawing_changed === 'yes') ? "見積時から変更あり\n詳細: " . $drawing_change_notes : "見積時から変更なし";
                
                $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                $stmtMsg->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => $change_msg
                ]);

                // Update status to doc_submitted and notify admin that design request is completed
                $projectRepo->updateStatus($project_id, 'doc_submitted');
                
                // 自動見積りの最新額を初期お見積額に設定
                $stmtEst = $pdo->prepare("SELECT total_price FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
                $stmtEst->execute(['pid' => $project_id]);
                $latest_est = $stmtEst->fetchColumn();
                if ($latest_est) {
                    $stmtInit = $pdo->prepare("UPDATE projects SET initial_est_amount = :amt, initial_est_date = :dt WHERE id = :pid AND (initial_est_amount IS NULL OR initial_est_amount = 0)");
                    $stmtInit->execute(['amt' => $latest_est, 'dt' => date('Y-m-d'), 'pid' => $project_id]);
                }
                
                $stmtNotify = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                $stmtNotify->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => "【通知】依頼種別に応じた必要図書の提出がすべて完了し、設計開始が依頼されました。図書の内容を確認の上、一次回答期日の設定をお願いします。"
                ]);

                // 管理者へメール通知
                $project_name = $project_info['project_name'] ?? '案件名未定';
                $subject = "【設計依頼】案件「{$project_name}」の設計開始が依頼されました";
                $body = "案件「{$project_name}」にて、依頼主から構造仕様の指定と必要図書の提出が完了し、設計開始が依頼されました。\n\n";
                $body .= "以下のURLよりダッシュボードにログインし、図書を確認して一次回答期日を設定してください。\n";
                $body .= "https://thanks.work/system/project_detail.php?id={$project_id}\n";
                sendSystemEmail('info@thanks.work', $subject, $body);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("処理に失敗しました: " . $e->getMessage());
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }


    
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

    
    // ファイルアップロード処理（管理者・依頼主）
    if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $file_category = $_POST['file_category'] ?? '';
        if ($file_category !== '') {
            $file_name = $_FILES['upload_file']['name'];
            $tmp_name = $_FILES['upload_file']['tmp_name'];
            $mime_type = $_FILES['upload_file']['type'];

            try {
                // Google Drive へのアップロード
                require_once 'google_drive_client.php';
                $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);

                $pdo->beginTransaction();
                // 1. 既存の同カテゴリのファイルを最新フラグから外す
                $stmtDisable = $pdo->prepare("
                    UPDATE project_files 
                    SET is_latest = 0 
                    WHERE project_id = :pid AND file_category = :cat
                ");
                $stmtDisable->execute([
                    'pid' => $project_id,
                    'cat' => $file_category
                ]);

                // 2. 現在の最大バージョンを取得
                $stmtVersion = $pdo->prepare("
                    SELECT MAX(version) 
                    FROM project_files 
                    WHERE project_id = :pid AND file_category = :cat
                ");
                $stmtVersion->execute([
                    'pid' => $project_id,
                    'cat' => $file_category
                ]);
                $max_version = (int)$stmtVersion->fetchColumn();
                $new_version = $max_version + 1;

                $update_reason = $_POST['update_reason'] ?? null;

                // 3. 新しいレコードを挿入
                $stmtInsert = $pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) 
                    VALUES (:pid, :cat, :name, :drive_id, :ver, 1, :reason)
                ");
                $stmtInsert->execute([
                    'pid' => $project_id,
                    'cat' => $file_category,
                    'name' => $file_name,
                    'drive_id' => $drive_file_id,
                    'ver' => $new_version,
                    'reason' => $update_reason
                ]);

                // 差し替え理由があればメッセージに投稿
                if (!empty($update_reason)) {
                    $cat_label = $file_category; // 簡易的にカテゴリーキーを使用。必要ならマップ用意。
                    $msg = "【図書差し替え通知】\n対象: {$cat_label}\n理由: {$update_reason}";
                    $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                    $stmtMsg->execute([
                        'pid' => $project_id,
                        'sid' => $_SESSION['user_id'] ?? 1,
                        'msg' => $msg
                    ]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                die("ファイルのアップロードまたはデータベース登録に失敗しました: " . $e->getMessage());
            }
            header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
        }
    }
}

