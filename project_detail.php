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

    // チャットメッセージの送信
    if ($action === 'send_message') {
        $message_text = trim($_POST['message_text'] ?? '');
        if ($message_text !== '') {
            $thread_type = 'client_admin'; // 対依頼主チャット
            
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
    SELECT p.*, s.soil_status, u.company_name, u.contact_name as client_name, u.phone_number as client_phone
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

// 案件に関連する全ファイルを取得
$stmtFiles = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND is_latest = 1");
$stmtFiles->execute(['pid' => $project_id]);
$all_files = $stmtFiles->fetchAll();

// カテゴリごとに整理
$files_by_cat = [];
foreach($all_files as $f) {
    $files_by_cat[$f['file_category']] = $f;
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
                    <strong>ステータス:</strong> <span class="badge" style="background:#007bff;"><?= htmlspecialchars($project_info['status'], ENT_QUOTES) ?></span>
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
                        'pdf_section' => '矩計図'
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
                <form action="estimate_print.php" method="GET" target="_blank">
                    <input type="hidden" name="id" value="<?= $project_id ?>">
                    <button type="submit" style="width:100%; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;">
                        📄 最新の見積書を開く（印刷・PDF保存）
                    </button>
                </form>
            </div>

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

        <!-- 中央パネル：最終成果物 -->
        <div class="column col-center">
            <h2 class="section-title" style="background:#3b82f6;">📁 最終成果物（構造図・計算書）</h2>
            
            <div class="box">
                <div style="font-size:12px; color:#555; margin-bottom:10px;">
                    管理者が承認した構造図・計算書がここに表示されます。依頼主はこちらからダウンロードしてください。
                </div>
                
                <?php if (isset($files_by_cat['structural_dwg'])): ?>
                    <?php 
                        $f = $files_by_cat['structural_dwg'];
                        $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                            ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                            : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                    ?>
                    <div style="padding:15px; border:1px solid #3b82f6; background:#eff6ff; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#1e40af; margin-bottom:5px;">構造図・計算書 (最新版 V<?= $f['version'] ?>)</div>
                        <a href="<?= $url ?>" target="_blank" class="file-link" style="font-size:14px; padding:10px 15px; background:#3b82f6; color:white;">
                            📄 ダウンロード（Google Driveを開く）
                        </a>
                    </div>
                <?php else: ?>
                    <div style="padding:20px; text-align:center; color:#999; border:1px dashed #ccc; border-radius:6px;">
                        まだ納品された成果物はありません。
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_admin && count($delivered_orders) > 0): ?>
                <div class="box" style="background:#fff3cd; border: 1px solid #ffeeba; margin-top:20px;">
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
        </div>

        <!-- 右パネル：チャット・管理ツール -->
        <div class="column col-right" style="padding: 15px;">
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
                
                <?php if ($project_info['status'] === 'quote_req'): ?>
                <h2 class="section-title" style="background:#28a745;">💰 自動見積シミュレーター</h2>
                <div class="box" style="background:#e8f5e9;">
                    <div style="font-size:11px; margin-bottom:10px; display:grid; gap:8px;">
                        <div>
                            <strong>基本料金（構造）</strong><br>
                            <select id="est_base" style="width:100%; font-size:11px; padding:3px;">
                                <option value="75000">構造計算 平屋建・2階建 (75,000円)</option>
                                <option value="100000">構造計算 3階建 (100,000円)</option>
                            </select>
                        </div>
                        <div>
                            <strong>構造床面積 (㎡)</strong><br>
                            <input type="number" id="est_area" value="100" style="width:100%; font-size:11px; padding:3px;">
                        </div>
                        <div>
                            <strong>目標等級加算</strong><br>
                            <select id="est_grade" style="width:100%; font-size:11px; padding:3px;">
                                <option value="0">なし (0円)</option>
                                <option value="40000">耐震等級3+耐風等級2 (+40,000円)</option>
                                <option value="20000">耐震等級2 (+20,000円)</option>
                                <option value="40000">耐震等級3 (+40,000円)</option>
                            </select>
                        </div>
                        <div>
                            <strong>形状加算等（基本料金+面積割増に乗算）</strong><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.2"> 準耐火/耐火構造 (+20%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.2"> PH階がある (+20%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.1"> 小屋裏収納がある (+10%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.1"> スキップ等レベル違い (+10%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="1.0"> 平面不整形 (+100%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="1.0"> 立面不整形 (+100%)</label>
                        </div>
                        <div>
                            <strong>その他加算（固定額）</strong><br>
                            <label>金物工法階数: <input type="number" id="est_kanamono" value="0" style="width:40px; font-size:11px;"> 階</label><br>
                            <label>斜め壁等特殊箇所数: <input type="number" id="est_special" value="0" style="width:40px; font-size:11px;"> 箇所</label>
                        </div>
                    </div>
                    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
                        見積合計: <span id="est_total_disp" style="color:#d32f2f; font-size:14px;">0</span> 円 (税別)
                    </div>
                    <div style="margin-top:10px; display:flex; gap:10px; flex-direction:column;">
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="calcClientEstimate()" style="flex:1; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">再計算</button>
                            <button type="button" onclick="saveAndPrintEstimate()" style="flex:2; background:#ff9800; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">印刷用PDFを発行</button>
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
        const msg = text || textarea.value.trim();
        if (!msg && fileInput.files.length === 0) return;

        const formData = new FormData();
        formData.append('project_id', PROJECT_ID);
        formData.append('message_text', msg);
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

    // ===== 見積シミュレーター =====
    let currentEstimate = 0, currentTax = 0, currentTotal = 0;
    function calcClientEstimate() {
        let base = parseInt(document.getElementById('est_base')?.value) || 0;
        let area = parseFloat(document.getElementById('est_area')?.value) || 0;
        let area_extra = area > 150 ? Math.ceil(area - 150) * 600 : 0;
        let base_with_area = base + area_extra;
        let multiplier = 0;
        document.querySelectorAll('.est_multiplier:checked').forEach(cb => multiplier += parseFloat(cb.value));
        let shape_extra = Math.round(base_with_area * multiplier);
        let grade_extra = parseInt(document.getElementById('est_grade')?.value) || 0;
        let kanamono = parseInt(document.getElementById('est_kanamono')?.value) || 0;
        let special = parseInt(document.getElementById('est_special')?.value) || 0;
        let other_extra = (kanamono * 15000) + (special * 15000);
        currentEstimate = base_with_area + shape_extra + grade_extra + other_extra;
        currentTax = Math.round(currentEstimate * 0.1);
        currentTotal = currentEstimate + currentTax;
        const el = document.getElementById('est_total_disp');
        if (el) el.innerText = currentEstimate.toLocaleString();
    }
    function sendClientEstimate() {
        calcClientEstimate();
        if (currentEstimate === 0) return;
        const msg = `【概算お見積り】\n税抜金額: ${currentEstimate.toLocaleString()}円\n消費税: ${currentTax.toLocaleString()}円\n税込合計: ${currentTotal.toLocaleString()}円\n\nよろしければ正式にご依頼ください。`;
        sendMessage(msg);
    }
    function saveAndPrintEstimate() {
        calcClientEstimate();
        if (currentEstimate === 0) return;
        const formData = new FormData();
        formData.append('project_id', PROJECT_ID);
        formData.append('base_price', document.getElementById('est_base').value);
        formData.append('area', document.getElementById('est_area').value);
        formData.append('grade_price', document.getElementById('est_grade').value);
        formData.append('total_price', currentEstimate);
        fetch('api_save_estimate.php', { method: 'POST', body: formData })
            .then(() => window.open(`estimate_print.php?id=${PROJECT_ID}`, '_blank'))
            .catch(() => window.open(`estimate_print.php?id=${PROJECT_ID}`, '_blank'));
    }
    window.addEventListener('DOMContentLoaded', calcClientEstimate);
    </script>
</body>
</html>
