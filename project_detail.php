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
    SELECT p.*, s.soil_status, u.company_name, u.contact_name as client_name
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
        
        .chat-container { max-height: 400px; overflow-y: auto; background: #fff; border: 1px solid #ddd; padding: 10px; border-radius: 4px; }
        .chat-msg { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee; font-size: 12px; }
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
        <div class="column col-right">
            <h2 class="section-title" style="background:#17a2b8;">💬 依頼主チャット</h2>
            <div class="box">
                <div class="chat-container">
                    <?php foreach ($chat_messages as $msg): ?>
                        <div class="chat-msg">
                            <?php 
                                // 送信者が管理者の場合とクライアントの場合で色を変える
                                $isAdminMsg = ($msg['sender_id'] == 1);
                                $name = $isAdminMsg ? '管理者' : '依頼主';
                                $color = $isAdminMsg ? '#0056b3' : '#28a745';
                            ?>
                            <div style="font-weight:bold; color:<?= $color ?>; margin-bottom:3px;"><?= $name ?></div>
                            <div style="white-space:pre-wrap;"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($chat_messages)): ?>
                        <span style="color:#999; font-size:12px;">メッセージはありません。</span>
                    <?php endif; ?>
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:10px;">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="message_text" placeholder="メッセージを入力してください..." style="width:100%; height:60px; margin-bottom:5px; font-size:12px; box-sizing:border-box; padding:8px; border:1px solid #ccc; border-radius:4px;" required></textarea>
                    <button type="submit" style="width:100%; background:#17a2b8; color:white; border:none; padding:8px; cursor:pointer; font-size:12px; font-weight:bold; border-radius:4px;">送信</button>
                </form>
            </div>

            <?php if ($is_admin): ?>
            <!-- ==============================
                 【管理者専用エリア】
                 ============================== -->
            <div style="margin-top: 20px; border-top: 2px dashed #ccc; padding-top: 20px;">
                <div style="font-size:12px; font-weight:bold; color:#c0392b; margin-bottom:10px;">🔒 以下は管理者のみに表示されます</div>
                
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
                
                <script>
                let currentEstimate = 0;
                let currentTax = 0;
                let currentTotal = 0;
                
                function calcClientEstimate() {
                    let base = parseInt(document.getElementById('est_base').value) || 0;
                    let area = parseFloat(document.getElementById('est_area').value) || 0;
                    
                    let area_extra = 0;
                    if (area > 150) {
                        area_extra = Math.ceil(area - 150) * 600;
                    }
                    
                    let base_with_area = base + area_extra;

                    let multiplier = 0;
                    document.querySelectorAll('.est_multiplier:checked').forEach(cb => {
                        multiplier += parseFloat(cb.value);
                    });
                    let shape_extra = Math.round(base_with_area * multiplier);

                    let grade_extra = parseInt(document.getElementById('est_grade').value) || 0;
                    let kanamono = parseInt(document.getElementById('est_kanamono').value) || 0;
                    let special = parseInt(document.getElementById('est_special').value) || 0;
                    let other_extra = (kanamono * 15000) + (special * 15000);

                    currentEstimate = base_with_area + shape_extra + grade_extra + other_extra;
                    currentTax = Math.round(currentEstimate * 0.1);
                    currentTotal = currentEstimate + currentTax;
                    
                    document.getElementById('est_total_disp').innerText = currentEstimate.toLocaleString();
                }

                function getEstimateMessage() {
                    let msg = `【概算お見積り】\n構造計算等の概算見積を算出いたしました。\n\n`;
                    msg += `税抜金額: ${currentEstimate.toLocaleString()}円\n`;
                    msg += `消費税: ${currentTax.toLocaleString()}円\n`;
                    msg += `税込合計: ${currentTotal.toLocaleString()}円\n\n`;
                    msg += `よろしければ正式にご依頼ください。`;
                    return msg;
                }

                function sendClientEstimate() {
                    calcClientEstimate();
                    if (currentEstimate === 0) return;
                    
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'project_detail.php?id=<?= $project_id ?>';
                    
                    const inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'action';
                    inputAction.value = 'send_message';
                    form.appendChild(inputAction);

                    const inputText = document.createElement('input');
                    inputText.type = 'hidden';
                    inputText.name = 'message_text';
                    inputText.value = getEstimateMessage();
                    form.appendChild(inputText);

                    document.body.appendChild(form);
                    form.submit();
                }

                function saveAndPrintEstimate() {
                    calcClientEstimate();
                    if (currentEstimate === 0) return;
                    
                    // fetchを用いてDBに見積情報を保存し、その直後に別タブで印刷用ページを開く
                    const formData = new FormData();
                    formData.append('action', 'save_estimate');
                    formData.append('project_id', <?= $project_id ?>);
                    formData.append('base_price', document.getElementById('est_base').value);
                    formData.append('area', document.getElementById('est_area').value);
                    formData.append('grade_price', document.getElementById('est_grade').value);
                    formData.append('total_price', currentEstimate);
                    
                    fetch('api_save_estimate.php', {
                        method: 'POST',
                        body: formData
                    }).then(res => {
                        window.open(`estimate_print.php?id=<?= $project_id ?>`, '_blank');
                    }).catch(err => {
                        console.error(err);
                        alert("見積もりの保存に失敗しましたが、プレビューを開きます。");
                        window.open(`estimate_print.php?id=<?= $project_id ?>`, '_blank');
                    });
                }

                window.addEventListener('DOMContentLoaded', calcClientEstimate);
                </script>
                <?php endif; ?>

                <h2 class="section-title" style="background:#e67e22; margin-top:20px;">🤝 協力業者への発注・タスク管理</h2>
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
                        const pricePerSqm = 500;
                        const basePrice = 30000;
                        const total = basePrice + Math.round(area * pricePerSqm);
                        document.getElementById('sub_calc_result').innerHTML = 
                            '<span style="color:#28a745;font-size:12px;font-weight:bold;">推奨発注額: ' + total.toLocaleString() + '円</span>';
                        document.querySelector('input[name="order_amount"]').value = total;
                    }
                    </script>

                    <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                        <input type="hidden" name="action" value="order_subcontractor">
                        <select name="subcontractor_id" style="width:100%; margin-bottom:5px; font-size:12px;">
                            <?php foreach($subcontractors as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= $sub['contact_name'] ?> 様</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="task_title" placeholder="依頼内容（例：構造図作図）" style="width:100%; margin-bottom:5px; font-size:12px;">
                        <input type="number" name="order_amount" placeholder="金額(税込)" style="width:100%; margin-bottom:5px; font-size:12px;">
                        <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:5px; font-size:12px; cursor:pointer;">発注を確定・送信</button>
                    </form>
                </div>
                
                <div style="font-size:11px; color:#555; margin-top:10px;">
                    <h3 style="font-size:12px; border-bottom:1px solid #ccc; margin-top:0;">発注履歴</h3>
                    <?php foreach($orders as $o): ?>
                        <div style="padding:4px 0; border-bottom:1px solid #eee;">
                            <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?>: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?> (<?= number_format($o['order_amount']) ?>円)
                            <span class="badge" style="background:#555;"><?= htmlspecialchars($o['status'], ENT_QUOTES) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?> <!-- 管理者エリア終了 -->
        </div>
    </div>
</body>
</html>
