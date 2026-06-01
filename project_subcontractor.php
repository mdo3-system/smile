<?php
// project_subcontractor.php
require_once 'auth.php';
check_auth(['admin', 'subcontractor']);

// セッションからログイン中のユーザー情報を取得
$user_id = $_SESSION['user_id']; 
$is_admin = ($_SESSION['role'] === 'admin');

// 承諾処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && !isset($_POST['action'])) {
    $order_id = intval($_POST['order_id']);
    $stmt = $pdo->prepare("UPDATE subcontractor_orders SET status = 'accepted' WHERE id = :id AND subcontractor_id = :sub_id");
    $stmt->execute(['id' => $order_id, 'sub_id' => $user_id]);
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
            $stmtOrder->execute(['id' => $order_id, 'sub_id' => $user_id]);
            
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

// 自分の担当案件リストを取得（業者の場合）
$my_tasks = [];
if (!$is_admin) {
    $stmt = $pdo->prepare("
        SELECT o.*, p.project_name, p.status AS project_status 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id 
        ORDER BY o.created_at DESC
    ");
    $stmt->execute(['sub_id' => $user_id]);
    $my_tasks = $stmt->fetchAll();
}

// 管理者の場合、対象プロジェクトの情報と業者リストを取得
$project_id = 0;
$project_info = null;
$subcontractors = [];
$admin_orders = [];
if ($is_admin) {
    $project_id = intval($_GET['id'] ?? 0);
    if ($project_id > 0) {
        $stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
        $stmtProj->execute(['id' => $project_id]);
        $project_info = $stmtProj->fetch();

        // 業者リストを取得
        $stmtSub = $pdo->prepare("SELECT id, contact_name FROM users WHERE role = 'subcontractor'");
        $stmtSub->execute();
        $subcontractors = $stmtSub->fetchAll();

        // この案件の発注履歴を取得
        $stmtOrd = $pdo->prepare("
            SELECT o.*, u.contact_name 
            FROM subcontractor_orders o 
            JOIN users u ON o.subcontractor_id = u.id 
            WHERE o.project_id = :pid 
            ORDER BY o.created_at DESC
        ");
        $stmtOrd->execute(['pid' => $project_id]);
        $admin_orders = $stmtOrd->fetchAll();
    }
}
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
    <?php if ($is_admin && $project_info): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #ccc; padding-bottom:10px; margin-bottom:20px;">
            <h1 style="margin:0; font-size:24px;">🏢 協力業者への発注・管理ダッシュボード</h1>
            <div style="font-size:14px;">
                案件名: <strong><?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?></strong>
                <a href="project_detail.php?id=<?= $project_id ?>" style="margin-left:15px; color:#3b82f6; text-decoration:none; font-weight:bold;">⬅ メイン画面へ戻る</a>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- 発注フォーム -->
            <div class="task-card">
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px;">🤝 新規発注（自動算出）</h2>
                <form action="project_detail_post.php" method="POST">
                    <input type="hidden" name="action" value="order_subcontractor">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    
                    <div style="margin-bottom:10px;">
                        <label style="font-size:14px;">
                            <input type="radio" name="order_type" value="design" checked onchange="calcSubcontractorEstimate()"> 構造用・外皮用意匠図作図
                        </label><br>
                        <label style="font-size:14px;">
                            <input type="radio" name="order_type" value="structure" onchange="calcSubcontractorEstimate()"> 構造図作図
                        </label>
                    </div>

                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">
                        <input type="number" id="sub_area" name="floor_area" placeholder="床面積(㎡)" style="width:100px; font-size:14px; padding:5px;" oninput="calcSubcontractorEstimate()" step="0.01">
                        <span style="font-size:14px;">㎡</span>
                    </div>

                    <div id="struct_options" style="display:none; margin-bottom:10px; font-size:14px; border:1px solid #ccc; padding:10px; background:#fff9f0;">
                        <label><input type="checkbox" name="opt_kiso" id="opt_kiso" onchange="calcSubcontractorEstimate()"> 基礎伏図 凡例・断面図 (+1,000円)</label><br>
                        <label><input type="checkbox" name="opt_yuka" id="opt_yuka" onchange="calcSubcontractorEstimate()"> 床小屋伏図 凡例 (+1,000円)</label>
                    </div>

                    <div id="sub_calc_result" style="margin-bottom:15px;"></div>
                    
                    <select name="subcontractor_id" style="width:100%; margin-bottom:10px; font-size:14px; padding:5px;" required>
                        <option value="">発注先を選択</option>
                        <?php foreach($subcontractors as $sub): ?>
                            <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['contact_name'], ENT_QUOTES) ?> 様</option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="text" name="task_title" placeholder="依頼内容（自動入力）" style="width:100%; margin-bottom:10px; font-size:14px; padding:5px; box-sizing:border-box;" readonly required>
                    <input type="number" name="order_amount" placeholder="金額(税込) 自動入力" style="width:100%; margin-bottom:15px; font-size:14px; padding:5px; box-sizing:border-box;" readonly required>
                    
                    <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:10px; font-size:16px; font-weight:bold; cursor:pointer; border-radius:4px;" onclick="return confirm('発注してよろしいですか？（納期は3日後に自動設定されます）')">発注を確定・送信</button>
                </form>

                <script>
                function calcSubcontractorEstimate() {
                    const type = document.querySelector('input[name="order_type"]:checked').value;
                    const area = parseFloat(document.getElementById('sub_area').value) || 0;
                    const structOpts = document.getElementById('struct_options');
                    
                    let total = 0;
                    let taskTitle = "";
                    
                    if (type === 'design') {
                        structOpts.style.display = 'none';
                        taskTitle = "構造用・外皮用意匠図作図";
                        if (area > 200) {
                            total = 50 * 100 + 40 * 100 + 30 * (area - 200);
                        } else if (area > 100) {
                            total = 50 * 100 + 40 * (area - 100);
                        } else {
                            total = 50 * area;
                        }
                    } else {
                        structOpts.style.display = 'block';
                        taskTitle = "構造図作図";
                        if (area > 200) {
                            total = 60 * 100 + 50 * 100 + 40 * (area - 200);
                        } else if (area > 100) {
                            total = 60 * 100 + 50 * (area - 100);
                        } else {
                            total = 60 * area;
                        }
                        
                        if (document.getElementById('opt_kiso').checked) total += 1000;
                        if (document.getElementById('opt_yuka').checked) total += 1000;
                    }
                    
                    total = Math.floor(total);

                    if (area > 0) {
                        let formulaText = "";
                        if (type === 'design') {
                            if (area > 200) formulaText = `(50円×100㎡ + 40円×100㎡ + 30円×${area - 200}㎡)`;
                            else if (area > 100) formulaText = `(50円×100㎡ + 40円×${area - 100}㎡)`;
                            else formulaText = `(50円×${area}㎡)`;
                        } else {
                            if (area > 200) formulaText = `(60円×100㎡ + 50円×100㎡ + 40円×${area - 200}㎡)`;
                            else if (area > 100) formulaText = `(60円×100㎡ + 50円×${area - 100}㎡)`;
                            else formulaText = `(60円×${area}㎡)`;
                        }
                        if (type === 'structure') {
                            let optAmount = 0;
                            if (document.getElementById('opt_kiso').checked) optAmount += 1000;
                            if (document.getElementById('opt_yuka').checked) optAmount += 1000;
                            if (optAmount > 0) formulaText += ` + オプション: ${optAmount}円`;
                        }
                        
                        document.getElementById('sub_calc_result').innerHTML = 
                            `<span style="color:#28a745;font-size:14px;font-weight:bold;">算出額: ${total.toLocaleString()}円</span><br>` + 
                            `<span style="color:#666;font-size:12px;">計算式: ${formulaText}</span>`;
                    } else {
                        document.getElementById('sub_calc_result').innerHTML = '';
                    }
                    
                    document.querySelector('input[name="order_amount"]').value = total;
                    document.querySelector('input[name="task_title"]').value = taskTitle;
                }
                </script>
            </div>

            <!-- 発注履歴 -->
            <div class="task-card">
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px;">📋 発注履歴・ステータス</h2>
                <?php if (empty($admin_orders)): ?>
                    <p style="color:#666;">まだ発注履歴はありません。</p>
                <?php else: ?>
                    <?php foreach($admin_orders as $o): ?>
                        <div style="padding:10px 0; border-bottom:1px solid #eee;">
                            <div style="font-weight:bold; margin-bottom:5px;">
                                <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?> 様宛
                                <span class="badge" style="background:#555; color:white; padding:3px 6px; border-radius:3px; font-size:12px; margin-left:10px;"><?= htmlspecialchars($o['status'], ENT_QUOTES) ?></span>
                            </div>
                            <div style="font-size:13px; color:#444;">
                                依頼内容: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?><br>
                                発注額: <?= number_format($o['order_amount']) ?>円<br>
                                発注日: <?= date('Y-m-d H:i', strtotime($o['created_at'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #ccc; padding-bottom:10px; margin-bottom:20px;">
            <h1 style="margin:0; font-size:24px;">👷 協力業者専用ダッシュボード</h1>
            <div style="font-size:14px;">
                ログイン中: <strong><?= htmlspecialchars($_SESSION['contact_name'], ENT_QUOTES) ?></strong> 様
                <a href="logout.php" style="margin-left:15px; color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
            </div>
        </div>
        <?php foreach ($my_tasks as $task): 
            // 該当案件の最新かつ「業者公開済み」のCADファイルを取得
            $stmtFiles = $pdo->prepare("
                SELECT * FROM project_files 
                WHERE project_id = :project_id 
                AND file_category LIKE 'cad_%' 
                AND is_latest = 1 
                AND is_published_to_sub = 1
            ");
            $stmtFiles->execute(['project_id' => $task['project_id']]);
            $cad_files = $stmtFiles->fetchAll();
        ?>
            <div class="task-card">
                <h3>案件名: <?= htmlspecialchars($task['project_name'], ENT_QUOTES) ?></h3>
                <p>依頼内容: <?= htmlspecialchars($task['task_title'], ENT_QUOTES) ?></p>
                <p>報酬額: <strong><?= number_format($task['order_amount']) ?>円</strong></p>

                <!-- CADデータ表示セクション (常に表示) -->
                <div class="cad-files-section" style="margin:15px 0; border:1px solid #cce5ff; background:#e6f2ff; padding:10px; border-radius:5px; font-size:13px;">
                    <strong style="color:#004085;">📂 共有されたCADデータ:</strong>
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
                        <div style="color:#856404; font-size:12px; margin-top:5px;">現在共有されているCADデータはありません。（管理者が公開するとここに表示されます）</div>
                    <?php endif; ?>
                </div>
                
                <?php if ($task['status'] === 'requested'): ?>
                    <form method="POST">
                        <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                        <button type="submit" class="btn-accept">この依頼を承諾する</button>
                    </form>
                <?php elseif ($task['status'] === 'accepted'): ?>
                    <p>状態: <span class="badge" style="background:#007bff; color:white; padding:3px 8px; border-radius:4px;">作業中 (承諾済)</span></p>

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
    <?php endif; ?>
</body>
</html>