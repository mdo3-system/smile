<?php
// actions/action_upload_file.php

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
            
            $stmtNew = $pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :fname, :fid, :ver, 1)");
            $stmtNew->execute([
                'pid' => $project_id,
                'cat' => $file_category,
                'fname' => $file_name,
                'fid' => $drive_file_id,
                'ver' => $next_ver
            ]);
            
            // === スケジュール自動入力処理 ===
            $today = date('Y-m-d');
            $colsToUpdate = [];
            $targetIndex = null;
            $msgTitle = "";
            
            // 1. 初回提示（一次回答）の自動入力 (Index 2)
            if ($file_category === 'inv_primary') {
                $colsToUpdate = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                $targetIndex = 2;
                $msgTitle = "初回提示（一次回答）";
                
                // ステータスを自動更新 (受注済へ)
                $stmtStatus = $pdo->prepare("UPDATE projects SET status = 'contracted' WHERE id = :pid");
                $stmtStatus->execute(['pid' => $project_id]);
            }
            
            // 2. 申請図書一式UPの自動入力
            // 許容応力度 (Index 5)
            if ($file_category === 'structural_dwg' && ($project['req_permit'] == 1 || $project['req_opt_kisohari'] == 1)) {
                $colsToUpdate[] = 'schedule_actuals';
                $targetIndex = 5;
                $msgTitle = "構造図・申請図書一式";
            }
            // 壁量計算 (Index 4)
            if (($file_category === 'wall_calc_doc' || $file_category === 'wall_spreadsheet') && $project['req_wall'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_wall';
                $targetIndex = 4;
                $msgTitle = "壁量計算・申請図書一式";
            }
            // 外皮計算 (Index 4)
            if (($file_category === 'skin_calc_doc' || $file_category === 'skin_doc') && $project['req_skin'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_skin';
                $targetIndex = 4;
                $msgTitle = "外皮計算・申請図書一式";
            }
            // 外皮計算・初回提示 (Index 2) は WEBプログラム計算書をUPした時
            if ($file_category === 'skin_web_prog' && $project['req_skin'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_skin';
                $targetIndex = 2;
                $msgTitle = "外皮計算・初回提示（WEBプログラム計算書）";
            }
            // 天空率 (Index 3)
            if ($file_category === 'sky_dwg' && $project['req_sky'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_sky';
                $targetIndex = 3;
                $msgTitle = "天空率図書・申請図書一式";
            }
            
            if (!empty($colsToUpdate) && $targetIndex !== null) {
                $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
                $stmtAct->execute(['id' => $project_id]);
                $current_actuals_row = $stmtAct->fetch(PDO::FETCH_ASSOC);
                
                $updated_any = false;
                foreach (array_unique($colsToUpdate) as $col) {
                    $actuals = json_decode($current_actuals_row[$col] ?? '{}', true) ?: [];
                    if (empty($actuals[$targetIndex])) {
                        $actuals[$targetIndex] = $today;
                        $stmtUpdateSchedule = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                        $stmtUpdateSchedule->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
                        $updated_any = true;
                    }
                }
                
                if ($updated_any) {
                    // チャット通知
                    $stmtNotify = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                    $stmtNotify->execute([
                        'pid' => $project_id,
                        'sid' => $_SESSION['user_id'],
                        'msg' => "【自動通知】{$msgTitle}が提出されました。該当スケジュールの実施日が自動設定されました。"
                    ]);
                }
            }
            // ==================================
            
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die("アップロードに失敗しました: " . $e->getMessage());
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

if ($action === 'toggle_cad_publish' && $is_admin) {
    $file_id = intval($_POST['file_id'] ?? 0);
    if ($file_id > 0) {
        $stmt = $pdo->prepare("UPDATE project_files SET is_published_to_sub = NOT is_published_to_sub WHERE id = :id");
        $stmt->execute(['id' => $file_id]);
    }
    header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
}

// ファイルアップロード処理（管理者・依頼主）
$is_upload = isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK;
$is_included = isset($_POST['included_in_other']) && $_POST['included_in_other'] == '1';

if ($_POST['action_type'] ?? '' === 'single_upload' && ($is_upload || $is_included)) {
    $file_category = $_POST['file_category'] ?? '';
    if ($file_category !== '') {
        try {
            $pdo->beginTransaction();
            
            $file_name = '';
            $drive_file_id = '';
            
            if ($is_included) {
                $file_name = '【他ファイルに記載】';
            } else {
                $file_name = $_FILES['upload_file']['name'];
                $tmp_name = $_FILES['upload_file']['tmp_name'];
                $mime_type = $_FILES['upload_file']['type'];
                // Google Drive へのアップロード
                require_once 'google_drive_client.php';
                $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);
            }

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
