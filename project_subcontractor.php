<?php
// project_subcontractor.php
require_once 'auth.php';
require_once 'functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\SubcontractorOrderService;

check_auth(['admin', 'subcontractor']);

// セッションからログイン中のユーザー情報を取得
$user_id = $_SESSION['user_id']; 
$is_admin = ($_SESSION['role'] === 'admin');

$subcontractorOrderService = new SubcontractorOrderService($pdo);

// 承諾処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && !isset($_POST['action']) && isset($_POST['expected_delivery_date'])) {
    $order_id = intval($_POST['order_id']);
    $expected_delivery_date = $_POST['expected_delivery_date'];
    
    $subcontractorOrderService->acceptOrder($order_id, $user_id, $expected_delivery_date);

    header("Location: project_subcontractor.php");
    exit;
}

// 拒否処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['action']) && $_POST['action'] === 'reject_order') {
    $order_id = intval($_POST['order_id']);
    
    $subcontractorOrderService->rejectOrder($order_id, $user_id);

    header("Location: subcontractor_portal.php");
    exit;
}

// 納品処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deliver_task') {
    $order_id = intval($_POST['order_id']);
    $project_id = intval($_POST['project_id']);
    
    require_once 'google_drive_client.php';
    
    try {
        $pdo->beginTransaction();
        
        $files_to_upload = [
            'architrend_design' => 'sub_architrend_design',
            'architrend_struct' => 'sub_architrend_struct',
            'structural_pdf'  => 'sub_structural_pdf'
        ];
        
        $uploaded_any = false;
        
        foreach ($files_to_upload as $input_name => $category) {
            if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES[$input_name]['tmp_name'];
                $file_name = $_FILES[$input_name]['name'];
                $mime_type = $_FILES[$input_name]['type'];
                
                $drive_file_id = upload_to_google_drive($file_tmp, $file_name, $mime_type);
                
                // 1. 最新バージョンの確認
                $stmtVer = $pdo->prepare("SELECT MAX(version) as max_v FROM project_files WHERE project_id = :pid AND file_category = :cat");
                $stmtVer->execute(['pid' => $project_id, 'cat' => $category]);
                $max_v = $stmtVer->fetch()['max_v'] ?? 0;
                $new_v = $max_v + 1;
                
                // 2. 過去のファイルの is_latest を 0 に更新
                $stmtUpdateLatest = $pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
                $stmtUpdateLatest->execute(['pid' => $project_id, 'cat' => $category]);
                
                // 3. 新しいファイルを登録 (これらは管理者と業者の間のみで表示される)
                $stmtInsertFile = $pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :cat, :fname, :fpath, :ver, 1)
                ");
                $stmtInsertFile->execute([
                    'pid' => $project_id,
                    'cat' => $category,
                    'fname' => $file_name,
                    'fpath' => $drive_file_id,
                    'ver' => $new_v
                ]);
                $uploaded_any = true;
            }
        }
        
        if ($uploaded_any) {
            // 発注ステータスを delivered (納品済) に更新
            $stmtOrder = $pdo->prepare("UPDATE subcontractor_orders SET status = 'delivered', updated_at = NOW() WHERE id = :id AND subcontractor_id = :sub_id");
            $stmtOrder->execute(['id' => $order_id, 'sub_id' => $user_id]);
            $pdo->commit();
        } else {
            $pdo->rollBack();
            die("ファイルが選択されていません。");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("納品処理に失敗しました: " . $e->getMessage());
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
            <div style="font-size:14px; display:flex; align-items:center; gap:15px;">
                <span>案件名: <strong><?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?></strong></span>
                <span style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '' ?></span>
                <a href="project_detail.php?id=<?= $project_id ?>" style="color:#3b82f6; text-decoration:none; font-weight:bold;">⬅ メイン画面へ戻る</a>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- 発注フォーム -->
            <div class="task-card">
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px;">🤝 新規発注（自動算出）</h2>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
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
                    
                    <div style="margin-bottom:15px; display:flex; align-items:center; gap:10px;">
                        <label style="font-size:14px; font-weight:bold; width:100px;">希望納品日:</label>
                        <input type="date" name="due_date" required style="flex:1; padding:5px; font-size:14px; border:1px solid #ccc; border-radius:3px;">
                    </div>

                    <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:10px; font-size:16px; font-weight:bold; cursor:pointer; border-radius:4px;" onclick="return confirm('発注してよろしいですか？')">発注を確定・送信</button>
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
                    <?php foreach($admin_orders as $o): 
                        $badge_bg = '#6c757d'; 
                        $status_label = $o['status'];
                        if ($o['status'] === 'requested') {
                            $badge_bg = '#ffc107'; $status_label = '発注済 (未承諾)';
                        } elseif ($o['status'] === 'accepted') {
                            $badge_bg = '#007bff'; $status_label = '作業中 (承諾済)';
                        } elseif ($o['status'] === 'delivered') {
                            $badge_bg = '#fd7e14'; $status_label = '納品済 (確認待ち)';
                        } elseif ($o['status'] === 'completed') {
                            $badge_bg = '#28a745'; $status_label = '完了 (確認済)';
                        }
                    ?>
                        <div style="padding:10px 0; border-bottom:1px solid #eee;">
                            <div style="font-weight:bold; margin-bottom:5px;">
                                <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?> 様宛
                                <span class="badge" style="background:<?= $badge_bg ?>; color:white; padding:3px 6px; border-radius:3px; font-size:12px; margin-left:10px;"><?= htmlspecialchars($status_label, ENT_QUOTES) ?></span>
                            </div>
                            <div style="font-size:13px; color:#444;">
                                依頼内容: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?><br>
                                発注額: <?= number_format($o['order_amount']) ?>円<br>
                                発注日: <?= date('Y-m-d H:i', strtotime($o['created_at'])) ?><br>
                                希望納品日: <?= !empty($o['due_date']) ? date('Y年m月d日', strtotime($o['due_date'])) : '未設定' ?><br>
                                完了予定日 (業者回答): <?= !empty($o['expected_delivery_date']) ? '<strong style="color:#e67e22;">'.date('Y年m月d日', strtotime($o['expected_delivery_date'])).'</strong>' : '<span style="color:#999;">未定</span>' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 管理者用 案件別チャットUI -->
            <div class="task-card">
                <?php
                    // このプロジェクトのチャット履歴を取得
                    $stmtChatAdmin = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'sub_admin' ORDER BY id ASC");
                    $stmtChatAdmin->execute(['pid' => $project_id]);
                    $admin_msgs = $stmtChatAdmin->fetchAll();
                ?>
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px;">💬 協力業者連絡チャット</h2>
                <div style="background:#fdf6e3; border:1px solid #e2e8f0; border-radius:8px; display:flex; flex-direction:column; height:400px;">
                    <div style="flex:1; overflow-y:auto; padding:10px; display:flex; flex-direction:column; gap:8px;" id="chatList_<?= $project_id ?>">
                        <?php foreach ($admin_msgs as $msg): 
                            $isMe = ($msg['sender_id'] == $_SESSION['user_id'] || $msg['sender_id'] == 1);
                            $bubbleBg = $isMe ? '#dcf8c6' : '#dbeafe';
                            $align = $isMe ? 'flex-end' : 'flex-start';
                            
                            $senderName = $isMe ? 'あなた (管理者)' : '協力業者';
                        ?>
                            <div style="display:flex; flex-direction:column; align-items:<?= $align ?>;">
                                <span style="font-size:10px; color:#666; margin-bottom:2px;"><?= $senderName ?> (<?= date('m/d H:i', strtotime($msg['created_at'])) ?>)</span>
                                <?php if (!empty($msg['message_text'])): ?>
                                    <div style="background:<?= $bubbleBg ?>; padding:8px 12px; border-radius:12px; font-size:13px; max-width:80%; white-space:pre-wrap; word-break:break-word;"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($msg['file_path'])): 
                                    $furl = (strpos($msg['file_path'], 'uploads/') !== 0 && strlen($msg['file_path']) > 15 && strpos($msg['file_path'], '/') === false) 
                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($msg['file_path'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                        : htmlspecialchars($msg['file_path'], ENT_QUOTES);
                                ?>
                                    <div style="background:<?= $bubbleBg ?>; padding:5px 10px; border-radius:8px; font-size:12px; margin-top:4px;">
                                        <a href="<?= $furl ?>" target="_blank" style="color:#0056b3; text-decoration:none;">
                                            <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $msg['file_path'])) echo "🖼 画像を見る"; else echo "📄 添付ファイルを見る"; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($admin_msgs)): ?>
                            <div style="text-align:center; color:#aaa; font-size:12px; margin-top:20px;">まだメッセージはありません。</div>
                        <?php endif; ?>
                    </div>
                    <div style="background:#fff; border-top:1px solid #e2e8f0; padding:10px; border-radius:0 0 8px 8px; display:flex; gap:10px; align-items:center;">
                        <input type="file" id="chatFile_<?= $project_id ?>" accept="image/*,.pdf" style="display:none;" onchange="document.getElementById('fileLabel_<?= $project_id ?>').style.color='#28a745'">
                        <label for="chatFile_<?= $project_id ?>" id="fileLabel_<?= $project_id ?>" style="cursor:pointer; font-size:18px; color:#6c757d;" title="ファイルを添付">📎</label>
                        
                        <textarea id="chatText_<?= $project_id ?>" style="flex:1; border:1px solid #ccc; border-radius:20px; padding:8px 12px; font-size:13px; resize:none;" rows="1" placeholder="メッセージを入力..."></textarea>
                        
                        <button onclick="sendProjMessage(<?= $project_id ?>)" style="background:#3b82f6; color:white; border:none; border-radius:50%; width:36px; height:36px; cursor:pointer; font-size:16px;">➤</button>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #ccc; padding-bottom:10px; margin-bottom:20px;">
            <div style="display:flex; align-items:center; gap:15px;">
                <h1 style="margin:0; font-size:24px;">👷 協力業者専用ダッシュボード</h1>
                <a href="subcontractor_portal.php" style="background:#e2e8f0; color:#333; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:13px; font-weight:bold;">⬅ 一覧画面に戻る</a>
            </div>
            <div style="font-size:14px; display:flex; align-items:center; gap:15px;">
                <span>ログイン中: <strong><?= htmlspecialchars($_SESSION['contact_name'], ENT_QUOTES) ?></strong> 様</span>
                <span style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '' ?></span>
                <a href="logout.php" style="color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
            </div>
        </div>
        <?php foreach ($my_tasks as $task): 
            // 該当案件の最新CADファイルを取得 (承諾前[requested]または承諾済[accepted]の期間は一時的に強制表示)
            if ($task['status'] === 'requested' || $task['status'] === 'accepted') {
                $stmtFiles = $pdo->prepare("
                    SELECT * FROM project_files 
                    WHERE project_id = :project_id 
                    AND file_category LIKE 'cad_%' 
                    AND is_latest = 1
                ");
            } else {
                $stmtFiles = $pdo->prepare("
                    SELECT * FROM project_files 
                    WHERE project_id = :project_id 
                    AND file_category LIKE 'cad_%' 
                    AND is_latest = 1 
                    AND is_published_to_sub = 1
                ");
            }
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
                    <form method="POST" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px;">
                        <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                        <div style="margin-bottom:10px;">
                            <label style="font-weight:bold; font-size:13px; color:#e67e22;">完了納期予定日を設定:</label>
                            <input type="date" name="expected_delivery_date" required style="padding:4px; border:1px solid #ccc; border-radius:3px;">
                        </div>
                        <button type="submit" class="btn-accept">納期を入力して承諾する</button>
                    </form>
                <?php else: ?>
                    <?php 
                        $badge_bg = '#6c757d'; 
                        $status_label = $task['status'];
                        if ($task['status'] === 'accepted') {
                            $badge_bg = '#007bff';
                            $status_label = '作業中 (承諾済)';
                        } elseif ($task['status'] === 'delivered') {
                            $badge_bg = '#fd7e14'; 
                            $status_label = '納品済 (確認待ち)';
                        } elseif ($task['status'] === 'completed') {
                            $badge_bg = '#28a745'; 
                            $status_label = '完了 (確認済)';
                        }
                    ?>
                    <p>状態: <span class="badge" style="background:<?= $badge_bg ?>; color:white; padding:3px 8px; border-radius:4px;"><?= htmlspecialchars($status_label, ENT_QUOTES) ?></span></p>
                    <p style="font-size:13px; color:#555;">完了納期予定日: <strong><?= !empty($task['expected_delivery_date']) ? date('Y年m月d日', strtotime($task['expected_delivery_date'])) : '未設定' ?></strong></p>

                    <div class="delivery-section" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:10px; font-size:13px;">
                        <strong>📤 成果物（作成した図面）の納品・差し替え:</strong>
                        <p style="font-size:11px; color:#666; margin:4px 0;">※個別にアップロード可能です。差し替えた場合も履歴が残ります。</p>
                        <form method="POST" enctype="multipart/form-data" style="margin-top:10px; display:flex; flex-direction:column; gap:10px; background:#fff; padding:10px; border:1px solid #eee; border-radius:5px;">
                            <input type="hidden" name="action" value="deliver_task">
                            <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                            <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                            
                            <div style="display:flex; align-items:center; gap:10px;">
                                <label style="width:160px; font-weight:bold; color:#0056b3;">意匠図用アーキデータ:</label>
                                <input type="file" name="architrend_design" style="font-size:12px;">
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <label style="width:160px; font-weight:bold; color:#0056b3;">構造図用アーキデータ:</label>
                                <input type="file" name="architrend_struct" style="font-size:12px;">
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <label style="width:160px; font-weight:bold; color:#dc3545;">構造図PDF (依頼主公開):</label>
                                <input type="file" name="structural_pdf" style="font-size:12px;">
                            </div>
                            <div style="margin-top:5px;">
                                <button type="submit" style="background:#28a745; color:white; border:none; padding:6px 15px; border-radius:3px; font-size:13px; font-weight:bold; cursor:pointer;">選択したファイルを納品・差し替えする</button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="history-section" style="margin-top:15px; font-size:12px;">
                        <strong>📜 納品履歴:</strong>
                        <?php
                            $stmtHist = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category IN ('sub_architrend_design', 'sub_architrend_struct', 'sub_structural_pdf') ORDER BY created_at DESC");
                            $stmtHist->execute(['pid' => $task['project_id']]);
                            $hist_files = $stmtHist->fetchAll();
                            
                            if (count($hist_files) > 0):
                        ?>
                            <ul style="margin:5px 0 0 0; padding-left:20px; color:#555;">
                                <?php foreach ($hist_files as $hf): 
                                    $hurl = htmlspecialchars($hf['drive_file_id'], ENT_QUOTES);
                                    if (strpos($hf['drive_file_id'], 'uploads/') !== 0 && !empty($hf['drive_file_id'])) {
                                        $hurl = 'https://drive.google.com/file/d/' . htmlspecialchars($hf['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk';
                                    }
                                    $lbl = 'ファイル';
                                    if ($hf['file_category'] === 'sub_architrend_design') $lbl = '意匠用アーキ';
                                    if ($hf['file_category'] === 'sub_architrend_struct') $lbl = '構造用アーキ';
                                    if ($hf['file_category'] === 'sub_structural_pdf') $lbl = '構造図PDF';
                                ?>
                                    <li style="margin-bottom:3px;">
                                        [<?= $lbl ?>] <a href="<?= $hurl ?>" target="_blank" style="color:#0056b3; text-decoration:none;"><?= htmlspecialchars($hf['file_name'], ENT_QUOTES) ?></a> 
                                        <span style="font-size:10px; color:#999;">(V<?= $hf['version'] ?>) - <?= date('m/d H:i', strtotime($hf['created_at'])) ?></span>
                                        <?php if ($hf['is_latest']): ?>
                                            <span style="background:#17a2b8; color:white; padding:1px 4px; border-radius:3px; font-size:9px;">最新</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="color:#aaa;">まだ納品されていません。</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- サブコントラクター用 案件別チャットUI -->
                <?php
                    // このプロジェクトのチャット履歴を取得
                    $stmtChat = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'sub_admin' ORDER BY id ASC");
                    $stmtChat->execute(['pid' => $task['project_id']]);
                    $sub_msgs = $stmtChat->fetchAll();
                ?>
                <div style="margin-top:20px; border-top:2px solid #ccc; padding-top:15px;">
                    <h4 style="margin:0 0 10px 0; color:#d97706;">💬 この案件の連絡・質疑チャット</h4>
                    <div style="background:#fdf6e3; border:1px solid #e2e8f0; border-radius:8px; display:flex; flex-direction:column; height:300px;">
                        <div style="flex:1; overflow-y:auto; padding:10px; display:flex; flex-direction:column; gap:8px;" id="chatList_<?= $task['project_id'] ?>">
                            <?php foreach ($sub_msgs as $msg): 
                                $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                                $bubbleBg = $isMe ? '#dcf8c6' : '#dbeafe';
                                $align = $isMe ? 'flex-end' : 'flex-start';
                                $sender = $isMe ? 'あなた' : '管理者';
                            ?>
                                <div style="display:flex; flex-direction:column; align-items:<?= $align ?>;">
                                    <span style="font-size:10px; color:#666; margin-bottom:2px;"><?= $sender ?> (<?= date('m/d H:i', strtotime($msg['created_at'])) ?>)</span>
                                    <?php if (!empty($msg['message_text'])): ?>
                                        <div style="background:<?= $bubbleBg ?>; padding:8px 12px; border-radius:12px; font-size:13px; max-width:80%; white-space:pre-wrap; word-break:break-word;"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($msg['file_path'])): 
                                        $furl = (strpos($msg['file_path'], 'uploads/') !== 0 && strlen($msg['file_path']) > 15 && strpos($msg['file_path'], '/') === false) 
                                            ? 'https://drive.google.com/file/d/' . htmlspecialchars($msg['file_path'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                            : htmlspecialchars($msg['file_path'], ENT_QUOTES);
                                    ?>
                                        <div style="background:<?= $bubbleBg ?>; padding:5px 10px; border-radius:8px; font-size:12px; margin-top:4px;">
                                            <a href="<?= $furl ?>" target="_blank" style="color:#0056b3; text-decoration:none;">
                                                <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $msg['file_path'])) echo "🖼 画像を見る"; else echo "📄 添付ファイルを見る"; ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="background:#fff; border-top:1px solid #e2e8f0; padding:10px; border-radius:0 0 8px 8px; display:flex; gap:10px; align-items:center;">
                            <input type="file" id="chatFile_<?= $task['project_id'] ?>" accept="image/*,.pdf" style="display:none;" onchange="document.getElementById('fileLabel_<?= $task['project_id'] ?>').style.color='#28a745'">
                            <label for="chatFile_<?= $task['project_id'] ?>" id="fileLabel_<?= $task['project_id'] ?>" style="cursor:pointer; font-size:18px; color:#6c757d;" title="ファイルを添付">📎</label>
                            
                            <textarea id="chatText_<?= $task['project_id'] ?>" style="flex:1; border:1px solid #ccc; border-radius:20px; padding:8px 12px; font-size:13px; resize:none;" rows="1" placeholder="メッセージを入力..."></textarea>
                            
                            <button onclick="sendProjMessage(<?= $task['project_id'] ?>)" style="background:#3b82f6; color:white; border:none; border-radius:50%; width:36px; height:36px; cursor:pointer; font-size:16px;">➤</button>
                        </div>
                    </div>
                </div>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
    function sendProjMessage(projectId) {
        const textEl = document.getElementById('chatText_' + projectId);
        const fileEl = document.getElementById('chatFile_' + projectId);
        const msg = textEl.value.trim();
        
        if (!msg && (!fileEl.files || fileEl.files.length === 0)) return;

        const formData = new FormData();
        formData.append('project_id', projectId);
        formData.append('action', 'send_message');
        formData.append('thread_type', 'sub_admin');
        formData.append('message_text', msg);
        if (fileEl.files && fileEl.files.length > 0) {
            formData.append('file', fileEl.files[0]);
        }

        fetch('api_send_message.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    textEl.value = '';
                    fileEl.value = '';
                    document.getElementById('fileLabel_' + projectId).style.color = '#6c757d';
                    window.location.reload();
                } else {
                    alert('送信エラー');
                }
            })
            .catch(e => {
                console.error(e);
                alert('通信エラー');
            });
    }
    
    // スクロールを最下部に
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[id^="chatList_"]').forEach(el => {
            el.scrollTop = el.scrollHeight;
        });
    });
    </script>
</body>
</html>