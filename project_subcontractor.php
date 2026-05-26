<?php
// project_subcontractor.php
require_once 'auth.php';
check_auth(['admin', 'subcontractor']);

// セッションからログイン中の業者IDを取得
$sub_id = $_SESSION['user_id']; 

// 承諾処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && !isset($_POST['action'])) {
    $order_id = intval($_POST['order_id']);
    $stmt = $pdo->prepare("UPDATE subcontractor_orders SET status = 'accepted' WHERE id = :id AND subcontractor_id = :sub_id");
    $stmt->execute(['id' => $order_id, 'sub_id' => $sub_id]);
    header("Location: project_subcontractor.php");
    exit;
}

// 納品処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deliver_task') {
    $order_id = intval($_POST['order_id']);
    $project_id = intval($_POST['project_id']);
    
    if (isset($_FILES['delivery_file']) && $_FILES['delivery_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['delivery_file']['tmp_name'];
        $file_name = $_FILES['delivery_file']['name'];
        $mime_type = $_FILES['delivery_file']['type'];
        
        try {
            // Google Drive へのアップロード
            require_once 'google_drive_client.php';
            $drive_file_id = upload_to_google_drive($file_tmp, $file_name, $mime_type);
            
            $pdo->beginTransaction();
            // 1. 最新バージョンの確認
            $stmtVer = $pdo->prepare("SELECT MAX(version) as max_v FROM project_files WHERE project_id = :pid AND file_category = 'structural_dwg'");
            $stmtVer->execute(['pid' => $project_id]);
            $max_v = $stmtVer->fetch()['max_v'] ?? 0;
            $new_v = $max_v + 1;
            
            // 2. 過去のファイルの is_latest を 0 に更新
            $stmtUpdateLatest = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = 'structural_dwg'");
            $stmtUpdateLatest->execute(['pid' => $project_id]);
            
            // 3. 新しいファイルを登録
            $stmtInsertFile = $pdo->prepare("
                INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                VALUES (:pid, 'structural_dwg', :fname, :fpath, :ver, 1)
            ");
            $stmtInsertFile->execute([
                'pid' => $project_id,
                'fname' => $file_name,
                'fpath' => $drive_file_id,
                'ver' => $new_v
            ]);
            
            // 4. 発注ステータスを delivered (納品済) に更新
            $stmtOrder = $pdo->prepare("UPDATE subcontractor_orders SET status = 'delivered' WHERE id = :id AND subcontractor_id = :sub_id");
            $stmtOrder->execute(['id' => $order_id, 'sub_id' => $sub_id]);
            
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            die("納品処理に失敗しました: " . $e->getMessage());
        }
    }
    header("Location: project_subcontractor.php");
    exit;
}

// 自分の担当案件リストを取得
$stmt = $pdo->prepare("
    SELECT o.*, p.project_name, p.status AS project_status 
    FROM subcontractor_orders o 
    JOIN projects p ON o.project_id = p.id 
    WHERE o.subcontractor_id = :sub_id 
    ORDER BY o.created_at DESC
");
$stmt->execute(['sub_id' => $sub_id]);
$my_tasks = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>協力業者専用ポータル</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .task-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 15px; border-left: 5px solid #e67e22; }
        .btn-accept { background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #ccc; padding-bottom:10px; margin-bottom:20px;">
        <h1 style="margin:0; font-size:24px;">👷 協力業者専用ダッシュボード</h1>
        <div style="font-size:14px;">
            ログイン中: <strong><?= htmlspecialchars($_SESSION['contact_name'], ENT_QUOTES) ?></strong> 様
            <a href="logout.php" style="margin-left:15px; color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
        </div>
    </div>
    <?php foreach ($my_tasks as $task): 
        // 該当案件の最新のCADファイルを取得
        $stmtFiles = $pdo->prepare("
            SELECT * FROM project_files 
            WHERE project_id = :project_id 
            AND file_category LIKE 'cad_%' 
            AND is_latest = 1
        ");
        $stmtFiles->execute(['project_id' => $task['project_id']]);
        $cad_files = $stmtFiles->fetchAll();
    ?>
        <div class="task-card">
            <h3>案件名: <?= htmlspecialchars($task['project_name'], ENT_QUOTES) ?></h3>
            <p>依頼内容: <?= htmlspecialchars($task['task_title'], ENT_QUOTES) ?></p>
            <p>報酬額: <strong><?= number_format($task['order_amount']) ?>円</strong></p>
            
            <?php if ($task['status'] === 'requested'): ?>
                <form method="POST">
                    <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                    <button type="submit" class="btn-accept">この依頼を承諾する</button>
                </form>
                
                <div class="cad-files-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; color:#c0392b; font-size:13px;">
                    <strong>📂 CADデータダウンロード:</strong>
                    <span style="margin-left:10px; font-weight:bold;">🔒 承諾待ちのため非公開 (依頼承諾後に公開されます)</span>
                </div>
            <?php elseif ($task['status'] === 'accepted'): ?>
                <p>状態: <span class="badge" style="background:#007bff; color:white; padding:3px 8px; border-radius:4px;">作業中 (承諾済)</span></p>
                
                <div class="cad-files-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; font-size:13px;">
                    <strong>📂 CADデータダウンロード:</strong>
                    <?php if (count($cad_files) > 0): ?>
                        <ul style="margin:5px 0 0 0; padding-left:20px;">
                            <?php foreach ($cad_files as $file): 
                                $download_url = htmlspecialchars($file['drive_file_id'], ENT_QUOTES);
                                if (strpos($file['drive_file_id'], 'uploads/') !== 0 && !empty($file['drive_file_id'])) {
                                    $download_url = 'https://drive.google.com/file/d/' . htmlspecialchars($file['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk';
                                }
                            ?>
                                <li style="margin-bottom:3px;">
                                    <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">
                                        📄 <?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?> <span class="badge" style="background:#555; color:white; font-size:10px; padding:1px 4px; border-radius:3px;">V<?= $file['version'] ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span style="color:#999; font-size:12px; margin-left:10px;">登録されているCADデータはありません。</span>
                    <?php endif; ?>
                </div>

                <div class="delivery-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; font-size:13px;">
                    <strong>📤 成果物（作成した図面）の納品:</strong>
                    <form method="POST" enctype="multipart/form-data" style="margin-top:5px; display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="action" value="deliver_task">
                        <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                        <input type="file" name="delivery_file" required style="font-size:12px;">
                        <button type="submit" style="background:#28a745; color:white; border:none; padding:4px 10px; border-radius:3px; font-size:12px; cursor:pointer;">納品する</button>
                    </form>
                </div>
            <?php else: ?>
                <?php 
                    $badge_bg = '#6c757d'; 
                    $status_label = $task['status'];
                    if ($task['status'] === 'delivered') {
                        $badge_bg = '#fd7e14'; 
                        $status_label = '納品済 (確認待ち)';
                    } elseif ($task['status'] === 'completed') {
                        $badge_bg = '#28a745'; 
                        $status_label = '完了 (確認済)';
                    }
                ?>
                <p>状態: <span class="badge" style="background:<?= $badge_bg ?>; color:white; padding:3px 8px; border-radius:4px;"><?= htmlspecialchars($status_label, ENT_QUOTES) ?></span></p>
                
                <div class="cad-files-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; font-size:13px;">
                    <strong>📂 CADデータダウンロード:</strong>
                    <?php if (count($cad_files) > 0): ?>
                        <ul style="margin:5px 0 0 0; padding-left:20px;">
                            <?php foreach ($cad_files as $file): 
                                $download_url = htmlspecialchars($file['drive_file_id'], ENT_QUOTES);
                                if (strpos($file['drive_file_id'], 'uploads/') !== 0 && !empty($file['drive_file_id'])) {
                                    $download_url = 'https://drive.google.com/file/d/' . htmlspecialchars($file['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk';
                                }
                            ?>
                                <li style="margin-bottom:3px;">
                                    <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">
                                        📄 <?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?> <span class="badge" style="background:#555; color:white; font-size:10px; padding:1px 4px; border-radius:3px;">V<?= $file['version'] ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span style="color:#999; font-size:12px; margin-left:10px;">登録されているCADデータはありません。</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>