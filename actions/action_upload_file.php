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
            
            // 一次回答（inv_primary）アップロード時の自動ステータス進行処理
            if ($file_category === 'inv_primary' && ($project['status'] ?? '') === 'primary_prep') {
                $stmtUpdateStatus = $pdo->prepare("UPDATE projects SET status = 'contracted' WHERE id = :pid");
                $stmtUpdateStatus->execute(['pid' => $project_id]);
                
                // スケジュールのインデックス1（着手基準日）に今日の日付を入れる
                $current_actuals_json = $project['schedule_actuals'] ?? '{}';
                $actuals = json_decode($current_actuals_json, true) ?: [];
                if (empty($actuals[1])) {
                    $actuals[1] = date('Y-m-d');
                    $stmtUpdateSchedule = $pdo->prepare("UPDATE projects SET schedule_actuals = :act WHERE id = :pid");
                    $stmtUpdateSchedule->execute(['act' => json_encode($actuals), 'pid' => $project_id]);
                }
                
                // チャット通知
                $stmtNotify = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                $stmtNotify->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => "【自動通知】一次回答が提出されました。ステータスが進行し、スケジュール表の着手基準日が本日付けで設定されました。"
                ]);
            }
            
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
