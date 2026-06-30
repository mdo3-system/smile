<?php
// actions/action_save_specs.php

// 仕様保存・一括ファイルアップロード処理
if ($action === 'save_client_specs_draft' || $action === 'request_design_start' || $action === 'replace_documents' || $action === 'update_specs_detail') {
    try {
        // Save JSON specs
        $buildSpecString = function($prefix) {
            $type = trim($_POST[$prefix . '_type'] ?? '');
            $size = trim($_POST[$prefix . '_size'] ?? '');
            if ($type === 'その他') {
                return $size;
            }
            if ($type !== '' && $size !== '') {
                return $type . ' ' . $size;
            }
            return $type !== '' ? $type : $size;
        };

        // 垂木の処理 (W×H@間隔)
        $taruki_str = '';
        $taruki_type = trim($_POST['spec_taruki_type'] ?? '');
        $taruki_w = trim($_POST['spec_taruki_w'] ?? '');
        $taruki_h = trim($_POST['spec_taruki_h'] ?? '');
        $taruki_pitch = trim($_POST['spec_taruki_pitch'] ?? '');
        if ($taruki_type === 'その他') {
            $taruki_str = trim($_POST['spec_taruki_size'] ?? '');
        } else {
            $dims = ($taruki_w !== '' && $taruki_h !== '') ? "{$taruki_w}×{$taruki_h}" : '';
            $pitch = ($taruki_pitch !== '') ? "@{$taruki_pitch}" : '';
            if ($taruki_type !== '' && ($dims !== '' || $pitch !== '')) {
                $taruki_str = $taruki_type . ' ' . $dims . $pitch;
            } else {
                $taruki_str = $taruki_type !== '' ? $taruki_type : ($dims . $pitch);
            }
            $taruki_str = trim($taruki_str);
        }

        $wood_details = [
            'dodai'    => $buildSpecString('spec_dodai'),
            'obiki'    => $buildSpecString('spec_obiki'),
            'hashira'  => $buildSpecString('spec_hashira'),
            'hari'     => $buildSpecString('spec_hari'),
            'koya'     => $buildSpecString('spec_koyatsuka'),
            'moya'     => $buildSpecString('spec_moya'),
            'munagi'   => $buildSpecString('spec_munagi'),
            'taruki'   => $taruki_str
        ];
        $wall_details = [
            'type' => $_POST['spec_wall'] ?? ''
        ];
        $hardware_details = [
            'type' => $_POST['spec_kanamono'] ?? ''
        ];

        if ($action === 'update_specs_detail') {
            $pdo->beginTransaction();
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
            $pdo->commit();
            header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
        }

        // Process multi file uploads (トランザクションの前にアップロードを完了させる)
        require_once 'google_drive_client.php';
        $files_to_insert = [];

        // 個別ファイルアップロード (配列対応)
        if (!empty($_FILES['upload_files']['name'])) {
            foreach ($_FILES['upload_files']['name'] as $cat => $file_names) {
                if (is_array($file_names)) {
                    foreach ($file_names as $idx => $file_name) {
                        if ($_FILES['upload_files']['error'][$cat][$idx] === UPLOAD_ERR_OK && $file_name !== '') {
                            $tmp_name = $_FILES['upload_files']['tmp_name'][$cat][$idx];
                            $mime_type = $_FILES['upload_files']['type'][$cat][$idx];
                            try {
                                $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type, $project_id, $pdo);
                                $files_to_insert[] = [
                                    'cat' => $cat,
                                    'name' => $file_name,
                                    'drive_id' => $drive_file_id,
                                    'reason' => $_POST['update_reason'][$cat] ?? null
                                ];
                            } catch (Exception $e) {
                                error_log("Multi upload error (Array): " . $e->getMessage());
                                throw $e;
                            }
                        }
                    }
                } else {
                    $file_name = $file_names;
                    if ($_FILES['upload_files']['error'][$cat] === UPLOAD_ERR_OK && $file_name !== '') {
                        $tmp_name = $_FILES['upload_files']['tmp_name'][$cat];
                        $mime_type = $_FILES['upload_files']['type'][$cat];
                        try {
                            $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type, $project_id, $pdo);
                            $files_to_insert[] = [
                                'cat' => $cat,
                                'name' => $file_name,
                                'drive_id' => $drive_file_id,
                                'reason' => $_POST['update_reason'][$cat] ?? null
                            ];
                        } catch (Exception $e) {
                            error_log("Multi upload error (Single): " . $e->getMessage());
                            throw $e;
                        }
                    }
                }
            }
        }

        // Handle "Included in another file" checkboxes
        $included_to_insert = [];
        if (!empty($_POST['included_in_other'])) {
            foreach ($_POST['included_in_other'] as $cat => $val) {
                if ($val == '1') {
                    $included_to_insert[] = [
                        'cat' => $cat,
                        'name' => '【他ファイルに記載】',
                        'drive_id' => null,
                        'reason' => $_POST['update_reason'][$cat] ?? null
                    ];
                }
            }
        }

        // --- ここからDB書き込みトランザクション開始 ---
        $pdo->beginTransaction();

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

        $disableOldFiles = function($cat) use ($pdo, $project_id) {
            $stmt = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
            $stmt->execute(['pid' => $project_id, 'cat' => $cat]);
        };

        $all_inserts = array_merge($files_to_insert, $included_to_insert);
        $disabled_cats = [];
        foreach ($all_inserts as $ins) {
            $cat = $ins['cat'];
            if (!in_array($cat, $disabled_cats)) {
                $disableOldFiles($cat);
                $disabled_cats[] = $cat;
            }
        }

        foreach ($all_inserts as $ins) {
            $cat = $ins['cat'];
            $file_name = $ins['name'];
            $drive_file_id = $ins['drive_id'];
            $reason = $ins['reason'];

            $stmtVersion = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
            $stmtVersion->execute(['pid' => $project_id, 'cat' => $cat]);
            $max_version = (int)$stmtVersion->fetchColumn();

            $stmtInsert = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) VALUES (:pid, :cat, :name, :drive_id, :ver, 1, :reason)");
            $stmtInsert->execute(['pid' => $project_id, 'cat' => $cat, 'name' => $file_name, 'drive_id' => $drive_file_id, 'ver' => $max_version + 1, 'reason' => $reason]);
        }

        if ($action === 'replace_documents') {
            $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtMsg->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => "【図書追加・差し替え通知】\n不足図書の追加、または既存ファイルの差し替えが行われました。ファイル一覧をご確認ください。"
            ]);
        }

        $project_info = $project;
        $all_docs_ready = false;
        $missing_str = '';
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

            // ============================
            // CADデータ必須チェック（正式依頼時に必ず必要）
            // ============================
            $stmtCadCheck = $pdo->prepare("
                SELECT COUNT(*) FROM project_files 
                WHERE project_id = :pid 
                AND is_latest = 1 
                AND file_category IN ('cad_design_all','cad_layout','cad_plan_1f','cad_plan_2f','cad_plan_3f','cad_plan_ph','cad_plan_rf','cad_elevation','cad_section','all_in_one_zip')
            ");
            $stmtCadCheck->execute(['pid' => $project_id]);
            $cad_count = (int)$stmtCadCheck->fetchColumn();
            if ($cad_count === 0) {
                throw new Exception("正式依頼には意匠CADデータ（JWW/DXF等）のアップロードが必須です。CADデータをアップロードしてから再度お試しください。");
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

            // Update status to primary_prep (必要図書が揃うまでスケジュール起算保留)
            $projectRepo->updateStatus($project_id, 'primary_prep');

            // ============================
            // 必須図書の充足チェック（確認申請書・地盤調査は後出し可）
            // 全て揃っている場合のみ schedule_actuals[0] を今日の日付で記録する
            // ============================
            $today = date('Y-m-d');

            // 後出し必須図書の揃い具合を判定するヘルパー
            $hasFile = function($cat) use ($pdo, $project_id) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat AND is_latest = 1");
                $s->execute(['pid' => $project_id, 'cat' => $cat]);
                return (int)$s->fetchColumn() > 0;
            };

            // 確認申請書は全依頼で必須（後出し可）
            $has_app_doc = $hasFile('app_doc');
            // 地盤調査報告書は許容応力度・基礎梁許容応力度の時のみ必須（後出し可）
            $needs_soil = ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1);
            $has_soil = $needs_soil ? $hasFile('soil_report') : true;

            $all_docs_ready = $has_app_doc && $has_soil;

            if ($all_docs_ready) {
                // 全図書揃い → 即座にスケジュール起算
                $colsToUpdate = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
                $stmtAct->execute(['id' => $project_id]);
                $current_actuals_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
                if ($current_actuals_row) {
                    foreach ($colsToUpdate as $col) {
                        $actuals = json_decode($current_actuals_row[$col] ?? '{}', true) ?: [];
                        if (empty($actuals[0])) {
                            $actuals[0] = $today;
                            $stmtUpdate = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                            $stmtUpdate->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
                        }
                    }
                }
            }

            // 自動見積りの最新額を初期お見積額に設定
            $stmtEst = $pdo->prepare("SELECT total_price FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
            $stmtEst->execute(['pid' => $project_id]);
            $latest_est = $stmtEst->fetchColumn();
            if ($latest_est) {
                $stmtInit = $pdo->prepare("UPDATE projects SET initial_est_amount = :amt, initial_est_date = :dt WHERE id = :pid AND (initial_est_amount IS NULL OR initial_est_amount = 0)");
                $stmtInit->execute(['amt' => $latest_est, 'dt' => date('Y-m-d'), 'pid' => $project_id]);
            }

            // チャット通知メッセージ（図書の揃い状況に応じて変える）
            if ($all_docs_ready) {
                $notify_msg = "【通知】正式依頼が受理され、すべての必要図書が揃いました。図書の内容を確認の上、一次回答期日の設定をお願いします。";
            } else {
                $missing = [];
                if (!$has_app_doc) $missing[] = '確認申請書（2〜5面）';
                if ($needs_soil && !$has_soil) $missing[] = '地盤調査報告書';
                $missing_str = implode('、', $missing);
                $notify_msg = "【通知】正式依頼が受理されました（CADデータ確認済）。\n以下の図書がまだ未提出です。提出が完了した時点を一次回答の起算日とします。\n\n未提出図書: {$missing_str}";
            }
            
            $stmtNotify = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
            $stmtNotify->execute([
                'pid' => $project_id,
                'sid' => $_SESSION['user_id'],
                'msg' => $notify_msg
            ]);
        }

        $pdo->commit();

        // --- コミット完了後にメール通知を行う (Race Condition 防止) ---
        if ($action === 'request_design_start') {
            try {
                $project_name = $project_info['project_name'] ?? '案件名未定';
                $subject = "【設計依頼】案件「{$project_name}」の設計開始が依頼されました";
                if ($all_docs_ready) {
                    $body = "案件「{$project_name}」にて、正式依頼が受理され、すべての必要図書が提出されました。\n\n";
                    $body .= "以下のURLよりダッシュボードにログインし、図書を確認して一次回答期日を設定してください。\n";
                } else {
                    $body = "案件「{$project_name}」にて、正式依頼が受理されました（CADデータ確認済）。\n未提出図書があります：{$missing_str}\n\n";
                    $body .= "未提出図書がすべて揃った時点が一次回答の起算日となります。\n";
                }
                $body .= "https://system.thanks.work/project_detail.php?id={$project_id}\n";
                sendSystemEmail('info@thanks.work', $subject, $body);
            } catch (Exception $mailEx) {
                error_log("Email send failed after commit: " . $mailEx->getMessage());
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("処理に失敗しました: " . $e->getMessage());
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

if ($action === 'update_client_info') {
    $project_name = trim($_POST['project_name'] ?? '');
    $billing_company_name = trim($_POST['billing_company_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    
    $company_name = trim($_POST['company_name'] ?? '');
    $company_kana = trim($_POST['company_kana'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_kana = trim($_POST['contact_kana'] ?? '');
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    
    $pdo->beginTransaction();
    try {
        // 案件に紐づく依頼主のユーザーIDを取得（管理者からの更新時にも正しく対象の依頼主を更新するため）
        $stmtGetClient = $pdo->prepare("SELECT client_id FROM projects WHERE id = :pid");
        $stmtGetClient->execute(['pid' => $project_id]);
        $target_uid = $stmtGetClient->fetchColumn();
        
        if (!$target_uid) {
            $target_uid = $_SESSION['user_id']; // フォールバック
        }

        if ($project_name !== '') {
            $stmt = $pdo->prepare("UPDATE projects SET project_name = :name, billing_company_name = :billing WHERE id = :pid");
            $stmt->execute(['name' => $project_name, 'billing' => $billing_company_name, 'pid' => $project_id]);
        }
        
        $stmtUser = $pdo->prepare("
            UPDATE users SET 
                company_name = :company_name,
                company_kana = :company_kana,
                zip_code = :zip_code,
                address = :address,
                phone_number = :phone_number,
                contact_name = :contact_name,
                contact_kana = :contact_kana,
                mobile_number = :mobile_number,
                billing_company_name = :billing_company_name,
                email_notifications = :email_notifications
            WHERE id = :uid
        ");
        $stmtUser->execute([
            'company_name' => $company_name,
            'company_kana' => $company_kana,
            'zip_code' => $zip_code,
            'address' => $address,
            'phone_number' => $phone_number,
            'contact_name' => $contact_name,
            'contact_kana' => $contact_kana,
            'mobile_number' => $mobile_number,
            'billing_company_name' => $billing_company_name,
            'email_notifications' => $email_notifications,
            'uid' => $target_uid
        ]);
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die("基本情報の更新に失敗しました: " . $e->getMessage());
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}
