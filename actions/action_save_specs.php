<?php
// actions/action_save_specs.php

// 仕様保存・一括ファイルアップロード処理
if ($action === 'save_client_specs_draft' || $action === 'request_design_start' || $action === 'replace_documents') {
    $pdo->beginTransaction();
    try {
        // Update upload mode
        $upload_mode = $_POST['upload_mode'] ?? 'individual';
        $projectRepo->updateUploadMode($project_id, $upload_mode);

        // Save JSON specs
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
