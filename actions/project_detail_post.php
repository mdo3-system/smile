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
            $stmt = $pdo->prepare("INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status) VALUES (:pid, :sub_id, :task, :amount, 'requested')");
            $stmt->execute([
                'pid' => $project_id,
                'sub_id' => $_POST['subcontractor_id'],
                'task' => $_POST['task_title'],
                'amount' => $_POST['order_amount']
            ]);

            // 案件のステータスを「構造図作成中 (structural_dwg)」へ自動更新
            $projectRepo->updateStatus($project_id, 'structural_dwg');

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
            // 1. 発注ステータスを completed に更新
            $stmt = $pdo->prepare("UPDATE subcontractor_orders SET status = 'completed' WHERE id = :id");
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

    // 仕様保存・一括ファイルアップロード処理
    if ($action === 'save_client_specs_draft' || $action === 'request_design_start') {
        $pdo->beginTransaction();
        try {
            // Update upload mode
            $upload_mode = $_POST['upload_mode'] ?? 'individual';
            $projectRepo->updateUploadMode($project_id, $upload_mode);

            // Save JSON specs
            $wood_details = [
                'foundation' => ['type' => $_POST['wood_dodai_type'] ?? '', 'size' => $_POST['wood_dodai_size'] ?? '', 'other' => $_POST['wood_dodai_other'] ?? ''],
                'column'     => ['type' => $_POST['wood_hashira_type'] ?? '', 'size' => $_POST['wood_hashira_size'] ?? '', 'other' => $_POST['wood_hashira_other'] ?? ''],
                'beam'       => ['type' => $_POST['wood_hari_type'] ?? '', 'size' => $_POST['wood_hari_size'] ?? '', 'other' => $_POST['wood_hari_other'] ?? ''],
                'obiki'      => ['type' => $_POST['wood_obiki_type'] ?? '', 'size' => $_POST['wood_obiki_size'] ?? '', 'other' => $_POST['wood_obiki_other'] ?? ''],
                'koyatsuka'  => ['type' => $_POST['wood_koyatsuka_type'] ?? '', 'size' => $_POST['wood_koyatsuka_size'] ?? '', 'other' => $_POST['wood_koyatsuka_other'] ?? ''],
                'moya'       => ['type' => $_POST['wood_moya_type'] ?? '', 'size' => $_POST['wood_moya_size'] ?? '', 'other' => $_POST['wood_moya_other'] ?? ''],
                'munagi'     => ['type' => $_POST['wood_munagi_type'] ?? '', 'size' => $_POST['wood_munagi_size'] ?? '', 'other' => $_POST['wood_munagi_other'] ?? ''],
                'taruki'     => ['type' => $_POST['wood_taruki_type'] ?? '', 'w' => $_POST['wood_taruki_w'] ?? '', 'h' => $_POST['wood_taruki_h'] ?? '', 'other' => $_POST['wood_taruki_other'] ?? ''],
                'hiuchi'     => ['type' => $_POST['wood_hiuchi_type'] ?? '', 'size' => $_POST['wood_hiuchi_size'] ?? '', 'other' => $_POST['wood_hiuchi_other'] ?? '']
            ];
            $wall_details = [
                'menzai' => ['type' => $_POST['wall_menzai_type'] ?? '', 'other' => $_POST['wall_menzai_other'] ?? ''],
                'sujikai' => ['type' => $_POST['wall_sujikai_type'] ?? '', 'other' => $_POST['wall_sujikai_other'] ?? '']
            ];
            $hardware_details = [
                'type' => $_POST['hw_type'] ?? '', 'type_other' => $_POST['hw_type_other'] ?? '',
                'method' => $_POST['hw_method'] ?? '', 'method_other' => $_POST['hw_method_other'] ?? ''
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
                                    
                                    $stmtInsert = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :name, :drive_id, :ver, 1)");
                                    $stmtInsert->execute(['pid' => $project_id, 'cat' => $cat, 'name' => $file_name, 'drive_id' => $drive_file_id, 'ver' => $max_version + 1]);
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
                                
                                $stmtInsert = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :name, :drive_id, :ver, 1)");
                                $stmtInsert->execute(['pid' => $project_id, 'cat' => $cat, 'name' => $file_name, 'drive_id' => $drive_file_id, 'ver' => $max_version + 1]);
                            } catch (Exception $e) {
                                error_log("Multi upload error (Single): " . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // Only execute validation and status change if action is request_design_start
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

                // Update status to primary_prep and notify admin that design request is completed
                $projectRepo->updateStatus($project_id, 'primary_prep');
                
                $stmtNotify = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                $stmtNotify->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => "【通知】構造仕様の指定と必要図書の提出が完了し、設計開始が依頼されました。一次回答期日の設定をお願いします。"
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

                // 3. 新しいレコードを挿入
                $stmtInsert = $pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :cat, :name, :drive_id, :ver, 1)
                ");
                $stmtInsert->execute([
                    'pid' => $project_id,
                    'cat' => $file_category,
                    'name' => $file_name,
                    'drive_id' => $drive_file_id,
                    'ver' => $new_version
                ]);

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

