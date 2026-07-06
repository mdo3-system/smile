<?php
// actions/action_issue_invoice_helper.php

function issuePrimaryInvoiceHelper($pdo, $project_id, $user_id, $invoice_rate = 0.5) {
    require_once __DIR__ . '/../google_drive_client.php';
    require_once __DIR__ . '/../estimate_pdf_generator.php';

    // 1. まず案件情報を取得して本見積額が確定しているか確認
    $stmtProj = $pdo->prepare("SELECT project_name, formal_est_amount FROM projects WHERE id = :pid");
    $stmtProj->execute(['pid' => $project_id]);
    $proj_info = $stmtProj->fetch(PDO::FETCH_ASSOC);

    if (!$proj_info) {
        throw new Exception("対象の案件が見つかりません。");
    }

    if (empty($proj_info['formal_est_amount']) || intval($proj_info['formal_est_amount']) <= 0) {
        throw new Exception("本見積額が確定していないため、請求書を発行できません。");
    }

    // 2. PDFの生成
    $temp_pdf_path = generate_primary_invoice_pdf($project_id, $pdo, $invoice_rate);
    
    $proj_name = $proj_info['project_name'];
    $is_full = ($invoice_rate >= 1.0);
    $pdf_filename = ($is_full ? '全額請求書_' : '一次請求書_') . $proj_name . '.pdf';
    
    $pdfDriveId = null;
    try {
        $project_folder_id = get_or_create_project_drive_folder($pdo, $project_id);
        // 3. Google Driveにアップロード
        $pdfDriveId = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);
        
        // 一時生成したローカルのPDFを削除
        if (file_exists($temp_pdf_path)) {
            unlink($temp_pdf_path);
        }
    } catch (Exception $driveEx) {
        error_log("Google Driveへの一次請求書保存に失敗しました。ローカル保存にフォールバックします: " . $driveEx->getMessage());
        
        $c_folder = '';
        $p_folder = '';
        $sub_dir = '';
        
        try {
            // 案件情報と依頼主情報を取得
            $stmt = $pdo->prepare("
                SELECT p.project_name, u.id as client_id, u.company_name, u.contact_name
                FROM projects p
                JOIN users u ON p.client_id = u.id
                WHERE p.id = :pid
            ");
            $stmt->execute(['pid' => $project_id]);
            $data = $stmt->fetch();
            
            if ($data) {
                $client_folder_name = !empty($data['company_name']) ? trim($data['company_name']) : trim($data['contact_name']);
                if (empty($client_folder_name)) {
                    $client_folder_name = "依頼主_ID_" . $data['client_id'];
                }
                $project_folder_name = !empty($data['project_name']) ? trim($data['project_name']) : "案件_ID_" . $project_id;
                
                $c_folder = sanitize_local_folder_name($client_folder_name);
                $p_folder = sanitize_local_folder_name($project_folder_name);
                
                if ($c_folder !== '' && $p_folder !== '') {
                    $sub_dir = '/' . $c_folder . '/' . $p_folder;
                }
            }
        } catch (Exception $db_ex) {
            error_log("Failed to fetch project info for primary invoice local fallback path: " . $db_ex->getMessage());
        }

        $upload_dir = __DIR__ . '/../uploads' . $sub_dir;
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $unique_name = time() . '_' . uniqid() . '_' . $pdf_filename;
        $dest_path = $upload_dir . '/' . $unique_name;
        
        $relative_path = 'uploads' . $sub_dir . '/' . $unique_name;
        
        if (copy($temp_pdf_path, $dest_path)) {
            $pdfDriveId = $relative_path;
        } else {
            throw new Exception("Google Drive保存に失敗し、さらにローカルフォールバック保存にも失敗しました: " . $driveEx->getMessage());
        }
        
        if (file_exists($temp_pdf_path)) {
            unlink($temp_pdf_path);
        }
    }

    $is_local_transaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $is_local_transaction = true;
    }

    try {
        // 4. project_files テーブルに登録
        $file_category = 'inv_primary';
        
        // 既存の同カテゴリファイルを履歴に落とす
        $stmtOld = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
        $stmtOld->execute(['pid' => $project_id, 'cat' => $file_category]);
        
        // バージョン番号の決定
        $stmtVer = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtVer->execute(['pid' => $project_id, 'cat' => $file_category]);
        $next_ver = intval($stmtVer->fetchColumn()) + 1;
        
        $stmtNew = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :fname, :fid, :ver, 1)");
        $stmtNew->execute([
            'pid' => $project_id,
            'cat' => $file_category,
            'fname' => $pdf_filename,
            'fid' => $pdfDriveId,
            'ver' => $next_ver
        ]);

        // 5. 自動的にチャット通知メッセージを生成し、チャットに送信
        $formal_est_amount = intval($proj_info['formal_est_amount']);
        $base_formal = round($formal_est_amount / 1.1); // 本見積の税抜額
        $subtotal = round($base_formal * $invoice_rate); // 税抜金額の指定比率
        $tax = round($subtotal * 0.1); // 消費税10%
        $grand_total = $subtotal + $tax; // 税込合計

        $is_full = ($invoice_rate >= 1.0);
        if ($is_full) {
            $msg = "【ご請求書(100%全額)が発行されました】\n";
            $msg .= "本見積額の100%全額を請求させていただきます。\n";
            $msg .= "請求金額: " . number_format($grand_total) . "円 (税込)\n";
            $msg .= "（内訳: 税抜 " . number_format($subtotal) . "円、消費税 " . number_format($tax) . "円）\n\n";
            $msg .= "詳細は左パネルの「一次請求書（全額）」からご確認ください。ご入金の確認後、詳細モデル作成業務に着手いたします。";
        } else {
            $msg = "【一次請求書(50%)が発行されました】\n";
            $msg .= "着手金として、本見積額の消費税加算前50%と消費税分を請求させていただきます。\n";
            $msg .= "請求金額: " . number_format($grand_total) . "円 (税込)\n";
            $msg .= "（内訳: 税抜 " . number_format($subtotal) . "円、消費税 " . number_format($tax) . "円）\n\n";
            $msg .= "詳細は左パネルの「一次請求書」からご確認ください。ご入金の確認後、詳細モデル作成業務に着手いたします。";
        }

        $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
        $stmtMsg->execute([
            'pid' => $project_id,
            'sid' => $user_id,
            'msg' => $msg
        ]);

        if ($is_local_transaction) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($is_local_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $pdfDriveId;
}

function issueFinalInvoiceHelper($pdo, $project_id, $user_id) {
    require_once __DIR__ . '/../google_drive_client.php';
    require_once __DIR__ . '/../estimate_pdf_generator.php';

    // 1. まず案件情報を取得して本見積額が確定しているか確認
    $stmtProj = $pdo->prepare("SELECT project_name, formal_est_amount, add_est_amount, deposit_amount_50 FROM projects WHERE id = :pid");
    $stmtProj->execute(['pid' => $project_id]);
    $proj_info = $stmtProj->fetch(PDO::FETCH_ASSOC);

    if (!$proj_info) {
        throw new Exception("対象の案件が見つかりません。");
    }

    if (empty($proj_info['formal_est_amount']) || intval($proj_info['formal_est_amount']) <= 0) {
        throw new Exception("本見積額が確定していないため、請求書を発行できません。");
    }

    // 2. PDFの生成
    $temp_pdf_path = generate_final_invoice_pdf($project_id, $pdo);
    
    $proj_name = $proj_info['project_name'];
    $pdf_filename = '最終請求書_' . $proj_name . '.pdf';
    
    $pdfDriveId = null;
    try {
        $project_folder_id = get_or_create_project_drive_folder($pdo, $project_id);
        // 3. Google Driveにアップロード
        $pdfDriveId = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);
        
        // 一時生成したローカルのPDFを削除
        if (file_exists($temp_pdf_path)) {
            unlink($temp_pdf_path);
        }
    } catch (Exception $driveEx) {
        error_log("Google Driveへの最終請求書保存に失敗しました。ローカル保存にフォールバックします: " . $driveEx->getMessage());
        
        $c_folder = '';
        $p_folder = '';
        $sub_dir = '';
        
        try {
            // 案件情報と依頼主情報を取得
            $stmt = $pdo->prepare("
                SELECT p.project_name, u.id as client_id, u.company_name, u.contact_name
                FROM projects p
                JOIN users u ON p.client_id = u.id
                WHERE p.id = :pid
            ");
            $stmt->execute(['pid' => $project_id]);
            $data = $stmt->fetch();
            
            if ($data) {
                $client_folder_name = !empty($data['company_name']) ? trim($data['company_name']) : trim($data['contact_name']);
                if (empty($client_folder_name)) {
                    $client_folder_name = "依頼主_ID_" . $data['client_id'];
                }
                $project_folder_name = !empty($data['project_name']) ? trim($data['project_name']) : "案件_ID_" . $project_id;
                
                $c_folder = sanitize_local_folder_name($client_folder_name);
                $p_folder = sanitize_local_folder_name($project_folder_name);
                
                if ($c_folder !== '' && $p_folder !== '') {
                    $sub_dir = '/' . $c_folder . '/' . $p_folder;
                }
            }
        } catch (Exception $db_ex) {
            error_log("Failed to fetch project info for final invoice local fallback path: " . $db_ex->getMessage());
        }

        $upload_dir = __DIR__ . '/../uploads' . $sub_dir;
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $unique_name = time() . '_' . uniqid() . '_' . $pdf_filename;
        $dest_path = $upload_dir . '/' . $unique_name;
        
        $relative_path = 'uploads' . $sub_dir . '/' . $unique_name;
        
        if (copy($temp_pdf_path, $dest_path)) {
            $pdfDriveId = $relative_path;
        } else {
            throw new Exception("Google Drive保存に失敗し、さらにローカルフォールバック保存にも失敗しました: " . $driveEx->getMessage());
        }
        
        if (file_exists($temp_pdf_path)) {
            unlink($temp_pdf_path);
        }
    }

    $is_local_transaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $is_local_transaction = true;
    }

    try {
        // 4. project_files テーブルに登録
        $file_category = 'inv_final';
        
        // 既存の同カテゴリファイルを履歴に落とす
        $stmtOld = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
        $stmtOld->execute(['pid' => $project_id, 'cat' => $file_category]);
        
        // バージョン番号の決定
        $stmtVer = $pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtVer->execute(['pid' => $project_id, 'cat' => $file_category]);
        $next_ver = intval($stmtVer->fetchColumn()) + 1;
        
        $stmtNew = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :fname, :fid, :ver, 1)");
        $stmtNew->execute([
            'pid' => $project_id,
            'cat' => $file_category,
            'fname' => $pdf_filename,
            'fid' => $pdfDriveId,
            'ver' => $next_ver
        ]);

        // 5. 自動的にチャット通知メッセージを生成し、チャットに送信
        $formal = intval($proj_info['formal_est_amount']);
        $add = intval($proj_info['add_est_amount'] ?? 0);
        $dep_50 = intval($proj_info['deposit_amount_50'] ?? 0);
        
        $base_formal = round($formal / 1.1);
        $base_add = round($add / 1.1);
        $base_dep_50 = round($dep_50 / 1.1);
        
        $subtotal = ($base_formal + $base_add) - $base_dep_50;
        $tax = round($subtotal * 0.1);
        $grand_total = $subtotal + $tax;

        $msg = "【最終請求書が発行されました】\n";
        $msg .= "残金（本見積＋追加費用から着手金50%分を差し引いた額）を請求させていただきます。\n";
        $msg .= "請求金額: " . number_format($grand_total) . "円 (税込)\n";
        $msg .= "（内訳: 本見積税抜 " . number_format($base_formal) . "円、追加税抜 " . number_format($base_add) . "円、着手金控除税抜 -" . number_format($base_dep_50) . "円、消費税 " . number_format($tax) . "円）\n\n";
        $msg .= "詳細は左パネルの「最終請求書」からご確認ください。よろしくお願い申し上げます。";

        $stmtMsg = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
        $stmtMsg->execute([
            'pid' => $project_id,
            'sid' => $user_id,
            'msg' => $msg
        ]);

        if ($is_local_transaction) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($is_local_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $pdfDriveId;
}
