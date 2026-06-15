<?php
// project_subcontractor.php
require_once 'auth.php';
require_once 'functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use App\Services\SubcontractorOrderService;

check_auth(['admin', 'subcontractor', 'accountant']);

// セッションからログイン中のユーザー情報を取得
$user_id = $_SESSION['user_id']; 
$is_admin = in_array($_SESSION['role'], ['admin', 'accountant']);

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

// キャンセル処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['action']) && $_POST['action'] === 'cancel_order' && $is_admin) {
    $order_id = intval($_POST['order_id']);
    $pid = intval($_POST['project_id'] ?? 0);
    
    $subcontractorOrderService->cancelOrder($order_id, $user_id);

    header("Location: project_subcontractor.php?id=" . $pid . "&t=" . time());
    exit;
}

// 公開・非表示の切り替え処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_publish_sub' && $is_admin) {
    $file_id = intval($_POST['file_id'] ?? 0);
    $publish_val = intval($_POST['publish_val'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    if ($file_id > 0 && $project_id > 0) {
        $stmt = $pdo->prepare("UPDATE project_files SET is_published_to_sub = :pub WHERE id = :id AND project_id = :pid");
        $stmt->execute(['pub' => $publish_val, 'id' => $file_id, 'pid' => $project_id]);
    }
    header("Location: project_subcontractor.php?id=" . $project_id . "&t=" . time());
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

            // 協力業者から管理者への納品報告チャットを自動登録
            $stmtGetSubName = $pdo->prepare("SELECT contact_name FROM users WHERE id = :uid");
            $stmtGetSubName->execute(['uid' => $user_id]);
            $sub_name = $stmtGetSubName->fetchColumn() ?: '協力業者';

            $notify_msg = "【自動通知】{$sub_name} 様より成果物の納品（ファイルアップロード）が行われました。\n";
            $notify_msg .= "管理者画面にて内容をご確認の上、承認（クライアントへの公開）処理を行ってください。";

            $stmtChat = $pdo->prepare("
                INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                VALUES (:pid, :sid, 'sub_admin', :msg)
            ");
            $stmtChat->execute([
                'pid' => $project_id,
                'sid' => $user_id,
                'msg' => $notify_msg
            ]);

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

// 自分の担当発注タスクリストを取得・プロジェクトごとにグループ化（業者の場合）
$my_projects = [];
if (!$is_admin) {
    $stmt = $pdo->prepare("
        SELECT o.*, p.project_name, p.status AS project_status 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id 
        ORDER BY o.created_at DESC
    ");
    $stmt->execute(['sub_id' => $user_id]);
    $orders = $stmt->fetchAll();
    
    foreach ($orders as $order) {
        $pid = $order['project_id'];
        if (!isset($my_projects[$pid])) {
            $my_projects[$pid] = [
                'project_id' => $pid,
                'project_name' => $order['project_name'],
                'project_status' => $order['project_status'],
                'tasks' => []
            ];
        }
        $my_projects[$pid]['tasks'][] = $order;
    }
}

// 管理者の場合、対象プロジェクトの情報と業者リストを取得
$project_id = 0;
$project_info = null;
$subcontractors = [];
$admin_orders = [];
$default_floor_area = ''; // 意匠図作図依頼からの引き継ぎ面積
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

        // 意匠図作図依頼（order_type = 'design'）から最も新しい床面積を取得
        $stmtArea = $pdo->prepare("
            SELECT floor_area 
            FROM subcontractor_orders 
            WHERE project_id = :pid AND order_type = 'design' AND status != 'cancelled'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtArea->execute(['pid' => $project_id]);
        $default_floor_area = $stmtArea->fetchColumn();

        // この案件の発注履歴を取得（納品ファイルも結合）
        $stmtOrd = $pdo->prepare("
            SELECT o.*, u.contact_name,
                   f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
                   f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
                   f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
            FROM subcontractor_orders o 
            JOIN users u ON o.subcontractor_id = u.id 
            LEFT JOIN project_files f1 ON o.project_id = f1.project_id AND f1.file_category = 'sub_structural_pdf' AND f1.is_latest = 1
            LEFT JOIN project_files f2 ON o.project_id = f2.project_id AND f2.file_category = 'sub_architrend_design' AND f2.is_latest = 1
            LEFT JOIN project_files f3 ON o.project_id = f3.project_id AND f3.file_category = 'sub_architrend_struct' AND f3.is_latest = 1
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
            <h1 style="margin:0; font-size:24px;">🏢 協力業者への発注依頼・管理ダッシュボード</h1>
            <div style="font-size:14px; display:flex; align-items:center; gap:15px;">
                <span>案件名: <strong><?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?></strong></span>
                <span style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '' ?></span>
                <a href="project_detail.php?id=<?= $project_id ?>" style="color:#3b82f6; text-decoration:none; font-weight:bold;">⬅ メイン画面へ戻る</a>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- 発注フォーム -->
            <div class="task-card">
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px;">🤝 新規発注依頼（自動算出）</h2>
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
                        <input type="number" id="sub_area" name="floor_area" placeholder="床面積(㎡)" value="<?= htmlspecialchars($default_floor_area, ENT_QUOTES) ?>" style="width:100px; font-size:14px; padding:5px;" oninput="calcSubcontractorEstimate()" step="0.01">
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

                    <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:10px; font-size:16px; font-weight:bold; cursor:pointer; border-radius:4px;" onclick="return confirm('発注依頼を送信してよろしいですか？')">発注依頼を送信</button>
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
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px;">📋 発注依頼履歴・ステータス</h2>
                <?php if (empty($admin_orders)): ?>
                    <p style="color:#666;">まだ発注依頼履歴はありません。</p>
                <?php else: ?>
                    <?php foreach($admin_orders as $o): 
                        $badge_bg = '#6c757d'; 
                        $status_label = $o['status'];
                        if ($o['status'] === 'requested') {
                            $badge_bg = '#ffc107'; $status_label = '依頼済 (未承諾)';
                        } elseif ($o['status'] === 'accepted') {
                            $badge_bg = '#007bff'; $status_label = '作業中 (承諾済)';
                        } elseif ($o['status'] === 'delivered') {
                            $badge_bg = '#fd7e14'; $status_label = '納品済 (確認待ち)';
                        } elseif ($o['status'] === 'completed') {
                            $badge_bg = '#28a745'; $status_label = '完了 (確認済)';
                        } elseif ($o['status'] === 'cancelled') {
                            $badge_bg = '#dc3545'; $status_label = 'キャンセル済';
                        }
                    ?>
                        <div style="padding:10px 0; border-bottom:1px solid #eee;">
                            <div style="font-weight:bold; margin-bottom:5px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <div>
                                    <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?> 様宛
                                    <span class="badge" style="background:<?= $badge_bg ?>; color:white; padding:3px 6px; border-radius:3px; font-size:12px; margin-left:10px;"><?= htmlspecialchars($status_label, ENT_QUOTES) ?></span>
                                </div>
                                <?php if ($is_admin && in_array($o['status'], ['requested', 'accepted'])): ?>
                                    <form action="project_subcontractor.php?id=<?= $project_id ?>" method="POST" onsubmit="return confirm('この発注をキャンセルしますか？\n（業者チャットへ自動通知されます）')" style="margin:0;">
                                        <input type="hidden" name="action" value="cancel_order">
                                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                        <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                        <button type="submit" style="background:#dc3545; color:white; border:none; padding:4px 10px; border-radius:3px; font-size:11px; font-weight:bold; cursor:pointer;">発注キャンセル</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:13px; color:#444; line-height:1.6;">
                                依頼内容: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?><br>
                                依頼額: <?= number_format($o['order_amount']) ?>円<br>
                                依頼日: <?= date('Y-m-d H:i', strtotime($o['created_at'])) ?><br>
                                希望納品日: <?= !empty($o['due_date']) ? date('Y年m月d日', strtotime($o['due_date'])) : '未設定' ?><br>
                                完了予定日 (業者回答): <?= !empty($o['expected_delivery_date']) ? '<strong style="color:#e67e22;">'.date('Y年m月d日', strtotime($o['expected_delivery_date'])).'</strong>' : '<span style="color:#999;">未定</span>' ?>
                                
                                <?php if (!empty($o['pdf_id']) || !empty($o['arc_d_id']) || !empty($o['arc_s_id'])): ?>
                                    <div style="margin-top:8px; padding:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                                        <strong style="color:#334155; font-size:12px;">📤 納品ファイル一覧:</strong>
                                        <ul style="margin:4px 0 0 0; padding-left:20px; font-size:12px;">
                                            <?php if (!empty($o['arc_d_id'])): 
                                                $d_url = (strpos($o['arc_d_id'], 'uploads/') === 0) ? $o['arc_d_id'] : 'https://drive.google.com/file/d/' . $o['arc_d_id'] . '/view?usp=drivesdk';
                                            ?>
                                                <li>意匠用アーキ: <a href="<?= htmlspecialchars($d_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['arc_d_name'], ENT_QUOTES) ?> (V<?= $o['arc_d_ver'] ?>)</a></li>
                                            <?php endif; ?>
                                            <?php if (!empty($o['arc_s_id'])): 
                                                $s_url = (strpos($o['arc_s_id'], 'uploads/') === 0) ? $o['arc_s_id'] : 'https://drive.google.com/file/d/' . $o['arc_s_id'] . '/view?usp=drivesdk';
                                            ?>
                                                <li>構造用アーキ: <a href="<?= htmlspecialchars($s_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['arc_s_name'], ENT_QUOTES) ?> (V<?= $o['arc_s_ver'] ?>)</a></li>
                                            <?php endif; ?>
                                            <?php if (!empty($o['pdf_id'])): 
                                                $pdf_url = (strpos($o['pdf_id'], 'uploads/') === 0) ? $o['pdf_id'] : 'https://drive.google.com/file/d/' . $o['pdf_id'] . '/view?usp=drivesdk';
                                                $is_published = ($o['status'] === 'completed');
                                            ?>
                                                <li>
                                                    構造図PDF: <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['pdf_name'], ENT_QUOTES) ?> (V<?= $o['pdf_ver'] ?>)</a>
                                                    <?php if ($is_published): ?>
                                                        <span class="badge" style="background:#28a745; color:white; font-size:10px; padding:2px 5px; border-radius:3px; margin-left:5px;">公開中</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background:#dc3545; color:white; font-size:10px; padding:2px 5px; border-radius:3px; margin-left:5px;">未公開</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endif; ?>
                                        </ul>

                                        <?php if ($o['status'] === 'delivered'): ?>
                                            <div style="margin-top:8px;">
                                                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                                                    <input type="hidden" name="action" value="approve_delivery">
                                                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                                    <div style="display:flex; align-items:center; gap:5px;">
                                                        <label style="font-size:11px; color:#555;">完了日を指定:</label>
                                                        <input type="date" name="completed_at" value="<?= date('Y-m-d') ?>" style="padding:2px 5px; font-size:12px; border:1px solid #ccc; border-radius:4px;" required>
                                                    </div>
                                                    <div style="display:flex; gap:5px;">
                                                        <button type="submit" style="background:#28a745; color:white; border:none; padding:5px 12px; border-radius:3px; font-size:12px; font-weight:bold; cursor:pointer; flex:1;" onclick="return confirm('納品タスクを完了（承認）しますか？')">納品完了</button>
                                                    </div>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 共通図書・CADデータの公開設定 -->
            <div class="task-card" style="border-left-color: #3b82f6;">
                <h2 style="margin-top:0; border-bottom:1px solid #ccc; padding-bottom:10px; color:#1e3a8a;">📂 共通図書・CADデータの業者公開設定</h2>
                <div style="font-size:12px; color:#555; margin-bottom:15px;">
                    依頼主から提出されたCADデータや共通図書を、協力業者ポータルに公開・非表示にする設定を行えます。<br>
                    <strong>初期状態はすべて非表示です。</strong>
                </div>
                <?php
                // 最新の共通図書・CADデータを取得
                $stmtClientFiles = $pdo->prepare("
                    SELECT * FROM project_files 
                    WHERE project_id = :pid 
                      AND file_category IN ('cad_layout', 'cad_plan_1f', 'cad_plan_2f', 'cad_plan_3f', 'cad_plan_ph', 'cad_plan_rf', 'cad_elevation', 'cad_section', 'app_doc', 'soil_report', 'soil_impr', 'pdf_precut')
                      AND is_latest = 1
                    ORDER BY id ASC
                ");
                $stmtClientFiles->execute(['pid' => $project_id]);
                $client_files = $stmtClientFiles->fetchAll();

                // カテゴリの日本語名マッピング
                $cat_names = [
                    'cad_layout'    => '配置図 (CAD)',
                    'cad_plan_1f'   => '1F平面図 (CAD)',
                    'cad_plan_2f'   => '2F平面図 (CAD)',
                    'cad_plan_3f'   => '3F平面図 (CAD)',
                    'cad_plan_ph'   => 'PH平面図 (CAD)',
                    'cad_plan_rf'   => 'RF平面図 (CAD)',
                    'cad_elevation' => '立面図 (CAD)',
                    'cad_section'   => '矩計図 (CAD)',
                    'app_doc'       => '確認申請書',
                    'soil_report'   => '地盤調査報告書',
                    'soil_impr'     => '地盤改良設計書',
                    'pdf_precut'    => 'プレカット図等',
                ];

                if (empty($client_files)):
                ?>
                    <p style="color:#666; font-size:13px;">アップロードされた共通図書・CADデータはありません。</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse; font-size:13px; line-height:1.5;">
                        <thead>
                            <tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;">
                                <th style="padding:6px; text-align:left;">図書類カテゴリ</th>
                                <th style="padding:6px; text-align:left;">ファイル名</th>
                                <th style="padding:6px; text-align:center; width:80px;">状態</th>
                                <th style="padding:6px; text-align:center; width:120px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($client_files as $idx => $f): 
                                $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8fafc';
                                $cat_label = $cat_names[$f['file_category']] ?? $f['file_category'];
                                $is_pub = (int)($f['is_published_to_sub'] ?? 0);
                            ?>
                                <tr style="background:<?= $bg_color ?>; border-bottom:1px solid #e2e8f0;">
                                    <td style="padding:8px 6px; font-weight:bold; color:#334155;"><?= htmlspecialchars($cat_label, ENT_QUOTES) ?></td>
                                    <td style="padding:8px 6px; font-size:11px; word-break:break-all;"><?= htmlspecialchars($f['file_name'], ENT_QUOTES) ?></td>
                                    <td style="padding:8px 6px; text-align:center;">
                                        <?php if ($is_pub === 1): ?>
                                            <span class="badge" style="background:#28a745; font-size:10px; padding:2px 6px; border-radius:3px;">公開中</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#6c757d; font-size:10px; padding:2px 6px; border-radius:3px;">非表示</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:8px 6px; text-align:center;">
                                        <div style="display:inline-flex; gap:5px;">
                                            <!-- 公開ボタン -->
                                            <form action="project_subcontractor.php?id=<?= $project_id ?>" method="POST" style="margin:0;">
                                                <input type="hidden" name="action" value="toggle_publish_sub">
                                                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                                                <input type="hidden" name="publish_val" value="1">
                                                <button type="submit" style="background:#28a745; color:white; border:none; padding:3px 8px; border-radius:3px; font-size:11px; cursor:pointer; font-weight:bold; <?= $is_pub === 1 ? 'opacity:0.4; cursor:not-allowed;' : '' ?>" <?= $is_pub === 1 ? 'disabled' : '' ?>>公開</button>
                                            </form>
                                            <!-- 非表示ボタン -->
                                            <form action="project_subcontractor.php?id=<?= $project_id ?>" method="POST" style="margin:0;">
                                                <input type="hidden" name="action" value="toggle_publish_sub">
                                                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                                                <input type="hidden" name="publish_val" value="0">
                                                <button type="submit" style="background:#dc3545; color:white; border:none; padding:3px 8px; border-radius:3px; font-size:11px; cursor:pointer; font-weight:bold; <?= $is_pub === 0 ? 'opacity:0.4; cursor:not-allowed;' : '' ?>" <?= $is_pub === 0 ? 'disabled' : '' ?>>非表示</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

        <?php foreach ($my_projects as $proj): 
            $project_id = $proj['project_id'];
            // 該当案件の最新共通図書・CADファイル（公開フラグ=1のもののみ）を取得
            $stmtFiles = $pdo->prepare("
                SELECT * FROM project_files 
                WHERE project_id = :project_id 
                  AND file_category IN ('cad_layout', 'cad_plan_1f', 'cad_plan_2f', 'cad_plan_3f', 'cad_plan_ph', 'cad_plan_rf', 'cad_elevation', 'cad_section', 'app_doc', 'soil_report', 'soil_impr', 'pdf_precut')
                  AND is_latest = 1 
                  AND is_published_to_sub = 1
            ");
            $stmtFiles->execute(['project_id' => $project_id]);
            $shared_files = $stmtFiles->fetchAll();

            // このプロジェクトのチャット履歴を取得
            $stmtChat = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'sub_admin' ORDER BY id ASC");
            $stmtChat->execute(['pid' => $project_id]);
            $sub_msgs = $stmtChat->fetchAll();
        ?>
            <!-- 案件ごとのカードコンテナ。PC用2カラム構成 -->
            <div class="task-card" style="border-left: 5px solid #e67e22; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 25px;">
                <div style="border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                    <h3 style="margin:0; font-size:18px;">案件名: <?= htmlspecialchars($proj['project_name'], ENT_QUOTES) ?></h3>
                </div>

                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    
                    <!-- 左カラム：案件スケジュール・納品フォーム（width: 55%） -->
                    <div style="flex: 1.2; min-width: 320px; display:flex; flex-direction:column; gap:15px;">
                        
                        <!-- 共有された図書・CADデータ表示セクション -->
                        <div class="shared-files-section" style="border:1px solid #cce5ff; background:#e6f2ff; padding:12px; border-radius:6px; font-size:13px; border-left: 5px solid #2563eb;">
                            <strong style="color:#004085; display:block; margin-bottom:8px; font-size:14px;">📂 共有された共通図書・CADデータ:</strong>
                            <?php if (count($shared_files) > 0): ?>
                                <ul style="margin:5px 0 0 0; padding-left:20px; line-height:1.8; list-style-type:square;">
                                    <?php 
                                    $sub_cat_names = [
                                        'cad_layout'    => '配置図 (CAD)',
                                        'cad_plan_1f'   => '1F平面図 (CAD)',
                                        'cad_plan_2f'   => '2F平面図 (CAD)',
                                        'cad_plan_3f'   => '3F平面図 (CAD)',
                                        'cad_plan_ph'   => 'PH平面図 (CAD)',
                                        'cad_plan_rf'   => 'RF平面図 (CAD)',
                                        'cad_elevation' => '立面図 (CAD)',
                                        'cad_section'   => '矩計図 (CAD)',
                                        'app_doc'       => '確認申請書',
                                        'soil_report'   => '地盤調査報告書',
                                        'soil_impr'     => '地盤改良設計書',
                                        'pdf_precut'    => 'プレカット図等',
                                    ];
                                    foreach ($shared_files as $file): 
                                        $download_url = htmlspecialchars($file['drive_file_id'], ENT_QUOTES);
                                        if (strpos($file['drive_file_id'], 'uploads/') !== 0 && !empty($file['drive_file_id'])) {
                                            $download_url = 'https://drive.google.com/file/d/' . htmlspecialchars($file['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk';
                                        }
                                        $lbl = $sub_cat_names[$file['file_category']] ?? $file['file_category'];
                                    ?>
                                        <li style="margin-bottom:5px;">
                                            <span style="font-weight:bold; color:#1e40af; margin-right:5px;">[<?= htmlspecialchars($lbl, ENT_QUOTES) ?>]</span>
                                            <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none; border-bottom:1px dashed #0056b3;">
                                                <?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?> 
                                            </a>
                                            <span class="badge" style="background:#64748b; color:white; font-size:9px; padding:1px 4px; border-radius:3px; margin-left:5px;">V<?= $file['version'] ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div style="color:#856404; font-size:12px; margin-top:5px;">現在共有されている図書・CADデータはありません。（管理者が公開するとここに表示されます）</div>
                            <?php endif; ?>
                        </div>

                        <!-- 各発注タスクの処理 -->
                        <?php foreach ($proj['tasks'] as $task): ?>
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:15px; border-radius:6px; display:flex; flex-direction:column; gap:10px;">
                                <div style="border-bottom: 1px solid #eee; padding-bottom: 8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                                    <span style="font-size:14px; font-weight:bold; color:#333;">📋 依頼内容: <?= htmlspecialchars($task['task_title'], ENT_QUOTES) ?></span>
                                    <span style="font-size:13px;">報酬額: <strong style="color:#d97706;"><?= number_format($task['order_amount']) ?>円</strong></span>
                                </div>
                                
                                <?php if ($task['status'] === 'requested'): ?>
                                    <div style="background:#fff3cd; border:1px solid #ffeeba; padding:15px; border-radius:6px;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                                            <div style="margin-bottom:10px;">
                                                <label style="font-weight:bold; font-size:13px; color:#e67e22; display:block; margin-bottom:5px;">完了納期予定日を設定:</label>
                                                <input type="date" name="expected_delivery_date" required style="padding:6px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
                                            </div>
                                            <button type="submit" class="btn-accept" style="font-weight:bold; padding:8px 20px;">納期を入力して承諾する</button>
                                        </form>
                                    </div>
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
                                        } elseif ($task['status'] === 'cancelled') {
                                            $badge_bg = '#dc3545';
                                            $status_label = 'キャンセル済';
                                        }
                                    ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; background:#fff; padding:10px; border:1px solid #e2e8f0; border-radius:4px;">
                                        <div>状態: <span class="badge" style="background:<?= $badge_bg ?>; color:white; padding:4px 10px; border-radius:4px; font-size:12px;"><?= htmlspecialchars($status_label, ENT_QUOTES) ?></span></div>
                                        <div style="font-size:13px; color:#555;">完了納期予定日: <strong><?= !empty($task['expected_delivery_date']) ? date('Y年m月d日', strtotime($task['expected_delivery_date'])) : '未設定' ?></strong></div>
                                    </div>

                                    <?php if ($task['status'] !== 'cancelled'): ?>
                                        <div class="delivery-section" style="border:1px solid #e2e8f0; background:#fdfdfd; padding:15px; border-radius:6px; font-size:13px;">
                                            <strong>📤 成果物（作成した図面）の納品・差し替え:</strong>
                                            <p style="font-size:11px; color:#666; margin:4px 0 10px 0;">※個別にアップロード可能です。差し替えた場合も履歴が残ります。</p>
                                            <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;">
                                                <input type="hidden" name="action" value="deliver_task">
                                                <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                                                <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                                                
                                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                    <label style="width:150px; font-weight:bold; color:#0056b3;">意匠図用アーキデータ:</label>
                                                    <input type="file" name="architrend_design" style="font-size:12px;">
                                                </div>
                                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                    <label style="width:150px; font-weight:bold; color:#0056b3;">構造図用アーキデータ:</label>
                                                    <input type="file" name="architrend_struct" style="font-size:12px;">
                                                </div>
                                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                    <label style="width:150px; font-weight:bold; color:#dc3545;">構造図PDF (依頼主公開):</label>
                                                    <input type="file" name="structural_pdf" style="font-size:12px;">
                                                </div>
                                                <div style="margin-top:8px;">
                                                    <button type="submit" style="background:#28a745; color:white; border:none; padding:8px 18px; border-radius:4px; font-size:13px; font-weight:bold; cursor:pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">選択したファイルを納品・差し替えする</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- プロジェクト全体の納品履歴 -->
                        <div class="history-section" style="font-size:12px; border:1px solid #e2e8f0; background:#fdfdfd; padding:15px; border-radius:6px;">
                            <strong>📜 納品履歴一覧:</strong>
                            <?php
                                $stmtHist = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category IN ('sub_architrend_design', 'sub_architrend_struct', 'sub_structural_pdf') ORDER BY created_at DESC");
                                $stmtHist->execute(['pid' => $project_id]);
                                $hist_files = $stmtHist->fetchAll();
                                
                                if (count($hist_files) > 0):
                            ?>
                                <ul style="margin:5px 0 0 0; padding-left:20px; color:#555; list-style-type:circle;">
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
                                        <li style="margin-bottom:4px;">
                                            [<?= $lbl ?>] <a href="<?= $hurl ?>" target="_blank" style="color:#0056b3; text-decoration:none;"><?= htmlspecialchars($hf['file_name'], ENT_QUOTES) ?></a> 
                                            <span style="font-size:10px; color:#999;">(V<?= $hf['version'] ?>) - <?= date('m/d H:i', strtotime($hf['created_at'])) ?></span>
                                            <?php if ($hf['is_latest']): ?>
                                                <span style="background:#17a2b8; color:white; padding:1px 4px; border-radius:3px; font-size:9px;">最新</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div style="color:#aaa; margin-top:5px;">まだ納品されていません。</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 右カラム：この案件の連絡・質疑チャット（width: 45%） -->
                    <div style="flex: 1; min-width: 300px; display:flex; flex-direction:column; border-left:1px solid #eee; padding-left:20px;">
                        <h4 style="margin:0 0 10px 0; color:#d97706; font-size:14px; display:flex; align-items:center; gap:5px;">💬 この案件の連絡・質疑チャット</h4>
                        <div style="background:#fdf6e3; border:1px solid #e2e8f0; border-radius:8px; display:flex; flex-direction:column; height:380px;">
                            <div style="flex:1; overflow-y:auto; padding:10px; display:flex; flex-direction:column; gap:8px;" id="chatList_<?= $project_id ?>">
                                <?php foreach ($sub_msgs as $msg): 
                                    $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                                    $bubbleBg = $isMe ? '#dcf8c6' : '#dbeafe';
                                    $align = $isMe ? 'flex-end' : 'flex-start';
                                    $sender = $isMe ? 'あなた' : '管理者';
                                ?>
                                    <div style="display:flex; flex-direction:column; align-items:<?= $align ?>;">
                                        <span style="font-size:10px; color:#666; margin-bottom:2px;"><?php if (!$isMe): ?><?= $sender ?> <?php endif; ?>(<?= date('m/d H:i', strtotime($msg['created_at'])) ?>)</span>
                                        <?php if (!empty($msg['message_text'])): ?>
                                            <div style="background:<?= $bubbleBg ?>; padding:8px 12px; border-radius:12px; font-size:13px; max-width:85%; white-space:pre-wrap; word-break:break-word;"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
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
                            <!-- 添付ファイルの強力な視認化機能インジケーター -->
                            <div id="filePreview_<?= $project_id ?>" style="padding:5px 10px; background:#fff; border-top:1px solid #eee; font-size:11px;"></div>
                            <div style="background:#fff; border-top:1px solid #e2e8f0; padding:10px; border-radius:0 0 8px 8px; display:flex; gap:10px; align-items:center;">
                                <input type="file" id="chatFile_<?= $project_id ?>" accept="image/*,.pdf" style="display:none;" onchange="previewSubFile(this, <?= $project_id ?>)">
                                <label for="chatFile_<?= $project_id ?>" id="fileLabel_<?= $project_id ?>" style="cursor:pointer; font-size:18px; color:#6c757d;" title="ファイルを添付">📎</label>
                                
                                <textarea id="chatText_<?= $project_id ?>" style="flex:1; border:1px solid #ccc; border-radius:20px; padding:8px 12px; font-size:13px; resize:none;" rows="1" placeholder="メッセージを入力..."></textarea>
                                
                                <button onclick="sendProjMessage(<?= $project_id ?>)" style="background:#3b82f6; color:white; border:none; border-radius:50%; width:36px; height:36px; cursor:pointer; font-size:16px;">➤</button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
    function previewSubFile(input, projectId) {
        const preview = document.getElementById('filePreview_' + projectId);
        const label = document.getElementById('fileLabel_' + projectId);
        const textarea = document.getElementById('chatText_' + projectId);
        const sendBtn = textarea.parentElement.querySelector('button');

        if (input.files && input.files[0]) {
            preview.innerHTML = `<span class="preview-badge" style="background:#dcfce7; color:#15803d; padding:6px 12px; border-radius:6px; font-size:12px; display:inline-flex; align-items:center; gap:5px; border:2px solid #bbf7d0; font-weight:bold; box-shadow:0 2px 4px rgba(0,0,0,0.05); animation: pulse-green-border 2s infinite;">📎 【送信待ち】 ${input.files[0].name} <span class="preview-remove" style="cursor:pointer; color:#ef4444; font-weight:bold; margin-left:8px; font-size:14px; line-height:1; padding:2px 6px; background:#fee2e2; border-radius:50%;" onclick="removeSubChatFile('${input.id}', ${projectId})">×</span></span>`;
            if (label) {
                label.style.background = '#10b981';
                label.style.color = '#fff';
                label.style.padding = '4px 8px';
                label.style.borderRadius = '4px';
            }
            if (textarea) {
                textarea.style.background = '#f0fdf4';
                textarea.style.borderColor = '#10b981';
                textarea.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.2)';
            }
            if (sendBtn) {
                sendBtn.style.background = '#10b981';
                sendBtn.style.animation = 'pulse-green 1.5s infinite';
            }
        } else {
            preview.innerHTML = '';
            if (label) {
                label.style.background = '';
                label.style.color = '#6c757d';
                label.style.padding = '';
                label.style.borderRadius = '';
            }
            if (textarea) {
                textarea.style.background = '';
                textarea.style.borderColor = '';
                textarea.style.boxShadow = '';
            }
            if (sendBtn) {
                sendBtn.style.background = '#3b82f6';
                sendBtn.style.animation = '';
            }
        }
    }

    function removeSubChatFile(inputId, projectId) {
        const input = document.getElementById(inputId);
        if (input) {
            input.value = '';
            previewSubFile(input, projectId);
        }
    }

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

        const sendBtn = fileEl.parentElement.querySelector('button');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.textContent = '...';
        }

        fetch('api_send_message.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    textEl.value = '';
                    fileEl.value = '';
                    previewSubFile(fileEl, projectId);
                    window.location.reload();
                } else {
                    alert('送信エラー');
                }
            })
            .catch(e => {
                console.error(e);
                alert('通信エラー');
            })
            .finally(() => {
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.textContent = '➤';
                }
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