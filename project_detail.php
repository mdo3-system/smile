<?php
// project_detail.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'client']);

$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

$project_id = $_GET['id'] ?? null;
if (!$project_id) { die("案件が指定されていません。"); }

// RBACチェック: 依頼主の場合、自分がオーナーの案件以外へのアクセスを制限
$stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmtProj->execute(['id' => $project_id]);
$project = $stmtProj->fetch();

if (!$project) {
    die("指定された案件が見つかりません。");
}

if ($_SESSION['role'] === 'client' && $project['client_id'] !== $current_user_id) {
    header("HTTP/1.1 403 Forbidden");
    die("この案件へのアクセス権限がありません。<br><a href='index.php'>ダッシュボードへ戻る</a>");
}

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
            $stmtUpdate = $pdo->prepare("UPDATE projects SET status = 'structural_dwg' WHERE id = :pid");
            $stmtUpdate->execute(['pid' => $project_id]);

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
            $stmtUpdate = $pdo->prepare("UPDATE projects SET status = 'submission' WHERE id = :pid");
            $stmtUpdate->execute(['pid' => $project_id]);

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
            $stmtUpdateMode = $pdo->prepare("UPDATE projects SET upload_mode = :mode WHERE id = :pid");
            $stmtUpdateMode->execute(['mode' => $upload_mode, 'pid' => $project_id]);

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
                $stmtStatus = $pdo->prepare("UPDATE projects SET status = 'primary_prep' WHERE id = :pid");
                $stmtStatus->execute(['pid' => $project_id]);
                
                $stmtNotify = $pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, 'client_admin', :msg)");
                $stmtNotify->execute([
                    'pid' => $project_id,
                    'sid' => $_SESSION['user_id'],
                    'msg' => "【通知】構造仕様の指定と必要図書の提出が完了し、設計開始が依頼されました。一次回答期日の設定をお願いします。"
                ]);
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
                $stmt = $pdo->prepare("UPDATE projects SET primary_due_date = :due WHERE id = :pid");
                $stmt->execute(['due' => $due_date, 'pid' => $project_id]);
                
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
                $stmtProj = $pdo->prepare("SELECT schedule_actuals FROM projects WHERE id = :pid");
                $stmtProj->execute(['pid' => $project_id]);
                $current_actuals_json = $stmtProj->fetchColumn();
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

// ==========================================
// データ取得
// ==========================================
// 案件と仕様情報を取得
$stmtProj = $pdo->prepare("
    SELECT p.*, s.*, u.company_name, u.contact_name as client_name, u.phone_number as client_phone
    FROM projects p 
    LEFT JOIN project_specs s ON p.id = s.project_id 
    LEFT JOIN users u ON p.client_id = u.id
    WHERE p.id = :id
");
$stmtProj->execute(['id' => $project_id]);
$project_info = $stmtProj->fetch();

if (!$project_info) {
    die("案件情報の取得に失敗しました。");
}

// 見積情報の取得
$stmtEst = $pdo->prepare("SELECT pdf_drive_file_id FROM estimates WHERE project_id = :pid");
$stmtEst->execute(['pid' => $project_id]);
$estimate_info = $stmtEst->fetch();
$pdf_drive_id = $estimate_info['pdf_drive_file_id'] ?? null;

// 案件に関連する全ファイル（最新のみ）を取得 (依頼主提出物用)
$stmtFiles = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND is_latest = 1");
$stmtFiles->execute(['pid' => $project_id]);
$all_files = $stmtFiles->fetchAll();

// カテゴリごとに整理 (最新のもの)
$files_by_cat = [];
foreach($all_files as $f) {
    $files_by_cat[$f['file_category']] = $f;
}

// 成果物の全履歴を取得 (成果物管理パネル用)
$artifact_categories = [
    'structural_dwg', 'standard_dwg', 'calc_doc', 'safety_cert', 'inv_primary', 'inv_primary_rev',
    'wall_calc_doc', 'wall_kiso_dwg', 'wall_perf_doc',
    'skin_calc_doc', 'skin_energy_doc', 'skin_desc_doc',
    'sky_calc_doc', 'sky_dwg', 'other_artifact'
];
$placeholders = implode(',', array_fill(0, count($artifact_categories), '?'));
$stmtHistory = $pdo->prepare("SELECT * FROM project_files WHERE project_id = ? AND file_category IN ($placeholders) ORDER BY file_category, version DESC");
$params = array_merge([$project_id], $artifact_categories);
$stmtHistory->execute($params);
$artifact_history = $stmtHistory->fetchAll();

$artifacts_by_cat = [];
foreach($artifact_history as $f) {
    $artifacts_by_cat[$f['file_category']][] = $f;
}

// 協力業者一覧を取得
$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// この案件への発注履歴を取得
$stmtOrders = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid ORDER BY o.created_at DESC");
$stmtOrders->execute(['pid' => $project_id]);
$orders = $stmtOrders->fetchAll();

// 未承認の納品を取得
$stmtDelivered = $pdo->prepare("
    SELECT o.*, u.contact_name, f.drive_file_id, f.file_name, f.version
    FROM subcontractor_orders o 
    JOIN users u ON o.subcontractor_id = u.id 
    LEFT JOIN project_files f ON o.project_id = f.project_id AND f.file_category = 'structural_dwg' AND f.is_latest = 1
    WHERE o.project_id = :pid AND o.status = 'delivered'
");
$stmtDelivered->execute(['pid' => $project_id]);
$delivered_orders = $stmtDelivered->fetchAll();

// チャット履歴を取得
$stmtMsgs = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'client_admin' ORDER BY id ASC");
$stmtMsgs->execute(['pid' => $project_id]);
$chat_messages = $stmtMsgs->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>案件詳細 | 構造設計サポート・ポータル</title>
    <style>
        body { font-family: 'Noto Sans JP', sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { display: flex; gap: 20px; max-width: 1400px; margin: 0 auto; align-items: flex-start; }
        .column { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; flex-direction: column; gap: 15px; }
        .col-left { flex: 1; min-width: 300px; }
        .col-center { flex: 1; min-width: 300px; }
        .col-right { flex: 1; min-width: 350px; }
        
        .section-title { font-size: 15px; color: white; padding: 8px 12px; border-radius: 4px; margin-top: 0; margin-bottom: 10px; display:flex; align-items:center; gap:8px; }
        .box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; }
        a.file-link { display: inline-block; background: #eef2f5; color: #0056b3; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; border: 1px solid #d0d7de; }
        a.file-link:hover { background: #e1e4e8; }
        
        /* ===== LINEスタイルチャット ===== */
        .chat-wrapper { display: flex; flex-direction: column; height: 520px; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 10px; background: #ece5dd; border-radius: 6px 6px 0 0; display: flex; flex-direction: column; gap: 8px; }
        .chat-bubble-row { display: flex; align-items: flex-end; gap: 6px; }
        .chat-bubble-row.from-me { flex-direction: row-reverse; }
        .chat-bubble-row.from-me .chat-meta { text-align: right; }
        .chat-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .chat-avatar.admin-avatar { background: #3b82f6; }
        .chat-avatar.client-avatar { background: #28a745; }
        .chat-content { max-width: 70%; }
        .chat-name { font-size: 10px; color: #666; margin-bottom: 2px; }
        .chat-bubble { padding: 8px 12px; border-radius: 16px; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
        .bubble-client { background: #dcf8c6; border-radius: 0 16px 16px 16px; }
        .bubble-admin  { background: #dbeafe; border-radius: 16px 0 16px 16px; }
        .chat-time { font-size: 10px; color: #aaa; margin-top: 2px; }
        .chat-image-thumb { max-width: 160px; max-height: 160px; border-radius: 8px; cursor: pointer; display: block; margin-top: 4px; }
        .chat-pdf-link { display: inline-flex; align-items: center; gap: 5px; background: white; border: 1px solid #ccc; padding: 6px 10px; border-radius: 8px; text-decoration: none; font-size: 12px; color: #0056b3; margin-top: 4px; }
        /* チャット入力エリア */
        .chat-input-area { background: #f0f0f0; border-radius: 0 0 6px 6px; padding: 8px; border-top: 1px solid #ddd; }
        .chat-input-row { display: flex; gap: 6px; align-items: flex-end; }
        .chat-textarea { flex: 1; padding: 8px 12px; border: 1px solid #ccc; border-radius: 20px; font-size: 13px; resize: none; min-height: 38px; max-height: 120px; overflow-y: auto; font-family: inherit; outline: none; }
        .chat-send-btn { background: #17a2b8; color: white; border: none; border-radius: 50%; width: 38px; height: 38px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
        .chat-send-btn:hover { background: #138496; }
        .chat-attach-btn { background: #6c757d; color: white; border: none; border-radius: 50%; width: 38px; height: 38px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
        .chat-file-preview { font-size: 11px; color: #555; margin-top: 4px; padding: 3px 8px; background: white; border-radius: 10px; display: none; }
        /* グリーティングモーダル */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; border-radius: 12px; padding: 24px; max-width: 700px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
        .modal-title { font-size: 16px; font-weight: bold; margin-bottom: 15px; color: #1e293b; border-bottom: 2px solid #3b82f6; padding-bottom: 8px; }
        .modal-body { font-size: 13px; white-space: pre-wrap; background: #f8f9fa; padding: 15px; border-radius: 8px; line-height: 1.7; max-height: 400px; overflow-y: auto; margin-bottom: 15px; }
        .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
    </style>
</head>
<body>
    <div style="max-width: 1400px; margin: 0 auto 15px auto; display:flex; justify-content:space-between; align-items:center;">
        <a href="index.php" style="color:#0056b3; text-decoration:none; font-weight:bold;">➔ 案件一覧に戻る</a>
        <a href="logout.php" style="color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
    </div>

    <div class="container">
        <!-- 左パネル：依頼主と案件情報 -->
        <div class="column col-left">
            <h2 class="section-title" style="background:#4a5568;">📋 案件情報と依頼主図書</h2>
            
            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">基本情報</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <strong>案件名:</strong> <?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?><br>
                    <strong>依頼主:</strong> <?= htmlspecialchars($project_info['company_name'] . ' ' . $project_info['client_name'], ENT_QUOTES) ?><br>
                    <?php if ($is_admin && !empty($project_info['client_phone'])): ?>
                    <strong>📱 電話番号:</strong> <a href="tel:<?= htmlspecialchars($project_info['client_phone'], ENT_QUOTES) ?>" style="color:#0056b3; font-weight:bold;"><?= htmlspecialchars($project_info['client_phone'], ENT_QUOTES) ?></a><br>
                    <?php elseif ($is_admin): ?>
                    <strong>📱 電話番号:</strong> <span style="color:#e53e3e; font-size:11px;">未登録（依頼主に入力を依頼してください）</span><br>
                    <?php endif; ?>
                    <strong>地盤調査:</strong> <?= htmlspecialchars($project_info['soil_status'] ?? '未定', ENT_QUOTES) ?><br>
                    <?php
                    // ステータス日本語化
                    global $status_options;
                    $status_ja = $status_options[$project_info['status']] ?? $project_info['status'];
                    
                    // 契約状態の判定
                    $has_cad = isset($files_by_cat['cad_design_all']) || isset($files_by_cat['all_in_one_zip']);
                    $contract_badge = '';
                    if ($has_cad) {
                        $contract_badge = '<span class="badge" style="background:#8b5cf6; margin-left:5px;">✅ 契約完了 (納期未定)</span>';
                    }

                    // 依頼内容の文字列化
                    $req_types = [];
                    if ($project_info['req_permit'] == 1) $req_types[] = '許容応力度設計';
                    if ($project_info['req_wall'] == 1) $req_types[] = '壁量計算';
                    if ($project_info['req_skin'] == 1) $req_types[] = '外皮計算';
                    if ($project_info['req_sky'] == 1) $req_types[] = '天空率';
                    if ($project_info['req_opt_kisohari'] == 1) $req_types[] = '基礎・横架材許容応力度';
                    $req_str = empty($req_types) ? '未指定' : implode(' / ', $req_types);
                    ?>
                    <strong>依頼内容:</strong> <span style="color:#d97706; font-weight:bold;"><?= htmlspecialchars($req_str, ENT_QUOTES) ?></span><br>
                    <strong>ステータス:</strong> <span class="badge" style="background:#007bff;"><?= htmlspecialchars($status_ja, ENT_QUOTES) ?></span><?= $contract_badge ?>
                </div>
            </div>

            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">依頼主アップロード図書</h3>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $categories = [
                        'pdf_plan' => '平面図',
                        'pdf_elevation' => '立面図',
                        'pdf_layout' => '配置図',
                        'pdf_section' => '矩計図',
                        'pdf_area_calc' => '求積図'
                    ];
                    foreach ($categories as $cat => $label) {
                        if (isset($files_by_cat[$cat])) {
                            $f = $files_by_cat[$cat];
                            $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                                ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                            echo "<div><strong>{$label}:</strong> <br><a href='{$url}' target='_blank' class='file-link'>📄 {$f['file_name']}</a></div>";
                        } else {
                            echo "<div><strong>{$label}:</strong> <span style='color:#999; font-size:12px;'>未提出</span></div>";
                        }
                    }
                    ?>
                </div>
            </div>
            
            <div class="box" style="background:#e8f5e9; border-color:#c8e6c9;">
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">最新の見積書PDF</h3>
                <div style="font-size:12px; color:#666; margin-bottom:10px;">シミュレーターで作成された見積書をPDFとして表示・印刷できます。</div>
                <?php if (!empty($pdf_drive_id)): ?>
                    <a href="https://drive.google.com/file/d/<?= htmlspecialchars($pdf_drive_id, ENT_QUOTES) ?>/view?usp=drivesdk" target="_blank" style="display:block; text-align:center; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; text-decoration:none; font-size:12px; cursor:pointer; line-height:2.2;">
                        📄 最新の見積書を開く（PDF）
                    </a>
                <?php else: ?>
                    <button style="width:100%; background:#777777; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:not-allowed;" disabled>
                        📄 見積書未発行
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($project_info['status'] === 'quote_req' || $project_info['status'] === 'primary_prep'): ?>
            <div class="box" style="background:#f8fafc; border-color:#e2e8f0; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">📋 提出が必要な図書</h3>
                <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; margin-bottom:15px;">
                    <?php
                    // 依頼内容に基づく必要図書の判定
                    $req_docs = [];
                    // 許容応力度の場合は意匠CAD必須（平面、立面、矩計、配置など。ここでは一括ZIPがあればOKとする判定も可能）
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1 || $project_info['req_skin'] == 1 || $project_info['req_sky'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $req_docs['cad_design_all'] = '意匠CAD一式 (または個別図面)';
                    }
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1) {
                        $req_docs['app_doc'] = '確認申請書（2〜5面）';
                        $req_docs['soil_report'] = '地盤調査資料';
                    }
                    // 地盤改良がある場合は追加
                    if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり') {
                        $req_docs['soil_impr'] = '地盤改良関連図書';
                    }

                    foreach ($req_docs as $key => $label) {
                        $is_submitted = false;
                        if (isset($files_by_cat[$key])) {
                            $is_submitted = true;
                        } else if ($key === 'cad_design_all') {
                            // 個別のCAD図面でもOKとする
                            if (isset($files_by_cat['cad_plan']) || isset($files_by_cat['cad_elevation']) || isset($files_by_cat['all_in_one_zip'])) {
                                $is_submitted = true;
                            }
                        }
                        
                        if ($is_submitted) {
                            echo "<div>✅ {$label} <span style='color:#10b981;'>(UP済)</span></div>";
                        } else {
                            echo "<div>❌ <span style='color:#ef4444; font-weight:bold;'>{$label}</span> <span style='color:#999;'>(未提出)</span></div>";
                        }
                    }
                    ?>
                </div>
                <button type="button" onclick="document.getElementById('designModal').classList.add('active')" style="width:100%; background:#3b82f6; color:white; border:none; padding:12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:14px; display:flex; justify-content:center; align-items:center; gap:8px; box-shadow:0 4px 6px rgba(59,130,246,0.3);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    設計依頼・図書アップロード
                </button>
            </div>
            <?php endif; ?>

            <!-- ▼▼▼ 進捗スケジュール可視化 ▼▼▼ -->
            <div class="box" style="background:#fff; border-color:#cbd5e1; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#1e293b; border-bottom:1px solid #cbd5e1; padding-bottom:5px; display:flex; align-items:center; gap:5px;">
                    📅 申請図書UPまでのスケジュール
                </h3>
                <div style="font-size:12px; color:#dc2626; font-weight:bold; margin-bottom:10px; background:#fef2f2; border:1px solid #fecaca; padding:8px; border-radius:4px;">
                    ⚠️ 一次回答の期限は、設計に必要な図書が全て揃った（アップロード完了）時点で再設定（確定）されます。
                </div>
                
                <?php
                // 計算タイプ別の納期判定
                $req_permit = $project_info['req_permit'] ?? 0;
                $req_wall = $project_info['req_wall'] ?? 0;
                $req_skin = $project_info['req_skin'] ?? 0;
                $req_sky = $project_info['req_sky'] ?? 0;
                $req_opt_kisohari = $project_info['req_opt_kisohari'] ?? 0;

                $base_days = 12;
                if ($req_permit == 1 || $req_opt_kisohari == 1) {
                    $base_days = 12;
                } elseif ($req_wall == 1) {
                    $base_days = 7;
                } elseif ($req_skin == 1 || $req_sky == 1) {
                    $base_days = 10;
                }

                $primary_due_date = $project_info['primary_due_date'] ?? null;
                
                // スケジュール定義
                $schedule_steps = [
                    ['name' => '設計図書の提出', 'actor' => 'client', 'desc' => '開始時', 'days' => 0, 'type' => 'base'],
                    ['name' => '標準一次回答', 'actor' => 'designer', 'desc' => "{$base_days}営業日", 'days' => $base_days, 'type' => 'biz'],
                    ['name' => 'CB & 50%ご入金', 'actor' => 'client', 'desc' => '一次回答から4日後', 'days' => 4, 'type' => 'cal'],
                    ['name' => 'CB対応 (設計側)', 'actor' => 'designer', 'desc' => 'CB受領から3営業日', 'days' => 3, 'type' => 'biz'],
                    ['name' => 'CB確認・返答', 'actor' => 'client', 'desc' => 'CB送付から4日後', 'days' => 4, 'type' => 'cal'],
                    ['name' => '構造図作図', 'actor' => 'designer', 'desc' => '決定から4営業日', 'days' => 4, 'type' => 'biz'],
                    ['name' => '構造図CB', 'actor' => 'client', 'desc' => '作図UPから2日後', 'days' => 2, 'type' => 'cal'],
                    ['name' => '構造図修正', 'actor' => 'designer', 'desc' => 'CB受領から4営業日', 'days' => 4, 'type' => 'biz'],
                    ['name' => '構造図CB(最終確認)', 'actor' => 'client', 'desc' => '修正UPから2日後', 'days' => 2, 'type' => 'cal'],
                    ['name' => '申請図書一式UP', 'actor' => 'designer', 'desc' => '確認から3営業日', 'days' => 3, 'type' => 'biz'],
                    ['name' => '補正通知', 'actor' => 'wait', 'desc' => '申請から1ヶ月程度', 'days' => 30, 'type' => 'cal'],
                    ['name' => '補正回答', 'actor' => 'designer', 'desc' => '通知受領から7営業日', 'days' => 7, 'type' => 'biz'],
                    ['name' => '構造審査完了', 'actor' => 'wait', 'desc' => '回答から7日程度', 'days' => 7, 'type' => 'cal'],
                    ['name' => '残金のご精算', 'actor' => 'client', 'desc' => '完了から7日以内', 'days' => 7, 'type' => 'cal'],
                ];

                // 日付計算
                $current_date = $primary_due_date ? date('Y-m-d', strtotime("-{$base_days} weekdays", strtotime($primary_due_date))) : null; 
                // ※厳密な逆算は複雑なので、設定されている場合は primary_due_date を一次回答日に固定して以降を順次計算する
                if ($primary_due_date) {
                    $current_calc_date = $primary_due_date;
                }

                echo '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
                echo '<thead><tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;"><th style="padding:6px; text-align:left;">工程</th><th style="padding:6px; text-align:left;">担当</th><th style="padding:6px; text-align:left;">予定</th><th style="padding:6px; text-align:left;">実施日</th></tr></thead>';
                echo '<tbody>';
                
                $calc_date = $primary_due_date; // primary_due_date がある場合はこれを基準日として以降を計算
                $schedule_actuals = json_decode($project_info['schedule_actuals'] ?? '{}', true) ?: [];
                
                foreach ($schedule_steps as $idx => $step) {
                    $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8fafc';
                    $badge = '';
                    if ($step['actor'] == 'designer') {
                        $badge = '<span style="background:#3b82f6; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟦 サポート</span>';
                    } elseif ($step['actor'] == 'client') {
                        $client_display_name = htmlspecialchars($project_info['client_name'], ENT_QUOTES) . '様';
                        $badge = '<span style="background:#10b981; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟩 ' . $client_display_name . '</span>';
                    } else {
                        $badge = '<span style="background:#64748b; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">⬛ 審査・待機</span>';
                    }

                    $date_str = '<span style="color:#64748b;">未確定</span>';
                    
                    if ($primary_due_date) {
                        if ($idx == 0) {
                            $date_str = '<span style="color:#64748b;">-</span>';
                        } elseif ($idx == 1) {
                            $calc_date = $primary_due_date;
                            $date_str = '<strong>' . date('m/d', strtotime($primary_due_date)) . '</strong>';
                        } else {
                            if ($step['type'] == 'biz') {
                                $calc_date = addBusinessDays($calc_date, $step['days']);
                            } elseif ($step['type'] == 'cal') {
                                $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                            }
                            $date_str = date('m/d', strtotime($calc_date));
                        }
                    }

                    // 実施日があればそれを起算日に上書きする
                    $actual_date = $schedule_actuals[$idx] ?? '';
                    if ($actual_date) {
                        $calc_date = $actual_date;
                        $date_str = '<span style="color:#10b981; font-weight:bold;">' . date('m/d', strtotime($actual_date)) . ' (済)</span>';
                    }

                    // 実施日入力フォーム (管理者のみ、一次回答日設定後)
                    $actual_form = '';
                    if ($is_admin && $primary_due_date) {
                        $actual_form = '
                        <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:5px; align-items:center;">
                            <input type="hidden" name="action" value="update_schedule_actual">
                            <input type="hidden" name="step_idx" value="'.$idx.'">
                            <input type="date" name="actual_date" value="'.htmlspecialchars($actual_date, ENT_QUOTES).'" style="font-size:10px; padding:2px;">
                            <button type="submit" style="font-size:10px; padding:2px 5px; background:#e2e8f0; border:1px solid #cbd5e1; border-radius:3px; cursor:pointer;">保存</button>
                        </form>';
                    }

                    echo "<tr style='background:{$bg_color}; border-bottom:1px solid #e2e8f0;'>";
                    echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step['name']}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
                    echo "<td style='padding:6px;'>{$badge}</td>";
                    echo "<td style='padding:6px;'>{$date_str}</td>";
                    echo "<td style='padding:6px;'>{$actual_form}</td>";
                    echo "</tr>";
                }
                echo '</tbody></table>';
                ?>
            </div>
            <!-- ▲▲▲ 進捗スケジュール可視化 ▲▲▲ -->

            <?php if ($is_admin && $project_info['status'] === 'primary_prep'): ?>
            <div class="box" style="background:#fff3cd; border-color:#ffeeba; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">
                    🎯 一次回答期日の設定
                </h3>
                <div style="font-size:12px; color:#666; margin-bottom:10px;">
                    依頼主から必要図書が提出されました。一次回答の期日を設定して設計スケジュールを確定させてください。
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                    <input type="hidden" name="action" value="set_primary_due_date">
                    <input type="date" name="primary_due_date" value="<?= $project_info['primary_due_date'] ?? '' ?>" required style="padding:6px; font-size:13px; border:1px solid #ccc; border-radius:4px; margin-bottom:10px; width:100%; box-sizing:border-box;">
                    <button type="submit" style="width:100%; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;">期日を設定してスケジュールを確定</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($project_info['status'] === 'quote_req' || $project_info['status'] === 'primary_prep'): ?>
            <!-- ▼▼▼ 依頼主 詳細仕様指定・図書アップロード（モーダル） ▼▼▼ -->
            <div id="designModal" class="modal-overlay">
                <div class="modal-box" style="max-width:800px; position:relative; background:#f8fafc;">
                    <button type="button" onclick="closeDesignModal()" style="position:absolute; right:15px; top:15px; background:none; border:none; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
                    <h3 class="modal-title" style="margin-top:0; font-size:16px; color:#0f172a; border-bottom:1px solid #cbd5e1; padding-bottom:5px;">
                        📤 設計開始依頼（必要図書の提出と詳細仕様の指定）
                    </h3>
                
                <?php
                $upload_mode = $project_info['upload_mode'] ?? 'individual';
                $wood_json = json_decode($project_info['wood_details'] ?? '{}', true) ?: [];
                $wall_json = json_decode($project_info['wall_details'] ?? '{}', true) ?: [];
                $hw_json = json_decode($project_info['hardware_details'] ?? '{}', true) ?: [];
                ?>
                
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data">
                    
                    <div style="margin-bottom:15px; background:#fff; padding:15px; border:2px solid #ef4444; border-radius:6px;">
                        <div style="font-size:13px; font-weight:bold; color:#b91c1c; margin-bottom:8px;">⚠️ 見積時からの図面変更の有無（必須）</div>
                        <div style="display:flex; gap:15px; font-size:12px; margin-bottom:10px;">
                            <label><input type="radio" name="drawing_changed" value="no" required> 変更なし</label>
                            <label><input type="radio" name="drawing_changed" value="yes" required> 変更あり</label>
                        </div>
                        <textarea name="drawing_change_notes" placeholder="変更ありの場合は、変更箇所を簡単にご記入ください。" style="width:100%; padding:8px; font-size:12px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;"></textarea>
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-size:12px; font-weight:bold; color:#334155; display:block; margin-bottom:5px;">📂 ファイルの提出方法</label>
                        <div style="display:flex; gap:15px; font-size:12px;">
                            <label><input type="radio" name="upload_mode" value="combined" onchange="toggleUploadMode()" <?= $upload_mode === 'combined' ? 'checked' : '' ?>> 1つのファイル（ZIP等）にまとめてアップロードする</label>
                            <label><input type="radio" name="upload_mode" value="individual" onchange="toggleUploadMode()" <?= $upload_mode === 'individual' ? 'checked' : '' ?>> 個別のファイルに分けてアップロード・指定する</label>
                        </div>
                    </div>

                    <!-- 一括アップロードエリア -->
                    <div id="mode_combined" style="display: <?= $upload_mode === 'combined' ? 'block' : 'none' ?>; background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:15px;">
                        <div style="font-size:12px; font-weight:bold; margin-bottom:10px;">必要図書一括 (ZIP/PDF) <span style="color:#ef4444;">※CADデータ必須</span></div>
                        <input type="file" name="upload_files[all_in_one_zip]" style="font-size:12px; width:100%;">
                        <?php if(isset($files_by_cat['all_in_one_zip'])): ?>
                            <div style="font-size:11px; margin-top:5px;">✅ 提出済: <a href="https://drive.google.com/file/d/<?= htmlspecialchars($files_by_cat['all_in_one_zip']['drive_file_id'], ENT_QUOTES) ?>/view?usp=drivesdk" target="_blank"><?= htmlspecialchars($files_by_cat['all_in_one_zip']['file_name'], ENT_QUOTES) ?></a></div>
                        <?php endif; ?>
                    </div>

                    <!-- 個別アップロードエリア -->
                    <div id="mode_individual" style="display: <?= $upload_mode === 'individual' ? 'block' : 'none' ?>;">
                        
                        <!-- A. 共通図書 -->
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">A. 共通図書</div>
                            <div style="display:grid; gap:10px;">
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">意匠CADデータ (平面・立面・配置・矩計を含む) <span style="color:#ef4444;">※必須</span></div>
                                    <input type="file" name="upload_files[cad_design_all]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['cad_design_all'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['cad_design_all']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">確認申請書 (2面〜5面)</div>
                                    <input type="file" name="upload_files[app_doc]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['app_doc'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['app_doc']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">求積図</div>
                                    <input type="file" name="upload_files[pdf_area_calc]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['pdf_area_calc'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['pdf_area_calc']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- B. 構造計算 -->
                        <?php if ($req_permit == 1 || $req_wall == 1 || $req_opt_kisohari == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">B. 構造仕様・図書</div>
                            
                            <div style="display:grid; gap:10px;">
                                <div style="background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">地盤調査の状況</div>
                                    <div style="display:flex; gap:15px; font-size:11px; margin-bottom:10px;">
                                        <label><input type="radio" name="soil_status" value="調査済" <?= ($project_info['soil_status']??'')==='調査済' ? 'checked' : '' ?>> 調査済</label>
                                        <label><input type="radio" name="soil_status" value="未調査+令96条但し書" <?= ($project_info['soil_status']??'')==='未調査+令96条但し書' ? 'checked' : '' ?>> 未調査+令96条但し書</label>
                                        <label><input type="radio" name="soil_status" value="調査予定" <?= ($project_info['soil_status']??'')==='調査予定' ? 'checked' : '' ?>> 調査予定</label>
                                    </div>
                                    
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">地盤調査報告書 / 改良関連図書</div>
                                    <div style="font-size:10px; color:#ef4444; margin-bottom:5px;">※新しくアップロードすると、過去にアップロードした同種の図書は上書き(非表示)されます。</div>
                                    <div style="display:grid; gap:5px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span style="font-size:11px; width:70px;">調査報告書:</span>
                                            <input type="file" name="upload_files[soil_report]" style="font-size:11px; flex:1;">
                                        </div>
                                        <div id="soil_imp_container" style="display:flex; flex-direction:column; gap:5px;">
                                            <div style="display:flex; align-items:center; gap:5px;">
                                                <span style="font-size:11px; width:70px;">改良関連図書:</span>
                                                <input type="file" name="upload_files[soil_improvement_spec][]" style="font-size:11px; flex:1;" title="改良設計書/計算書/認定書など">
                                                <button type="button" onclick="addSoilRow()" style="font-size:11px; padding:2px 5px; cursor:pointer;">＋追加</button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top:10px; font-size:10px;">
                                    <?php 
                                        if(isset($files_by_cat['soil_report'])) echo '<div style="color:#16a34a;">✅ 調査報告書: '.htmlspecialchars($files_by_cat['soil_report']['file_name']).'</div>'; 
                                        // 複数あるかもしれない改良設計書（現在は最新のみ表示する設計だが、履歴も含め複数あれば表示）
                                        // TODO: 厳密には $files_by_cat はカテゴリごと1つしか持っていない場合がある。複数対応は別途考慮。
                                        if(isset($files_by_cat['soil_improvement_spec'])) echo '<div style="color:#16a34a;">✅ 改良関連図書: '.htmlspecialchars($files_by_cat['soil_improvement_spec']['file_name']).'</div>';
                                    ?>
                                    </div>
                                </div>
                                
                                <div style="background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">耐力壁・筋交い仕様指定</div>
                                    <div style="display:grid; gap:5px; font-size:11px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>面材:</span>
                                            <select name="wall_menzai_type" style="padding:2px;">
                                                <?php renderOptions(['構造用合板', 'OSB', 'MDF', 'パーティクルボード', 'その他'], $wall_json['menzai']['type'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="wall_menzai_other" placeholder="その他の場合" value="<?= htmlspecialchars($wall_json['menzai']['other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>筋交い:</span>
                                            <select name="wall_sujikai_type" style="padding:2px;">
                                                <?php renderOptions(['30×45', '45×90', '90×90', 'その他'], $wall_json['sujikai']['type'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="wall_sujikai_other" placeholder="その他の場合" value="<?= htmlspecialchars($wall_json['sujikai']['other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                    </div>
                                    <div style="margin-top:5px; font-size:11px;">ファイル添付: <input type="file" name="upload_files[wall_spec]" style="font-size:10px;"></div>
                                    <?php if(isset($files_by_cat['wall_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['wall_spec']['file_name']).'</div>'; ?>
                                </div>
                                
                                <div style="background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">金物仕様指定</div>
                                    <div style="display:grid; gap:5px; font-size:11px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>金物仕様:</span>
                                            <select name="hw_type" style="padding:2px;">
                                                <?php renderOptions(['Z金物', 'その他'], $hw_json['type'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="hw_type_other" placeholder="その他の場合" value="<?= htmlspecialchars($hw_json['type_other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>金物工法:</span>
                                            <select name="hw_method" style="padding:2px;">
                                                <?php renderOptions(['Tec-One', 'プレセッター', 'Stroog', 'その他'], $hw_json['method'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="hw_method_other" placeholder="その他の場合" value="<?= htmlspecialchars($hw_json['method_other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                    </div>
                                    <div style="margin-top:5px; font-size:11px;">ファイル添付: <input type="file" name="upload_files[hardware_spec]" style="font-size:10px;"></div>
                                    <?php if(isset($files_by_cat['hardware_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['hardware_spec']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- C. 構造材種 -->
                        <?php if ($req_permit == 1 || $req_opt_kisohari == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">C. 構造材種</div>
                            
                            <div style="font-size:11px; margin-bottom:10px;">プレカット図等による指定: <input type="file" name="upload_files[wood_species_spec]" style="font-size:10px;"></div>
                            <?php if(isset($files_by_cat['wood_species_spec'])) echo '<div style="font-size:10px; color:#16a34a; margin-bottom:10px;">✅ '.htmlspecialchars($files_by_cat['wood_species_spec']['file_name']).'</div>'; ?>
                            
                            <table style="width:100%; font-size:11px; border-collapse:collapse; border:1px solid #e2e8f0;">
                                <tr style="background:#f1f5f9;"><th style="border:1px solid #e2e8f0; padding:4px;">部位</th><th style="border:1px solid #e2e8f0; padding:4px;">材種</th><th style="border:1px solid #e2e8f0; padding:4px;">サイズ/その他</th></tr>
                                
                                <?php 
                                    $wood_opts_std = ['スギKD', 'ヒノキKD', 'ベイマツKD', 'ベイツガKD', 'WWKD', 'E65-F255', 'E95-F315', 'E105-F300', 'E135-F375', 'その他'];
                                    $size_opts_105_120 = ['□105', '□120', 'その他'];
                                    $size_opts_90_105 = ['□90', '□105', 'その他'];
                                    
                                    function renderWoodRow($name, $key, $wood_json, $wood_opts, $size_opts) {
                                        echo '<tr>';
                                        echo '<td style="border:1px solid #e2e8f0; padding:4px; font-weight:bold;">'.$name.'</td>';
                                        echo '<td style="border:1px solid #e2e8f0; padding:4px;">';
                                        echo '<select name="wood_'.$key.'_type" style="width:100%; padding:2px; font-size:10px;">';
                                        renderOptions($wood_opts, $wood_json[$key]['type'] ?? '');
                                        echo '</select></td>';
                                        
                                        echo '<td style="border:1px solid #e2e8f0; padding:4px; display:flex; gap:2px;">';
                                        if ($key === 'taruki') {
                                            echo 'W <input type="number" name="wood_'.$key.'_w" value="'.htmlspecialchars($wood_json[$key]['w'] ?? '', ENT_QUOTES).'" style="width:30px; font-size:10px;"> × ';
                                            echo 'H <input type="number" name="wood_'.$key.'_h" value="'.htmlspecialchars($wood_json[$key]['h'] ?? '', ENT_QUOTES).'" style="width:30px; font-size:10px;">';
                                        } else {
                                            echo '<select name="wood_'.$key.'_size" style="width:60px; padding:2px; font-size:10px;">';
                                            renderOptions($size_opts, $wood_json[$key]['size'] ?? '');
                                            echo '</select>';
                                        }
                                        echo '<input type="text" name="wood_'.$key.'_other" placeholder="その他" value="'.htmlspecialchars($wood_json[$key]['other'] ?? '', ENT_QUOTES).'" style="flex:1; padding:2px; font-size:10px;">';
                                        echo '</td></tr>';
                                    }
                                    
                                    renderWoodRow('土台', 'foundation', $wood_json, $wood_opts_std, $size_opts_105_120);
                                    renderWoodRow('柱', 'column', $wood_json, $wood_opts_std, $size_opts_105_120);
                                    renderWoodRow('梁', 'beam', $wood_json, $wood_opts_std, $size_opts_105_120);
                                    renderWoodRow('大引', 'obiki', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('小屋束', 'koyatsuka', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('母屋', 'moya', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('棟木', 'munagi', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('垂木', 'taruki', $wood_json, $wood_opts_std, []);
                                    renderWoodRow('火打', 'hiuchi', $wood_json, ['スギKD', 'ベイマツKD', 'Z金物', 'その他'], ['その他']);
                                ?>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- D. 天空率 -->
                        <?php if ($req_sky == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">D. 天空率図書</div>
                            <div style="display:grid; gap:10px;">
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">道路の資料 (座標、測量図、道路台帳、高さ等)</div>
                                    <input type="file" name="upload_files[road_data]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['road_data'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['road_data']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">真北の資料</div>
                                    <input type="file" name="upload_files[true_north]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['true_north'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['true_north']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- E. 外皮計算 -->
                        <?php if ($req_skin == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">E. 外皮計算図書</div>
                            <div style="display:grid; gap:10px;">
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">断熱材・サッシ・ガラス仕様指定</div>
                                    <input type="file" name="upload_files[insulation_spec]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['insulation_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['insulation_spec']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">矩計図（使用断熱材の部位記載あり）</div>
                                    <input type="file" name="upload_files[section_dwg_ins]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['section_dwg_ins'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['section_dwg_ins']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">設備仕様書（換気・エアコン・給湯器・照明等）</div>
                                    <input type="file" name="upload_files[equipment_spec]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['equipment_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['equipment_spec']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- F. その他欄 -->
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">F. その他欄</div>
                            <textarea name="client_notes_extra" rows="3" style="width:100%; font-size:11px; padding:5px; border:1px solid #ccc; border-radius:4px; margin-bottom:5px;"><?= htmlspecialchars($project_info['client_notes_extra'] ?? '', ENT_QUOTES) ?></textarea>
                            <input type="file" name="upload_files[other_extra]" style="font-size:11px; width:100%;">
                            <?php if(isset($files_by_cat['other_extra'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['other_extra']['file_name']).'</div>'; ?>
                        </div>

                    </div>

                    <input type="hidden" name="action" id="form_action" value="">
                    
                    <div style="display:flex; gap:10px; margin-top:20px;">
                        <button type="submit" onclick="document.getElementById('form_action').value='save_client_specs_draft'; document.querySelectorAll('input[required], textarea[required], select[required]').forEach(e => e.removeAttribute('required'));" style="flex:1; background:#f8fafc; color:#475569; border:1px solid #cbd5e1; padding:12px; border-radius:8px; font-weight:bold; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                            💾 一時保存する（適宜アップロード用）
                        </button>
                        
                        <button type="submit" onclick="document.getElementById('form_action').value='request_design_start'; return confirm('必要図書・仕様を提出し、設計開始を依頼します。よろしいですか？');" style="flex:2; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; border:none; padding:12px; border-radius:8px; font-weight:bold; cursor:pointer; box-shadow:0 4px 15px rgba(16,185,129,0.3);">
                            🚀 全て揃ったので設計開始を依頼する
                        </button>
                    </div>
                </form>

                <script>
                    function toggleUploadMode() {
                        const isCombined = document.querySelector('input[name="upload_mode"][value="combined"]').checked;
                        document.getElementById('mode_combined').style.display = isCombined ? 'block' : 'none';
                        document.getElementById('mode_individual').style.display = isCombined ? 'none' : 'block';
                    }
                    function openDesignModal() {
                        document.getElementById('designModal').classList.add('active');
                    }
                    function closeDesignModal() {
                        document.getElementById('designModal').classList.remove('active');
                    }
                    function addSoilRow() {
                        const container = document.getElementById('soil_imp_container');
                        const div = document.createElement('div');
                        div.style.display = 'flex';
                        div.style.alignItems = 'center';
                        div.style.gap = '5px';
                        div.innerHTML = '<span style="font-size:11px; width:70px;">(追加分):</span><input type="file" name="upload_files[soil_improvement_spec][]" style="font-size:11px; flex:1;" title="改良設計書/計算書/認定書など"><button type="button" onclick="this.parentElement.remove()" style="font-size:11px; padding:2px 5px; cursor:pointer;">削除</button>';
                        container.appendChild(div);
                    }
                </script>
                </div>
            </div>
            <!-- ▲▲▲ 依頼主 詳細仕様指定・図書アップロード（モーダル） ▲▲▲ -->
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <!-- 管理者専用：協力業者への発注 -->
            <h2 class="section-title" style="background:#e67e22;">🤝 協力業者への発注・タスク管理</h2>
            <div class="box" style="background:#fff9f0;">
                <div style="font-size:11px; margin-bottom:5px;"><strong>自動発注額算出</strong></div>
                <div style="display:flex; gap:5px;">
                    <input type="number" id="sub_area" placeholder="面積(㎡)" style="width:60px; font-size:12px;">
                    <button type="button" onclick="calcSubcontractorEstimate()" style="font-size:11px; padding:2px 5px;">算出</button>
                </div>
                <div id="sub_calc_result" style="margin-bottom:10px;"></div>
                <script>
                function calcSubcontractorEstimate() {
                    const area = parseFloat(document.getElementById('sub_area').value) || 0;
                    if (area <= 0) return;
                    const total = 30000 + Math.round(area * 500);
                    document.getElementById('sub_calc_result').innerHTML = 
                        '<span style="color:#28a745;font-size:12px;font-weight:bold;">推奨発注額: ' + total.toLocaleString() + '円</span>';
                    document.querySelector('input[name="order_amount"]').value = total;
                }
                </script>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:10px;">
                    <input type="hidden" name="action" value="order_subcontractor">
                    <select name="subcontractor_id" style="width:100%; margin-bottom:5px; font-size:12px;">
                        <?php foreach($subcontractors as $sub): ?>
                            <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['contact_name'], ENT_QUOTES) ?> 様</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="task_title" placeholder="依頼内容（例：構造図作図）" style="width:100%; margin-bottom:5px; font-size:12px;">
                    <input type="number" name="order_amount" placeholder="金額(税込)" style="width:100%; margin-bottom:5px; font-size:12px;">
                    <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:5px; font-size:12px; cursor:pointer; border-radius:3px;">発注を確定・送信</button>
                </form>
            </div>

            <div style="font-size:11px; color:#555;">
                <h3 style="font-size:12px; border-bottom:1px solid #ccc; margin-top:0;">発注履歴</h3>
                <?php foreach($orders as $o): ?>
                    <div style="padding:4px 0; border-bottom:1px solid #eee;">
                        <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?>: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?> (<?= number_format($o['order_amount']) ?>円)
                        <span class="badge" style="background:#555;"><?= htmlspecialchars($o['status'], ENT_QUOTES) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                    <div style="color:#999;">発注履歴はありません。</div>
                <?php endif; ?>
            </div>

            <!-- 協力業者ダッシュボードへの切り替えリンク -->
            <div style="margin-top:15px; padding:10px; background:#e8f0fe; border:1px solid #93c5fd; border-radius:6px; text-align:center;">
                <div style="font-size:11px; color:#555; margin-bottom:8px;">この案件を協力業者視点で確認する</div>
                <a href="project_subcontractor.php?id=<?= $project_id ?>" target="_blank" style="display:inline-block; background:#3b82f6; color:white; padding:7px 15px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">👷 協力業者ダッシュボードで見る</a>
            </div>
            <?php endif; ?>
        </div>



        <!-- 中央パネル：成果物一覧 -->
        <div class="column col-center">
            <h2 class="section-title" style="background:#8b5cf6;">📂 成果物（納品物）</h2>
            <div style="font-size:12px; color:#555; margin-bottom:15px;">常に最新版が表示されます。過去の履歴もここからダウンロード可能です。</div>

            <?php
            // 各種目別の成果物定義
            $artifact_sections = [];
            
            if ($req_permit == 1 || $req_opt_kisohari == 1) {
                $artifact_sections['許容応力度計算'] = [
                    'structural_dwg' => '構造図',
                    'standard_dwg' => '構造標準図',
                    'calc_doc' => '構造計算書',
                    'safety_cert' => '安全証明書',
                    'inv_primary' => '一次回答',
                    'inv_primary_rev' => '修正一次回答'
                ];
            }
            if ($req_wall == 1) {
                $artifact_sections['性能表示壁量計算'] = [
                    'wall_calc_doc' => '壁量計算書',
                    'wall_kiso_dwg' => '基礎伏図',
                    'wall_perf_doc' => '性能評価用図書'
                ];
            }
            if ($req_skin == 1) {
                $artifact_sections['外皮計算'] = [
                    'skin_calc_doc' => '外皮計算書',
                    'skin_energy_doc' => '一次エネ計算書',
                    'skin_desc_doc' => '設計内容説明書'
                ];
            }
            if ($req_sky == 1) {
                $artifact_sections['天空率計算'] = [
                    'sky_calc_doc' => '天空率計算書',
                    'sky_dwg' => '天空率図面'
                ];
            }
            // その他の納品物
            $artifact_sections['その他納品物'] = [
                'other_artifact' => 'その他ファイル'
            ];

            foreach ($artifact_sections as $section_title => $categories):
                // このセクション内のファイルが一つでもUPされているか、または管理者の場合は表示
                $show_section = $is_admin;
                if (!$is_admin) {
                    foreach ($categories as $cat => $label) {
                        if (!empty($artifacts_by_cat[$cat])) { $show_section = true; break; }
                    }
                }
                if (!$show_section) continue;
            ?>
                <div class="box" style="margin-bottom:15px; background:#f8fafc; border:1px solid #cbd5e1;">
                    <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #cbd5e1; padding-bottom:5px; color:#1e293b;"><?= $section_title ?></h3>
                    
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($categories as $cat => $label): ?>
                            <?php $history = $artifacts_by_cat[$cat] ?? []; ?>
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; padding:8px;">
                                <div style="font-weight:bold; font-size:12px; color:#334155; margin-bottom:5px;"><?= $label ?></div>
                                
                                <?php if (!empty($history)): 
                                    $latest = $history[0]; 
                                    $url = (strpos($latest['drive_file_id'], 'uploads/') !== 0 && !empty($latest['drive_file_id'])) 
                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($latest['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                        : htmlspecialchars($latest['drive_file_id'], ENT_QUOTES);
                                ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px;">
                                        <a href="<?= $url ?>" target="_blank" class="file-link" style="background:#10b981; color:white; border-color:#059669;">
                                            📄 最新版ダウンロード (V<?= $latest['version'] ?>)
                                        </a>
                                        
                                        <?php if (count($history) > 1): ?>
                                            <select onchange="if(this.value) window.open(this.value, '_blank');" style="font-size:11px; padding:3px; max-width:140px;">
                                                <option value="">過去バージョン...</option>
                                                <?php foreach ($history as $idx => $h): 
                                                    if ($idx === 0) continue; // 最新は除外
                                                    $h_url = (strpos($h['drive_file_id'], 'uploads/') !== 0 && !empty($h['drive_file_id'])) 
                                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($h['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                                        : htmlspecialchars($h['drive_file_id'], ENT_QUOTES);
                                                    $dateStr = date('m/d H:i', strtotime($h['created_at']));
                                                ?>
                                                    <option value="<?= $h_url ?>">V<?= $h['version'] ?> (<?= $dateStr ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:10px; color:#64748b; margin-top:3px; word-break:break-all;">
                                        ファイル名: <?= htmlspecialchars($latest['file_name'], ENT_QUOTES) ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:11px; color:#94a3b8;">未提出</div>
                                <?php endif; ?>

                                <!-- 管理者用 アップロードフォーム -->
                                <?php if ($is_admin): ?>
                                    <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin-top:8px; display:flex; gap:5px; align-items:center; border-top:1px dashed #e2e8f0; padding-top:5px;">
                                        <input type="hidden" name="action" value="upload_artifact">
                                        <input type="hidden" name="file_category" value="<?= $cat ?>">
                                        <input type="file" name="artifact_file" required style="font-size:10px; width:150px;">
                                        <button type="submit" style="font-size:10px; background:#3b82f6; color:white; border:none; padding:3px 8px; border-radius:3px; cursor:pointer;">UP</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 右パネル：チャット・管理ツール -->
        <div class="column col-right" style="padding: 15px;">
            <?php if ($is_admin && count($delivered_orders) > 0): ?>
                <div class="box" style="background:#fff3cd; border: 1px solid #ffeeba; margin-bottom:15px;">
                    <h3 style="margin-top:0; color:#856404; font-size:13px;">🔔 納品確認エリア（成果物の承認待ち）</h3>
                    <?php foreach ($delivered_orders as $del): ?>
                        <div style="font-size:11px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px dashed #ffeeba; color:#666;">
                            <strong>担当者:</strong> <?= htmlspecialchars($del['contact_name'], ENT_QUOTES) ?> 様<br>
                            <strong>タスク:</strong> <?= htmlspecialchars($del['task_title'], ENT_QUOTES) ?><br>
                            <strong>納品物:</strong> 
                            <?php if ($del['drive_file_id']): 
                                $download_url = (strpos($del['drive_file_id'], 'uploads/') !== 0 && !empty($del['drive_file_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['drive_file_id'], ENT_QUOTES);
                            ?>
                                <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">📄 確認する (V<?= $del['version'] ?>)</a>
                            <?php endif; ?>
                            
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:8px;">
                                <input type="hidden" name="action" value="approve_delivery">
                                <input type="hidden" name="order_id" value="<?= $del['id'] ?>">
                                <button type="submit" style="background:#28a745; color:white; border:none; padding:4px 10px; font-size:11px; border-radius:3px; cursor:pointer;">承認してクライアントへ公開</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h2 class="section-title" style="background:#17a2b8; margin:0;">💬 依頼主チャット</h2>
                <?php if ($is_admin && $project_info['status'] === 'quote_req'): ?>
                <button onclick="document.getElementById('greetingModal').classList.add('active')" style="font-size:11px; background:#28a745; color:white; border:none; padding:5px 10px; border-radius:12px; cursor:pointer;">📋 定型文を送信</button>
                <?php endif; ?>
            </div>

            <!-- チャットエリア -->
            <div class="chat-wrapper">
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($chat_messages as $msg):
                        $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                        $rowClass = $isMe ? 'from-me' : '';
                        $bubbleClass = ($msg['sender_id'] == 1) ? 'bubble-admin' : 'bubble-client';
                        $avatarClass = ($msg['sender_id'] == 1) ? 'admin-avatar' : 'client-avatar';
                        $avatarIcon  = ($msg['sender_id'] == 1) ? '👷' : '👤';
                        $senderName  = ($msg['sender_id'] == 1) ? '管理者' : htmlspecialchars($project_info['client_name'], ENT_QUOTES);
                        $timeStr     = date('m/d H:i', strtotime($msg['created_at'] ?? 'now'));
                    ?>
                        <div class="chat-bubble-row <?= $rowClass ?>" data-msg-id="<?= $msg['id'] ?>">
                            <div class="chat-avatar <?= $avatarClass ?>"><?= $avatarIcon ?></div>
                            <div class="chat-content">
                                <?php if (!$isMe): ?>
                                <div class="chat-name"><?= $senderName ?></div>
                                <?php endif; ?>
                                <?php if (!empty($msg['message_text'])): ?>
                                <div class="chat-bubble <?= $bubbleClass ?>"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($msg['file_path'])): ?>
                                    <?php
                                        $ftype = $msg['file_type'] ?? '';
                                        $fpath = $msg['file_path'];
                                        // Google Drive IDかローカルパスかを判定
                                        $isGdrive = (strlen($fpath) > 15 && strpos($fpath, '/') === false && strpos($fpath, 'uploads/') !== 0);
                                        $furl = $isGdrive ? 'https://drive.google.com/file/d/' . htmlspecialchars($fpath, ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($fpath, ENT_QUOTES);
                                        $thumbUrl = $isGdrive ? 'https://drive.google.com/thumbnail?id=' . htmlspecialchars($fpath, ENT_QUOTES) . '&sz=w200' : '';
                                    ?>
                                    <?php if ($ftype === 'image' && $isGdrive): ?>
                                        <a href="<?= $furl ?>" target="_blank">
                                            <img src="<?= $thumbUrl ?>" class="chat-image-thumb" alt="添付画像">
                                        </a>
                                    <?php elseif ($ftype === 'pdf' || !empty($fpath)): ?>
                                        <a href="<?= $furl ?>" target="_blank" class="chat-pdf-link">📄 添付ファイルを開く</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="chat-time"><?= $timeStr ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($chat_messages)): ?>
                        <div style="text-align:center; color:#aaa; font-size:12px; margin-top:40px;">メッセージはまだありません</div>
                    <?php endif; ?>
                </div>

                <!-- 入力エリア -->
                <div class="chat-input-area">
                    <div id="filePreview" class="chat-file-preview"></div>
                    <div style="margin-bottom:8px;">
                        <select id="chatTargetFile" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; font-size:12px;">
                            <option value="">-- 対象ファイル（全体へのメッセージ） --</option>
                            <?php
                            $uploaded_file_names = [];
                            foreach ($files_by_cat as $cat => $f) { $uploaded_file_names[] = $f['file_name']; }
                            $stmtAllCenter = $pdo->prepare("SELECT file_name FROM project_files WHERE project_id = :pid AND is_latest = 1 ORDER BY created_at DESC");
                            $stmtAllCenter->execute(['pid' => $project_id]);
                            while ($row = $stmtAllCenter->fetch(PDO::FETCH_ASSOC)) { $uploaded_file_names[] = $row['file_name']; }
                            $uploaded_file_names = array_unique($uploaded_file_names);
                            foreach ($uploaded_file_names as $fname) {
                                echo '<option value="' . htmlspecialchars($fname, ENT_QUOTES) . '">📎 ' . htmlspecialchars($fname, ENT_QUOTES) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="chat-input-row">
                        <label class="chat-attach-btn" title="ファイルを添付">
                            📎
                            <input type="file" id="chatFileInput" accept="image/*,.pdf" style="display:none;" onchange="previewFile(this)">
                        </label>
                        <textarea id="chatTextarea" class="chat-textarea" placeholder="メッセージを入力..." rows="1" onkeydown="handleKey(event)"></textarea>
                        <button class="chat-send-btn" onclick="sendMessage()" title="送信">➤</button>
                    </div>
                </div>
            </div>

            <?php if ($is_admin): ?>
            <!-- 管理者専用エリア -->
            <div style="margin-top: 20px; border-top: 2px dashed #ccc; padding-top: 15px;">
                <div style="font-size:11px; font-weight:bold; color:#c0392b; margin-bottom:10px;">🔒 以下は管理者のみに表示されます</div>
                
                <!-- Google Drive 連携状況 -->
                <div style="background:#f8f9fa; border:1px solid #ddd; padding:10px; border-radius:5px; margin-bottom:15px; font-size:11px; display:flex; align-items:center; justify-content:between;">
                    <div>
                        <strong>📂 Googleドライブ連携:</strong>
                        <?php if (file_exists(__DIR__ . '/token.json')): ?>
                            <span style="color:#28a745; font-weight:bold;">🟢 連携完了</span>
                        <?php else: ?>
                            <span style="color:#dc3545; font-weight:bold;">🔴 未連携（ファイルの送受信ができません）</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="google_auth.php" target="_blank" style="font-weight:bold; color:white; background:#007bff; padding:3px 8px; border-radius:4px; text-decoration:none; margin-left:10px;">
                            <?= file_exists(__DIR__ . '/token.json') ? '認証を更新する' : 'Google連携ログイン' ?>
                        </a>
                    </div>
                </div>
                
                <?php if ($project_info['status'] === 'quote_req'): ?>
                <h2 class="section-title" style="background:#28a745;">💰 自動見積シミュレーター</h2>
                <div class="box" style="background:#e8f5e9; font-size:11px; display:flex; flex-direction:column; gap:10px;">
                    <!-- 計算タイプの選択 -->
                    <div>
                        <strong>計算タイプ（複数選択可）</strong><br>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_permit" onchange="toggleEstContainers(); calcClientEstimate();" checked> 許容応力度計算</label>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_wall" onchange="toggleEstContainers(); calcClientEstimate();"> 性能表示壁量計算（性能表示のみ）</label>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_skin" onchange="toggleEstContainers(); calcClientEstimate();"> 外皮計算（一次エネ計算セット）</label>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_sky" onchange="toggleEstContainers(); calcClientEstimate();"> 天空率計算</label>
                    </div>

                    <!-- 1. 許容応力度計算用フォーム -->
                    <div id="container_permit" class="box" style="background:#ffffff; border:1px solid #ccc; display:block; padding:8px; margin:0;">
                        <strong style="color:#2e7d32;">【許容応力度計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                基本料金<br>
                                <select id="est_base_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="75000">平屋建・2階建 (75,000円)</option>
                                    <option value="100000">3階建 (100,000円)</option>
                                </select>
                            </div>
                            <div>
                                構造床面積 (㎡) <span style="color:#666;">*150㎡超は600円/㎡加算</span><br>
                                <input type="number" id="est_area_permit" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                目標等級加算<br>
                                <select id="est_grade_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="0">なし (0円)</option>
                                    <option value="40000">耐震等級3+耐風等級2 (+40,000円)</option>
                                    <option value="20000">耐震等級2 (+20,000円)</option>
                                    <option value="40000">耐震等級3 (+40,000円)</option>
                                </select>
                            </div>
                            <div>
                                形状・仕様加算（基本料金+面積割増に乗算）<br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.2" onchange="calcClientEstimate()"> 準耐火/耐火構造 (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.2" onchange="calcClientEstimate()"> PH階がある (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.1" onchange="calcClientEstimate()"> 小屋裏収納がある (+10%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.1" onchange="calcClientEstimate()"> スキップ等レベル違い (+10%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="1.0" onchange="calcClientEstimate()"> 平面不整形 (+100%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="1.0" onchange="calcClientEstimate()"> 立面不整形 (+100%)</label>
                            </div>
                            <div>
                                その他加算（固定額）<br>
                                <label>金物工法階数: <input type="number" id="est_kanamono_permit" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 階 (+15,000円/階)</label><br>
                                <label>斜め壁等特殊箇所数: <input type="number" id="est_special_permit" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 2. 性能表示壁量計算用フォーム -->
                    <div id="container_wall" class="box" style="background:#ffffff; border:1px solid #ccc; display:none; padding:8px; margin:0;">
                        <strong style="color:#c0392b;">【性能表示壁量計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                基本料金<br>
                                <select id="est_base_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="35000">性能表示 平屋建 (35,000円)</option>
                                    <option value="50000">性能表示 2階建 (50,000円)</option>
                                </select>
                            </div>
                            <div>
                                構造床面積 (㎡) <span style="color:#666;">*150㎡超は500円/㎡加算</span><br>
                                <input type="number" id="est_area_wall" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                構造図（基礎伏図）作成<br>
                                <select id="est_dwg_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="0">依頼なし (0円)</option>
                                    <option value="15000">建築面積 50㎡未満 (+15,000円)</option>
                                    <option value="20000">建築面積 100㎡未満 (+20,000円)</option>
                                    <option value="25000">建築面積 150㎡未満 (+25,000円)</option>
                                    <option value="30000">建築面積 150㎡以上 (+30,000円)</option>
                                </select>
                            </div>
                            <div>
                                人通孔箇所数割増<br>
                                <select id="est_jintsu_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="0">5箇所未満 (0円)</option>
                                    <option value="5000">5箇所以上10箇所未満 (+5,000円)</option>
                                    <option value="10000">10箇所以上 (+10,000円)</option>
                                </select>
                            </div>
                            <div>
                                基礎梁許容応力度計算<br>
                                <label><input type="checkbox" id="est_kisohari_wall" onchange="calcClientEstimate()"> 依頼する (+20,000円、※150㎡超は500円/㎡加算)</label>
                            </div>
                            <div>
                                形状加算（基本料金+面積割増に乗算）<br>
                                <label><input type="checkbox" class="est_mult_wall" value="0.2" onchange="calcClientEstimate()"> PH階がある (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_wall" value="0.1" onchange="calcClientEstimate()"> 小屋裏収納がある (+10%)</label><br>
                                <label><input type="checkbox" class="est_mult_wall" value="0.1" onchange="calcClientEstimate()"> スキップレベル違いがある (+10%)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 3. 外皮計算用フォーム -->
                    <div id="container_skin" class="box" style="background:#ffffff; border:1px solid #ccc; display:none; padding:8px; margin:0;">
                        <strong style="color:#d35400;">【外皮計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                基本料金<br>
                                <select id="est_base_skin" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="20000">平屋建 (20,000円)</option>
                                    <option value="35000">2階建 (35,000円)</option>
                                    <option value="50000">3階建 (50,000円)</option>
                                </select>
                            </div>
                            <div>
                                外皮床面積 (㎡) <span style="color:#666;">*100㎡超は500円/㎡加算</span><br>
                                <input type="number" id="est_area_skin" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                形状加算（基本料金+面積割増に乗算）<br>
                                <label><input type="checkbox" class="est_mult_skin" value="0.2" onchange="calcClientEstimate()"> PH階がある (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_skin" value="0.1" onchange="calcClientEstimate()"> スキップレベル違いがある (+10%)</label>
                            </div>
                            <div>
                                その他加算（固定額）<br>
                                <label>基礎立上り400超箇所数: <input type="number" id="est_kisotachi_skin" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label><br>
                                <label><input type="checkbox" id="est_setsumei_skin" onchange="calcClientEstimate()"> 設計内容説明書を作成する (+15,000円)</label><br>
                                <label><input type="checkbox" id="est_energy_skin" checked disabled> 一次消費エネルギー計算書 (+15,000円 ※セット)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 4. 天空率用フォーム -->
                    <div id="container_sky" class="box" style="background:#ffffff; border:1px solid #ccc; display:none; padding:8px; margin:0;">
                        <strong style="color:#2980b9;">【天空率計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                対象斜線<br>
                                <label><input type="checkbox" id="est_road_sky" onchange="calcClientEstimate()" checked> 道路斜線天空率 (50,000円)</label><br>
                                <label><input type="checkbox" id="est_north_sky" onchange="calcClientEstimate()"> 北側斜線天空率 (50,000円)</label>
                            </div>
                            <div>
                                追加検討斜線面数<br>
                                <label>追加面数: <input type="number" id="est_extra_sky" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 面 (+25,000円/面、※1面目は基本料金に含む)</label>
                            </div>
                            <div>
                                敷地面積 (㎡) <span style="color:#666;">*150㎡超は200円/㎡加算</span><br>
                                <input type="number" id="est_site_area_sky" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                建物床面積 (㎡) <span style="color:#666;">*150㎡超は200円/㎡加算</span><br>
                                <input type="number" id="est_building_area_sky" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                詳細モデル検討加算<br>
                                <label><input type="checkbox" id="est_detail_sky" onchange="calcClientEstimate()"> 建物の詳細モデルによる検討を行う (+15,000円)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 計算結果表示 -->
                    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
                        見積合計 (税抜): <span id="est_total_disp" style="color:#d32f2f; font-size:12px;">0</span> 円<br>
                        消費税 (10%): <span id="est_tax_disp" style="color:#555; font-size:11px;">0</span> 円<br>
                        税込合計: <span id="est_grand_total_disp" style="color:#28a745; font-size:12px;">0</span> 円
                    </div>

                    <div style="margin-top:10px; display:flex; gap:10px; flex-direction:column;">
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="calcClientEstimate()" style="flex:1; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">再計算</button>
                            <button type="button" id="pdf_issue_btn" onclick="saveAndPrintEstimate()" style="flex:2; background:#ff9800; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">印刷用PDFを発行</button>
                        </div>
                        <button type="button" onclick="sendClientEstimate()" style="width:100%; background:#28a745; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">チャットに見積を送信</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== 定型文モーダル ===== -->
    <div class="modal-overlay" id="greetingModal">
        <div class="modal-box">
            <div class="modal-title">📋 初回お見積り案内（定型文送信）</div>
            <div class="modal-body" id="greetingText">この度はお見積り依頼を頂きましてありがとうございます。
早速意匠図を拝見させていただきましたのでお見積書を送付いたします。

■一次回答納期は「15営業日」となります。（お昼の12時前までの依頼図書送付は1日カウント、水曜日・日曜日定休、8/10~17お盆休み）
納期短縮は他の案件と相談になりますが、お見積もり価格の10%/日 で対応いたします。

▼お見積内容
・構造計算書 → お見積りに含みます
・安全証明書 → お見積りに含みます
・構造図一式 → お見積りに含みます
・確認申請の質疑対応 → お見積りに含みます
・現場検査対応（配筋検査、軸組検査） → お見積りに含みません。合計で30分以内で対応できる写真提出による施工確認は無償対応いたします。

【業務の流れ】
1. 一次回答は構造計算プログラムからの出力による、柱配置・耐力壁配置・梁成・梁伏・水平構面・金物等一式をUP致します。
2. ご確認いただき、意匠図の変更を伴わない変更は無償対応いたします。梁成による階高の変更は無償対応いたします。構造図作図以降の変更は @6,000円/時間+税 となります。
3. 一次回答を1か月以内にご確認いただきます。お見積額の50%入金をお願い致します。ご入金確認後4営業日以内に構造図をUP致します。
4. 構造図をご確認いただき、意匠図との整合含めOKとなりましたら、安全証明書・計算書・構造図・構造標準図をUP致します。
5. 補正通知が来ましたらUPいただき、概ね4営業日を目安に補正回答いたします。
6. 構造補正・審査完了後、1週間以内に残金のご精算をお願いいたします。

※一次回答のチェックバック・50%のご入金が4営業日以内にいただけない場合は、対応日数に加算されますこと、予めご承知おき願います。
※基本は設計サポート業務となりますので、私は設計者にはなりません。

ご依頼いただける際は下記をお送りください：
1. 意匠図CADデータ（JWW/DXF等）
2. 確認申請書 2面〜5面
3. 地盤調査報告書
4. 構造材種の指定（土台・大引・柱・梁・小屋束・母屋・棟木・垂木・火打）
5. Z金物以外の場合は金物仕様の指定
6. 耐力壁配置ルール（大臣認定耐力壁 EXハイパー、パーティクルボード、内部筋違 等）

高さの不整合が多い傾向にございます。構造で図面間の高さの不整合は手が止まってしまいますこと、予めご承知おき願います。

ご検討いただき、ご用命賜れますようお願い申し上げます。

菅原
設計サポート専用ダイヤル 070-8305-8480
SMS送付する場合がございますので、ご依頼いただける際は上記番号を受け付ける設定としていただけますようお願い申し上げます。</div>
            <div class="modal-btns">
                <button onclick="document.getElementById('greetingModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                <button onclick="sendGreeting()" style="padding:8px 20px; background:#17a2b8; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">このメッセージを送信</button>
            </div>
        </div>
    </div>

    <script>
    // ===== チャット変数 =====
    const PROJECT_ID = <?= $project_id ?>;
    const CURRENT_USER_ID = <?= $_SESSION['user_id'] ?>;
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
    const CLIENT_NAME = '<?= htmlspecialchars($project_info['client_name'] ?? '依頼主', ENT_QUOTES) ?>';
    let lastMsgId = <?= !empty($chat_messages) ? end($chat_messages)['id'] : 0 ?>;

    // ===== チャット自動スクロール =====
    function scrollToBottom() {
        const el = document.getElementById('chatMessages');
        if (el) el.scrollTop = el.scrollHeight;
    }
    window.addEventListener('DOMContentLoaded', scrollToBottom);

    // ===== メッセージバブルHTML生成 =====
    function buildBubble(msg) {
        const isMe = (msg.sender_id == CURRENT_USER_ID);
        const isAdminMsg = (msg.sender_id == 1);
        const rowClass = isMe ? 'from-me' : '';
        const bubbleClass = isAdminMsg ? 'bubble-admin' : 'bubble-client';
        const avatarClass = isAdminMsg ? 'admin-avatar' : 'client-avatar';
        const avatarIcon = isAdminMsg ? '👷' : '👤';
        const senderName = isAdminMsg ? '管理者' : CLIENT_NAME;
        const timeStr = msg.created_at ? msg.created_at.substring(5, 16).replace('T', ' ') : '';

        let fileHtml = '';
        if (msg.file_path) {
            const isGdrive = msg.file_path.length > 15 && !msg.file_path.includes('/');
            const furl = isGdrive ? `https://drive.google.com/file/d/${msg.file_path}/view?usp=drivesdk` : msg.file_path;
            if (msg.file_type === 'image' && isGdrive) {
                const thumb = `https://drive.google.com/thumbnail?id=${msg.file_path}&sz=w200`;
                fileHtml = `<a href="${furl}" target="_blank"><img src="${thumb}" class="chat-image-thumb" alt="添付画像"></a>`;
            } else if (msg.file_path) {
                fileHtml = `<a href="${furl}" target="_blank" class="chat-pdf-link">📄 添付ファイルを開く</a>`;
            }
        }

        const nameHtml = !isMe ? `<div class="chat-name">${senderName}</div>` : '';
        const textHtml = msg.message_text ? `<div class="chat-bubble ${bubbleClass}">${msg.message_text.replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}</div>` : '';

        return `<div class="chat-bubble-row ${rowClass}" data-msg-id="${msg.id}">
            <div class="chat-avatar ${avatarClass}">${avatarIcon}</div>
            <div class="chat-content">
                ${nameHtml}
                ${textHtml}
                ${fileHtml}
                <div class="chat-time">${timeStr}</div>
            </div>
        </div>`;
    }

    // ===== ポーリング（30秒ごと） =====
    function pollMessages() {
        fetch(`api_get_messages.php?project_id=${PROJECT_ID}&since_id=${lastMsgId}`)
            .then(r => r.json())
            .then(msgs => {
                if (msgs && msgs.length > 0) {
                    const container = document.getElementById('chatMessages');
                    // 「まだありません」テキストを消す
                    const empty = container.querySelector('[data-empty]');
                    if (empty) empty.remove();
                    msgs.forEach(msg => {
                        container.insertAdjacentHTML('beforeend', buildBubble(msg));
                        lastMsgId = msg.id;
                    });
                    scrollToBottom();
                }
            }).catch(e => console.error('ポーリングエラー:', e));
    }
    setInterval(pollMessages, 30000);

    // ===== メッセージ送信 =====
    function sendMessage(text) {
        const textarea = document.getElementById('chatTextarea');
        const fileInput = document.getElementById('chatFileInput');
        const targetSelect = document.getElementById('chatTargetFile');
        const msg = text || textarea.value.trim();
        if (!msg && fileInput.files.length === 0) return;

        const formData = new FormData();
        formData.append('project_id', PROJECT_ID);
        formData.append('message_text', msg);
        if (targetSelect && targetSelect.value) {
            formData.append('target_file', targetSelect.value);
        }
        if (fileInput.files.length > 0) {
            formData.append('file', fileInput.files[0]);
        }

        const sendBtn = document.querySelector('.chat-send-btn');
        sendBtn.disabled = true;
        sendBtn.textContent = '...';

        fetch('api_send_message.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    textarea.value = '';
                    fileInput.value = '';
                    document.getElementById('filePreview').style.display = 'none';
                    pollMessages();
                } else {
                    alert('送信に失敗しました: ' + (data.error || '不明なエラー'));
                }
            })
            .catch(e => alert('通信エラー: ' + e))
            .finally(() => {
                sendBtn.disabled = false;
                sendBtn.textContent = '➤';
            });
    }

    // ===== Enterキーで送信（Shift+Enterで改行） =====
    function handleKey(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    }

    // ===== ファイルプレビュー =====
    function previewFile(input) {
        const preview = document.getElementById('filePreview');
        if (input.files.length > 0) {
            preview.textContent = '📎 ' + input.files[0].name;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
        }
    }

    // ===== 定型文送信 =====
    function sendGreeting() {
        const text = document.getElementById('greetingText').innerText;
        document.getElementById('greetingModal').classList.remove('active');
        sendMessage(text);
    }

    // ===== 見積シミュレーターアコーディオン制御 =====
    function toggleEstContainers() {
        if(document.getElementById('container_permit')) {
            document.getElementById('container_permit').style.display = document.getElementById('est_active_permit').checked ? 'block' : 'none';
        }
        if(document.getElementById('container_wall')) {
            document.getElementById('container_wall').style.display = document.getElementById('est_active_wall').checked ? 'block' : 'none';
        }
        if(document.getElementById('container_skin')) {
            document.getElementById('container_skin').style.display = document.getElementById('est_active_skin').checked ? 'block' : 'none';
        }
        if(document.getElementById('container_sky')) {
            document.getElementById('container_sky').style.display = document.getElementById('est_active_sky').checked ? 'block' : 'none';
        }
    }

    // ===== 見積計算ロジック =====
    let currentEstimate = 0, currentTax = 0, currentTotal = 0;
    let estimateItems = [];
    
    function calcClientEstimate() {
        currentEstimate = 0;
        estimateItems = [];
        
        const permit_active = document.getElementById('est_active_permit')?.checked || false;
        const wall_active = document.getElementById('est_active_wall')?.checked || false;
        const skin_active = document.getElementById('est_active_skin')?.checked || false;
        const sky_active = document.getElementById('est_active_sky')?.checked || false;
        
        // 1. 許容応力度計算
        if (permit_active) {
            let base = parseInt(document.getElementById('est_base_permit').value) || 0;
            let area = parseFloat(document.getElementById('est_area_permit').value) || 0;
            let area_extra = area > 150 ? Math.ceil(area - 150) * 600 : 0;
            let base_with_area = base + area_extra;
            
            let multiplier = 0;
            document.querySelectorAll('.est_mult_permit:checked').forEach(cb => multiplier += parseFloat(cb.value));
            let shape_extra = Math.round(base_with_area * multiplier);
            
            let grade = parseInt(document.getElementById('est_grade_permit').value) || 0;
            let kanamono = parseInt(document.getElementById('est_kanamono_permit').value) || 0;
            let special = parseInt(document.getElementById('est_special_permit').value) || 0;
            
            let kanamono_extra = kanamono * 15000;
            let special_extra = special * 15000;
            
            let subtotal = base_with_area + shape_extra + grade + kanamono_extra + special_extra;
            currentEstimate += subtotal;
            
            estimateItems.push({ name: "許容応力度計算 基本料金", qty: 1, unit: "式", price: base, amount: base });
            if (area_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 構造床面積割増 (150㎡超)", qty: Math.ceil(area - 150), unit: "㎡", price: 600, amount: area_extra });
            }
            if (shape_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 形状・仕様割増 (" + Math.round(multiplier * 100) + "%)", qty: 1, unit: "式", price: shape_extra, amount: shape_extra });
            }
            if (grade > 0) {
                let gradeName = "許容応力度計算 等級加算";
                if(grade == 40000) gradeName = "許容応力度計算 等級加算(耐震3/耐風2等)";
                if(grade == 20000) gradeName = "許容応力度計算 等級加算(耐震2)";
                estimateItems.push({ name: gradeName, qty: 1, unit: "式", price: grade, amount: grade });
            }
            if (kanamono_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 金物工法割増", qty: kanamono, unit: "階", price: 15000, amount: kanamono_extra });
            }
            if (special_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 特殊箇所割増", qty: special, unit: "箇所", price: 15000, amount: special_extra });
            }
        }
        
        // 2. 性能表示壁量計算
        if (wall_active) {
            let base = parseInt(document.getElementById('est_base_wall').value) || 0;
            let area = parseFloat(document.getElementById('est_area_wall').value) || 0;
            let area_extra = area > 150 ? Math.ceil(area - 150) * 500 : 0;
            let base_with_area = base + area_extra;
            
            let multiplier = 0;
            document.querySelectorAll('.est_mult_wall:checked').forEach(cb => multiplier += parseFloat(cb.value));
            let shape_extra = Math.round(base_with_area * multiplier);
            
            let dwg = parseInt(document.getElementById('est_dwg_wall').value) || 0;
            let jintsu = parseInt(document.getElementById('est_jintsu_wall').value) || 0;
            
            let kisohari = document.getElementById('est_kisohari_wall').checked;
            let kisohari_extra = 0;
            if (kisohari) {
                kisohari_extra = 20000;
                if (area > 150) {
                    kisohari_extra += Math.ceil(area - 150) * 500;
                }
            }
            
            let subtotal = base_with_area + shape_extra + dwg + jintsu + kisohari_extra;
            currentEstimate += subtotal;
            
            estimateItems.push({ name: "性能表示壁量計算 基本料金", qty: 1, unit: "式", price: base, amount: base });
            if (area_extra > 0) {
                estimateItems.push({ name: "性能表示壁量計算 構造床面積割増 (150㎡超)", qty: Math.ceil(area - 150), unit: "㎡", price: 500, amount: area_extra });
            }
            if (shape_extra > 0) {
                estimateItems.push({ name: "性能表示壁量計算 形状割増 (" + Math.round(multiplier * 100) + "%)", qty: 1, unit: "式", price: shape_extra, amount: shape_extra });
            }
            if (dwg > 0) {
                let dwgName = "性能表示壁量計算 構造図作成";
                estimateItems.push({ name: dwgName, qty: 1, unit: "式", price: dwg, amount: dwg });
            }
            if (jintsu > 0) {
                estimateItems.push({ name: "性能表示壁量計算 人通孔箇所割増", qty: 1, unit: "式", price: jintsu, amount: jintsu });
            }
            if (kisohari_extra > 0) {
                estimateItems.push({ name: "性能表示壁量計算 基礎梁許容応力度計算", qty: 1, unit: "式", price: kisohari_extra, amount: kisohari_extra });
            }
        }
        
        // 3. 外皮計算
        if (skin_active) {
            let base = parseInt(document.getElementById('est_base_skin').value) || 0;
            let area = parseFloat(document.getElementById('est_area_skin').value) || 0;
            let area_extra = area > 100 ? Math.ceil(area - 100) * 500 : 0;
            let base_with_area = base + area_extra;
            
            let multiplier = 0;
            document.querySelectorAll('.est_mult_skin:checked').forEach(cb => multiplier += parseFloat(cb.value));
            let shape_extra = Math.round(base_with_area * multiplier);
            
            let kisotachi = parseInt(document.getElementById('est_kisotachi_skin').value) || 0;
            let kisotachi_extra = kisotachi * 15000;
            
            let setsumei = document.getElementById('est_setsumei_skin').checked ? 15000 : 0;
            let energy_extra = 15000; // 一次エネルギー計算は常にセットで加算
            
            let subtotal = base_with_area + shape_extra + kisotachi_extra + setsumei + energy_extra;
            currentEstimate += subtotal;
            
            estimateItems.push({ name: "外皮計算 基本料金", qty: 1, unit: "式", price: base, amount: base });
            if (area_extra > 0) {
                estimateItems.push({ name: "外皮計算 外皮床面積割増 (100㎡超)", qty: Math.ceil(area - 100), unit: "㎡", price: 500, amount: area_extra });
            }
            if (shape_extra > 0) {
                estimateItems.push({ name: "外皮計算 形状割増 (" + Math.round(multiplier * 100) + "%)", qty: 1, unit: "式", price: shape_extra, amount: shape_extra });
            }
            if (kisotachi_extra > 0) {
                estimateItems.push({ name: "外皮計算 基礎立上り400超割増", qty: kisotachi, unit: "箇所", price: 15000, amount: kisotachi_extra });
            }
            if (setsumei > 0) {
                estimateItems.push({ name: "外皮計算 設計内容説明書作成", qty: 1, unit: "式", price: 15000, amount: setsumei });
            }
            estimateItems.push({ name: "一次消費エネルギー量計算", qty: 1, unit: "式", price: 15000, amount: energy_extra });
        }
        
        // 4. 天空率計算
        if (sky_active) {
            let road = document.getElementById('est_road_sky').checked ? 50000 : 0;
            let north = document.getElementById('est_north_sky').checked ? 50000 : 0;
            
            let extra = parseInt(document.getElementById('est_extra_sky').value) || 0;
            let extra_extra = extra * 25000;
            
            let site_area = parseFloat(document.getElementById('est_site_area_sky').value) || 0;
            let site_area_extra = site_area > 150 ? Math.ceil(site_area - 150) * 200 : 0;
            
            let building_area = parseFloat(document.getElementById('est_building_area_sky').value) || 0;
            let building_area_extra = building_area > 150 ? Math.ceil(building_area - 150) * 200 : 0;
            
            let detail = document.getElementById('est_detail_sky').checked ? 15000 : 0;
            
            let subtotal = road + north + extra_extra + site_area_extra + building_area_extra + detail;
            currentEstimate += subtotal;
            
            if (road > 0) {
                estimateItems.push({ name: "天空率 道路斜線基本料金", qty: 1, unit: "式", price: 50000, amount: road });
            }
            if (north > 0) {
                estimateItems.push({ name: "天空率 北側斜線基本料金", qty: 1, unit: "式", price: 50000, amount: north });
            }
            if (extra_extra > 0) {
                estimateItems.push({ name: "天空率 追加斜線面検討", qty: extra, unit: "面", price: 25000, amount: extra_extra });
            }
            if (site_area_extra > 0) {
                estimateItems.push({ name: "天空率 敷地面積割増 (150㎡超)", qty: Math.ceil(site_area - 150), unit: "㎡", price: 200, amount: site_area_extra });
            }
            if (building_area_extra > 0) {
                estimateItems.push({ name: "天空率 建物床面積割増 (150㎡超)", qty: Math.ceil(building_area - 150), unit: "㎡", price: 200, amount: building_area_extra });
            }
            if (detail > 0) {
                estimateItems.push({ name: "天空率 詳細モデル検討", qty: 1, unit: "式", price: 15000, amount: detail });
            }
        }
        
        currentTax = Math.round(currentEstimate * 0.1);
        currentTotal = currentEstimate + currentTax;
        
        const elTotal = document.getElementById('est_total_disp');
        if (elTotal) elTotal.innerText = currentEstimate.toLocaleString();
        
        const elTax = document.getElementById('est_tax_disp');
        if (elTax) elTax.innerText = currentTax.toLocaleString();
        
        const elGrand = document.getElementById('est_grand_total_disp');
        if (elGrand) elGrand.innerText = currentTotal.toLocaleString();
    }
    
    // ===== チャットに見積を送信 =====
    function sendClientEstimate() {
        calcClientEstimate();
        if (currentEstimate === 0) {
            alert('計算する対象を選択してください。');
            return;
        }
        
        let msg = "【概算お見積り内訳】\n";
        estimateItems.forEach(item => {
            msg += `・${item.name} x ${item.qty}${item.unit} : ${item.amount.toLocaleString()}円\n`;
        });
        msg += `\n税抜金額: ${currentEstimate.toLocaleString()}円\n`;
        msg += `消費税: ${currentTax.toLocaleString()}円\n`;
        msg += `税込合計: ${currentTotal.toLocaleString()}円\n\n`;
        msg += "よろしければ正式にご依頼ください。";
        
        sendMessage(msg);
    }
    
    // ===== 見積書の保存とPDF自動発行 =====
    function saveAndPrintEstimate() {
        calcClientEstimate();
        if (currentEstimate === 0) {
            alert('計算する対象を選択してください。');
            return;
        }
        
        const btn = document.getElementById('pdf_issue_btn');
        btn.disabled = true;
        btn.innerText = 'PDF発行中...';
        
        let base_val = 0;
        let area_val = 0;
        let grade_val = 0;
        
        const permit_active = document.getElementById('est_active_permit')?.checked || false;
        const wall_active = document.getElementById('est_active_wall')?.checked || false;
        const skin_active = document.getElementById('est_active_skin')?.checked || false;
        
        if (permit_active) {
            base_val = parseInt(document.getElementById('est_base_permit').value) || 0;
            area_val = parseFloat(document.getElementById('est_area_permit').value) || 0;
            grade_val = parseInt(document.getElementById('est_grade_permit').value) || 0;
        } else if (wall_active) {
            base_val = parseInt(document.getElementById('est_base_wall').value) || 0;
            area_val = parseFloat(document.getElementById('est_area_wall').value) || 0;
        } else if (skin_active) {
            base_val = parseInt(document.getElementById('est_base_skin').value) || 0;
            area_val = parseFloat(document.getElementById('est_area_skin').value) || 0;
        }
        
        const formData = new FormData();
        formData.append('project_id', PROJECT_ID);
        formData.append('base_price', base_val);
        formData.append('area', area_val);
        formData.append('grade_price', grade_val);
        formData.append('total_price', currentEstimate);
        formData.append('note', JSON.stringify(estimateItems));
        
        fetch('api_save_estimate.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.drive_file_id) {
                    alert('お見積書PDFが自動作成され、Google Driveおよびチャットに共有されました。');
                    window.open(`https://drive.google.com/file/d/${data.drive_file_id}/view?usp=drivesdk`, '_blank');
                    location.reload();
                } else {
                    alert('見積保存・PDF発行に失敗しました: ' + (data.error || '不明なエラー'));
                }
            })
            .catch(e => {
                alert('通信エラー: ' + e);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerText = '印刷用PDFを発行';
            });
    }
    
    window.addEventListener('DOMContentLoaded', () => {
        toggleEstContainers();
        calcClientEstimate();
    });
    </script>

</body>
</html>
