<?php
// project_detail.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'client']);

$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

$project_id = $_GET['id'] ?? null;
if (!$project_id) { die("譯井ｻｶ縺梧欠螳壹＆繧後※縺・∪縺帙ｓ縲・); }

// RBAC繝√ぉ繝・け: 萓晞ｼ荳ｻ縺ｮ蝣ｴ蜷医∬・蛻・′繧ｪ繝ｼ繝翫・縺ｮ譯井ｻｶ莉･螟悶∈縺ｮ繧｢繧ｯ繧ｻ繧ｹ繧貞宛髯・$stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmtProj->execute(['id' => $project_id]);
$project = $stmtProj->fetch();

if (!$project) {
    die("謖・ｮ壹＆繧後◆譯井ｻｶ縺瑚ｦ九▽縺九ｊ縺ｾ縺帙ｓ縲・);
}

if ($_SESSION['role'] === 'client' && $project['client_id'] !== $current_user_id) {
    header("HTTP/1.1 403 Forbidden");
    die("縺薙・譯井ｻｶ縺ｸ縺ｮ繧｢繧ｯ繧ｻ繧ｹ讓ｩ髯舌′縺ゅｊ縺ｾ縺帙ｓ縲・br><a href='index.php'>繝繝・す繝･繝懊・繝峨∈謌ｻ繧・/a>");
}

// ==========================================
// POST蜃ｦ逅・ｼ育匱豕ｨ萓晞ｼ縺ｮ逋ｻ骭ｲ縺ｪ縺ｩ・・// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 譁ｰ隕冗匱豕ｨ萓晞ｼ縺ｮ菫晏ｭ・    if ($action === 'order_subcontractor') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status) VALUES (:pid, :sub_id, :task, :amount, 'requested')");
            $stmt->execute([
                'pid' => $project_id,
                'sub_id' => $_POST['subcontractor_id'],
                'task' => $_POST['task_title'],
                'amount' => $_POST['order_amount']
            ]);

            // 譯井ｻｶ縺ｮ繧ｹ繝・・繧ｿ繧ｹ繧偵梧ｧ矩蝗ｳ菴懈・荳ｭ (structural_dwg)縲阪∈閾ｪ蜍墓峩譁ｰ
            $stmtUpdate = $pdo->prepare("UPDATE projects SET status = 'structural_dwg' WHERE id = :pid");
            $stmtUpdate->execute(['pid' => $project_id]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("逋ｺ豕ｨ蜃ｦ逅・↓螟ｱ謨励＠縺ｾ縺励◆: " . $e->getMessage());
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }

    // 邏榊刀謇ｿ隱榊・逅・    if ($action === 'approve_delivery') {
        $order_id = intval($_POST['order_id']);
        $pdo->beginTransaction();
        try {
            // 1. 逋ｺ豕ｨ繧ｹ繝・・繧ｿ繧ｹ繧・completed 縺ｫ譖ｴ譁ｰ
            $stmt = $pdo->prepare("UPDATE subcontractor_orders SET status = 'completed' WHERE id = :id");
            $stmt->execute(['id' => $order_id]);

            // 2. 譯井ｻｶ繧ｹ繝・・繧ｿ繧ｹ繧偵梧署蜃ｺ貂医・遒ｺ隱堺ｸｭ (submission)縲阪↓譖ｴ譁ｰ
            $stmtUpdate = $pdo->prepare("UPDATE projects SET status = 'submission' WHERE id = :pid");
            $stmtUpdate->execute(['pid' => $project_id]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("謇ｿ隱榊・逅・↓螟ｱ謨励＠縺ｾ縺励◆: " . $e->getMessage());
        }
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }

    // 繝√Ε繝・ヨ繝｡繝・そ繝ｼ繧ｸ縺ｮ騾∽ｿ｡
    if ($action === 'send_message') {
        $message_text = trim($_POST['message_text'] ?? '');
        if ($message_text !== '') {
            $thread_type = 'client_admin'; // 蟇ｾ萓晞ｼ荳ｻ繝√Ε繝・ヨ
            
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
    
    // 繝輔ぃ繧､繝ｫ繧｢繝・・繝ｭ繝ｼ繝牙・逅・ｼ育ｮ｡逅・・・萓晞ｼ荳ｻ・・    if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $file_category = $_POST['file_category'] ?? '';
        if ($file_category !== '') {
            $file_name = $_FILES['upload_file']['name'];
            $tmp_name = $_FILES['upload_file']['tmp_name'];
            $mime_type = $_FILES['upload_file']['type'];

            try {
                // Google Drive 縺ｸ縺ｮ繧｢繝・・繝ｭ繝ｼ繝・                require_once 'google_drive_client.php';
                $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);

                $pdo->beginTransaction();
                // 1. 譌｢蟄倥・蜷後き繝・ざ繝ｪ縺ｮ繝輔ぃ繧､繝ｫ繧呈怙譁ｰ繝輔Λ繧ｰ縺九ｉ螟悶☆
                $stmtDisable = $pdo->prepare("
                    UPDATE project_files 
                    SET is_latest = 0 
                    WHERE project_id = :pid AND file_category = :cat
                ");
                $stmtDisable->execute([
                    'pid' => $project_id,
                    'cat' => $file_category
                ]);

                // 2. 迴ｾ蝨ｨ縺ｮ譛螟ｧ繝舌・繧ｸ繝ｧ繝ｳ繧貞叙蠕・                $stmtVersion = $pdo->prepare("
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

                // 3. 譁ｰ縺励＞繝ｬ繧ｳ繝ｼ繝峨ｒ謖ｿ蜈･
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
                die("繝輔ぃ繧､繝ｫ縺ｮ繧｢繝・・繝ｭ繝ｼ繝峨∪縺溘・繝・・繧ｿ繝吶・繧ｹ逋ｻ骭ｲ縺ｫ螟ｱ謨励＠縺ｾ縺励◆: " . $e->getMessage());
            }
            header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
        }
    }
}

// ==========================================
// 繝・・繧ｿ蜿門ｾ・// ==========================================
// 蜊泌鴨讌ｭ閠・ｸ隕ｧ繧貞叙蠕・$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// 縺薙・譯井ｻｶ縺ｸ縺ｮ逋ｺ豕ｨ螻･豁ｴ繧貞叙蠕・$stmt = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid");
$stmt->execute(['pid' => $project_id]);
// ==========================================
// 繝・・繧ｿ蜿門ｾ・// ==========================================
// 譯井ｻｶ縺ｨ莉墓ｧ俶ュ蝣ｱ繧貞叙蠕・$stmtProj = $pdo->prepare("
    SELECT p.*, s.soil_status, u.company_name, u.contact_name as client_name
    FROM projects p 
    LEFT JOIN project_specs s ON p.id = s.project_id 
    LEFT JOIN users u ON p.client_id = u.id
    WHERE p.id = :id
");
$stmtProj->execute(['id' => $project_id]);
$project_info = $stmtProj->fetch();

if (!$project_info) {
    die("譯井ｻｶ諠・ｱ縺ｮ蜿門ｾ励↓螟ｱ謨励＠縺ｾ縺励◆縲・);
}

// 譯井ｻｶ縺ｫ髢｢騾｣縺吶ｋ蜈ｨ繝輔ぃ繧､繝ｫ繧貞叙蠕・$stmtFiles = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND is_latest = 1");
$stmtFiles->execute(['pid' => $project_id]);
$all_files = $stmtFiles->fetchAll();

// 繧ｫ繝・ざ繝ｪ縺斐→縺ｫ謨ｴ逅・$files_by_cat = [];
foreach($all_files as $f) {
    $files_by_cat[$f['file_category']] = $f;
}

// 蜊泌鴨讌ｭ閠・ｸ隕ｧ繧貞叙蠕・$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// 縺薙・譯井ｻｶ縺ｸ縺ｮ逋ｺ豕ｨ螻･豁ｴ繧貞叙蠕・$stmtOrders = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid ORDER BY o.created_at DESC");
$stmtOrders->execute(['pid' => $project_id]);
$orders = $stmtOrders->fetchAll();

// 譛ｪ謇ｿ隱阪・邏榊刀繧貞叙蠕・$stmtDelivered = $pdo->prepare("
    SELECT o.*, u.contact_name, f.drive_file_id, f.file_name, f.version
    FROM subcontractor_orders o 
    JOIN users u ON o.subcontractor_id = u.id 
    LEFT JOIN project_files f ON o.project_id = f.project_id AND f.file_category = 'structural_dwg' AND f.is_latest = 1
    WHERE o.project_id = :pid AND o.status = 'delivered'
");
$stmtDelivered->execute(['pid' => $project_id]);
$delivered_orders = $stmtDelivered->fetchAll();

// 繝√Ε繝・ヨ螻･豁ｴ繧貞叙蠕・$stmtMsgs = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'client_admin' ORDER BY id ASC");
$stmtMsgs->execute(['pid' => $project_id]);
$chat_messages = $stmtMsgs->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>譯井ｻｶ隧ｳ邏ｰ | 讒矩險ｭ險医し繝昴・繝医・繝昴・繧ｿ繝ｫ</title>
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
        <a href="index.php" style="color:#0056b3; text-decoration:none; font-weight:bold;">筐・譯井ｻｶ荳隕ｧ縺ｫ謌ｻ繧・/a>
        <a href="logout.php" style="color:#c0392b; text-decoration:none; font-weight:bold;">繝ｭ繧ｰ繧｢繧ｦ繝・/a>
    </div>

    <div class="container">
        <!-- 蟾ｦ繝代ロ繝ｫ・壻ｾ晞ｼ荳ｻ縺ｨ譯井ｻｶ諠・ｱ -->
        <div class="column col-left">
            <h2 class="section-title" style="background:#4a5568;">搭 譯井ｻｶ諠・ｱ縺ｨ萓晞ｼ荳ｻ蝗ｳ譖ｸ</h2>
            
            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">蝓ｺ譛ｬ諠・ｱ</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <strong>譯井ｻｶ蜷・</strong> <?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?><br>
                    <strong>萓晞ｼ荳ｻ:</strong> <?= htmlspecialchars($project_info['company_name'] . ' ' . $project_info['client_name'], ENT_QUOTES) ?><br>
                    <strong>蝨ｰ逶､隱ｿ譟ｻ:</strong> <?= htmlspecialchars($project_info['soil_status'] ?? '譛ｪ螳・, ENT_QUOTES) ?><br>
                    <strong>繧ｹ繝・・繧ｿ繧ｹ:</strong> <span class="badge" style="background:#007bff;"><?= htmlspecialchars($project_info['status'], ENT_QUOTES) ?></span>
                </div>
            </div>

            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">萓晞ｼ荳ｻ繧｢繝・・繝ｭ繝ｼ繝牙峙譖ｸ</h3>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $categories = [
                        'pdf_plan' => '蟷ｳ髱｢蝗ｳ',
                        'pdf_elevation' => '遶矩擇蝗ｳ',
                        'pdf_layout' => '驟咲ｽｮ蝗ｳ',
                        'pdf_section' => '遏ｩ險亥峙'
                    ];
                    foreach ($categories as $cat => $label) {
                        if (isset($files_by_cat[$cat])) {
                            $f = $files_by_cat[$cat];
                            $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                                ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                            echo "<div><strong>{$label}:</strong> <br><a href='{$url}' target='_blank' class='file-link'>塘 {$f['file_name']}</a></div>";
                        } else {
                            echo "<div><strong>{$label}:</strong> <span style='color:#999; font-size:12px;'>譛ｪ謠仙・</span></div>";
                        }
                    }
                    ?>
                </div>
            </div>
            
            <div class="box" style="background:#e8f5e9; border-color:#c8e6c9;">
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">譛譁ｰ縺ｮ隕狗ｩ肴嶌PDF</h3>
                <div style="font-size:12px; color:#666; margin-bottom:10px;">繧ｷ繝溘Η繝ｬ繝ｼ繧ｿ繝ｼ縺ｧ菴懈・縺輔ｌ縺溯ｦ狗ｩ肴嶌繧単DF縺ｨ縺励※陦ｨ遉ｺ繝ｻ蜊ｰ蛻ｷ縺ｧ縺阪∪縺吶・/div>
                <form action="estimate_print.php" method="GET" target="_blank">
                    <input type="hidden" name="id" value="<?= $project_id ?>">
                    <button type="submit" style="width:100%; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;">
                        塘 譛譁ｰ縺ｮ隕狗ｩ肴嶌繧帝幕縺擾ｼ亥魂蛻ｷ繝ｻPDF菫晏ｭ假ｼ・                    </button>
                </form>
            </div>
        </div>

        <!-- 荳ｭ螟ｮ繝代ロ繝ｫ・壽怙邨よ・譫懃黄 -->
        <div class="column col-center">
            <h2 class="section-title" style="background:#3b82f6;">刀 譛邨よ・譫懃黄・域ｧ矩蝗ｳ繝ｻ險育ｮ玲嶌・・/h2>
            
            <div class="box">
                <div style="font-size:12px; color:#555; margin-bottom:10px;">
                    邂｡逅・・′謇ｿ隱阪＠縺滓ｧ矩蝗ｳ繝ｻ險育ｮ玲嶌縺後％縺薙↓陦ｨ遉ｺ縺輔ｌ縺ｾ縺吶ゆｾ晞ｼ荳ｻ縺ｯ縺薙■繧峨°繧峨ム繧ｦ繝ｳ繝ｭ繝ｼ繝峨＠縺ｦ縺上□縺輔＞縲・                </div>
                
                <?php if (isset($files_by_cat['structural_dwg'])): ?>
                    <?php 
                        $f = $files_by_cat['structural_dwg'];
                        $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                            ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                            : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                    ?>
                    <div style="padding:15px; border:1px solid #3b82f6; background:#eff6ff; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#1e40af; margin-bottom:5px;">讒矩蝗ｳ繝ｻ險育ｮ玲嶌 (譛譁ｰ迚・V<?= $f['version'] ?>)</div>
                        <a href="<?= $url ?>" target="_blank" class="file-link" style="font-size:14px; padding:10px 15px; background:#3b82f6; color:white;">
                            塘 繝繧ｦ繝ｳ繝ｭ繝ｼ繝会ｼ・oogle Drive繧帝幕縺擾ｼ・                        </a>
                    </div>
                <?php else: ?>
                    <div style="padding:20px; text-align:center; color:#999; border:1px dashed #ccc; border-radius:6px;">
                        縺ｾ縺邏榊刀縺輔ｌ縺滓・譫懃黄縺ｯ縺ゅｊ縺ｾ縺帙ｓ縲・                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($is_admin && count($delivered_orders) > 0): ?>
                <div class="box" style="background:#fff3cd; border: 1px solid #ffeeba; margin-top:20px;">
                    <h3 style="margin-top:0; color:#856404; font-size:13px;">粕 邏榊刀遒ｺ隱阪お繝ｪ繧｢・域・譫懃黄縺ｮ謇ｿ隱榊ｾ・■・・/h3>
                    <?php foreach ($delivered_orders as $del): ?>
                        <div style="font-size:11px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px dashed #ffeeba; color:#666;">
                            <strong>諡・ｽ楢・</strong> <?= htmlspecialchars($del['contact_name'], ENT_QUOTES) ?> 讒・br>
                            <strong>繧ｿ繧ｹ繧ｯ:</strong> <?= htmlspecialchars($del['task_title'], ENT_QUOTES) ?><br>
                            <strong>邏榊刀迚ｩ:</strong> 
                            <?php if ($del['drive_file_id']): 
                                $download_url = (strpos($del['drive_file_id'], 'uploads/') !== 0 && !empty($del['drive_file_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['drive_file_id'], ENT_QUOTES);
                            ?>
                                <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">塘 遒ｺ隱阪☆繧・(V<?= $del['version'] ?>)</a>
                            <?php endif; ?>
                            
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:8px;">
                                <input type="hidden" name="action" value="approve_delivery">
                                <input type="hidden" name="order_id" value="<?= $del['id'] ?>">
                                <button type="submit" style="background:#28a745; color:white; border:none; padding:4px 10px; font-size:11px; border-radius:3px; cursor:pointer;">謇ｿ隱阪＠縺ｦ繧ｯ繝ｩ繧､繧｢繝ｳ繝医∈蜈ｬ髢・/button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 蜿ｳ繝代ロ繝ｫ・壹メ繝｣繝・ヨ繝ｻ邂｡逅・ヤ繝ｼ繝ｫ -->
        <div class="column col-right">
            <h2 class="section-title" style="background:#17a2b8;">町 萓晞ｼ荳ｻ繝√Ε繝・ヨ</h2>
            <div class="box">
                <div class="chat-container">
                    <?php foreach ($chat_messages as $msg): ?>
                        <div class="chat-msg">
                            <?php 
                                // 騾∽ｿ｡閠・′邂｡逅・・・蝣ｴ蜷医→繧ｯ繝ｩ繧､繧｢繝ｳ繝医・蝣ｴ蜷医〒濶ｲ繧貞､峨∴繧・                                $isAdminMsg = ($msg['sender_id'] == 1);
                                $name = $isAdminMsg ? '邂｡逅・・ : '萓晞ｼ荳ｻ';
                                $color = $isAdminMsg ? '#0056b3' : '#28a745';
                            ?>
                            <div style="font-weight:bold; color:<?= $color ?>; margin-bottom:3px;"><?= $name ?></div>
                            <div style="white-space:pre-wrap;"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($chat_messages)): ?>
                        <span style="color:#999; font-size:12px;">繝｡繝・そ繝ｼ繧ｸ縺ｯ縺ゅｊ縺ｾ縺帙ｓ縲・/span>
                    <?php endif; ?>
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:10px;">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="message_text" placeholder="繝｡繝・そ繝ｼ繧ｸ繧貞・蜉帙＠縺ｦ縺上□縺輔＞..." style="width:100%; height:60px; margin-bottom:5px; font-size:12px; box-sizing:border-box; padding:8px; border:1px solid #ccc; border-radius:4px;" required></textarea>
                    <button type="submit" style="width:100%; background:#17a2b8; color:white; border:none; padding:8px; cursor:pointer; font-size:12px; font-weight:bold; border-radius:4px;">騾∽ｿ｡</button>
                </form>
            </div>

            <?php if ($is_admin): ?>
            <!-- ==============================
                 縲千ｮ｡逅・・ｰら畑繧ｨ繝ｪ繧｢縲・                 ============================== -->
            <div style="margin-top: 20px; border-top: 2px dashed #ccc; padding-top: 20px;">
                <div style="font-size:12px; font-weight:bold; color:#c0392b; margin-bottom:10px;">白 莉･荳九・邂｡逅・・・縺ｿ縺ｫ陦ｨ遉ｺ縺輔ｌ縺ｾ縺・/div>
                
                <?php if ($project_info['status'] === 'quote_req'): ?>
                <h2 class="section-title" style="background:#28a745;">腸 閾ｪ蜍戊ｦ狗ｩ阪す繝溘Η繝ｬ繝ｼ繧ｿ繝ｼ</h2>
                <div class="box" style="background:#e8f5e9;">
                    <div style="font-size:11px; margin-bottom:10px; display:grid; gap:8px;">
                        <div>
                            <strong>蝓ｺ譛ｬ譁咎≡・域ｧ矩・・/strong><br>
                            <select id="est_base" style="width:100%; font-size:11px; padding:3px;">
                                <option value="75000">讒矩險育ｮ・蟷ｳ螻句ｻｺ繝ｻ2髫主ｻｺ (75,000蜀・</option>
                                <option value="100000">讒矩險育ｮ・3髫主ｻｺ (100,000蜀・</option>
                            </select>
                        </div>
                        <div>
                            <strong>讒矩蠎企擇遨・(緕｡)</strong><br>
                            <input type="number" id="est_area" value="100" style="width:100%; font-size:11px; padding:3px;">
                        </div>
                        <div>
                            <strong>逶ｮ讓咏ｭ臥ｴ壼刈邂・/strong><br>
                            <select id="est_grade" style="width:100%; font-size:11px; padding:3px;">
                                <option value="0">縺ｪ縺・(0蜀・</option>
                                <option value="40000">閠宣怫遲臥ｴ・+閠宣｢ｨ遲臥ｴ・ (+40,000蜀・</option>
                                <option value="20000">閠宣怫遲臥ｴ・ (+20,000蜀・</option>
                                <option value="40000">閠宣怫遲臥ｴ・ (+40,000蜀・</option>
                            </select>
                        </div>
                        <div>
                            <strong>蠖｢迥ｶ蜉邂礼ｭ会ｼ亥渕譛ｬ譁咎≡+髱｢遨榊牡蠅励↓荵礼ｮ暦ｼ・/strong><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.2"> 貅冶千↓/閠千↓讒矩 (+20%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.2"> PH髫弱′縺ゅｋ (+20%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.1"> 蟆丞ｱ玖｣丞庶邏阪′縺ゅｋ (+10%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="0.1"> 繧ｹ繧ｭ繝・・遲峨Ξ繝吶Ν驕輔＞ (+10%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="1.0"> 蟷ｳ髱｢荳肴紛蠖｢ (+100%)</label><br>
                            <label><input type="checkbox" class="est_multiplier" value="1.0"> 遶矩擇荳肴紛蠖｢ (+100%)</label>
                        </div>
                        <div>
                            <strong>縺昴・莉門刈邂暦ｼ亥崋螳夐｡搾ｼ・/strong><br>
                            <label>驥醍黄蟾･豕暮嚴謨ｰ: <input type="number" id="est_kanamono" value="0" style="width:40px; font-size:11px;"> 髫・/label><br>
                            <label>譁懊ａ螢∫ｭ臥音谿顔ｮ・園謨ｰ: <input type="number" id="est_special" value="0" style="width:40px; font-size:11px;"> 邂・園</label>
                        </div>
                    </div>

                    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
                        隕狗ｩ榊粋險・ <span id="est_total_disp" style="color:#d32f2f; font-size:14px;">0</span> 蜀・(遞主挨)
                    </div>

                    <div style="margin-top:10px; display:flex; gap:10px; flex-direction:column;">
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="calcClientEstimate()" style="flex:1; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">蜀崎ｨ育ｮ・/button>
                            <button type="button" onclick="saveAndPrintEstimate()" style="flex:2; background:#ff9800; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">蜊ｰ蛻ｷ逕ｨPDF繧堤匱陦・/button>
                        </div>
                        <button type="button" onclick="sendClientEstimate()" style="width:100%; background:#28a745; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">繝√Ε繝・ヨ縺ｫ隕狗ｩ阪ｒ騾∽ｿ｡</button>
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
                    let msg = `縲先ｦらｮ励♀隕狗ｩ阪ｊ縲曾n讒矩險育ｮ礼ｭ峨・讎らｮ苓ｦ狗ｩ阪ｒ邂怜・縺・◆縺励∪縺励◆縲・n\n`;
                    msg += `遞取栢驥鷹｡・ ${currentEstimate.toLocaleString()}蜀・n`;
                    msg += `豸郁ｲｻ遞・ ${currentTax.toLocaleString()}蜀・n`;
                    msg += `遞手ｾｼ蜷郁ｨ・ ${currentTotal.toLocaleString()}蜀・n\n`;
                    msg += `繧医ｍ縺励￠繧後・豁｣蠑上↓縺比ｾ晞ｼ縺上□縺輔＞縲Ａ;
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
                    
                    // fetch繧堤畑縺・※DB縺ｫ隕狗ｩ肴ュ蝣ｱ繧剃ｿ晏ｭ倥＠縲√◎縺ｮ逶ｴ蠕後↓蛻･繧ｿ繝悶〒蜊ｰ蛻ｷ逕ｨ繝壹・繧ｸ繧帝幕縺・                    const formData = new FormData();
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
                        alert("隕狗ｩ阪ｂ繧翫・菫晏ｭ倥↓螟ｱ謨励＠縺ｾ縺励◆縺後√・繝ｬ繝薙Η繝ｼ繧帝幕縺阪∪縺吶・);
                        window.open(`estimate_print.php?id=<?= $project_id ?>`, '_blank');
                    });
                }

                window.addEventListener('DOMContentLoaded', calcClientEstimate);
                </script>
                <?php endif; ?>

                <h2 class="section-title" style="background:#e67e22; margin-top:20px;">､・蜊泌鴨讌ｭ閠・∈縺ｮ逋ｺ豕ｨ繝ｻ繧ｿ繧ｹ繧ｯ邂｡逅・/h2>
                <div class="box" style="background:#fff9f0;">
                    <div style="font-size:11px; margin-bottom:5px;"><strong>閾ｪ蜍慕匱豕ｨ鬘咲ｮ怜・</strong></div>
                    <div style="display:flex; gap:5px;">
                        <input type="number" id="sub_area" placeholder="髱｢遨・緕｡)" style="width:60px; font-size:12px;">
                        <button type="button" onclick="calcSubcontractorEstimate()" style="font-size:11px; padding:2px 5px;">邂怜・</button>
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
                            '<span style="color:#28a745;font-size:12px;font-weight:bold;">謗ｨ螂ｨ逋ｺ豕ｨ鬘・ ' + total.toLocaleString() + '蜀・/span>';
                        document.querySelector('input[name="order_amount"]').value = total;
                    }
                    </script>

                    <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                        <input type="hidden" name="action" value="order_subcontractor">
                        <select name="subcontractor_id" style="width:100%; margin-bottom:5px; font-size:12px;">
                            <?php foreach($subcontractors as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= $sub['contact_name'] ?> 讒・/option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="task_title" placeholder="萓晞ｼ蜀・ｮｹ・井ｾ具ｼ壽ｧ矩蝗ｳ菴懷峙・・ style="width:100%; margin-bottom:5px; font-size:12px;">
                        <input type="number" name="order_amount" placeholder="驥鷹｡・遞手ｾｼ)" style="width:100%; margin-bottom:5px; font-size:12px;">
                        <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:5px; font-size:12px; cursor:pointer;">逋ｺ豕ｨ繧堤｢ｺ螳壹・騾∽ｿ｡</button>
                    </form>
                </div>
                
                <div style="font-size:11px; color:#555; margin-top:10px;">
                    <h3 style="font-size:12px; border-bottom:1px solid #ccc; margin-top:0;">逋ｺ豕ｨ螻･豁ｴ</h3>
                    <?php foreach($orders as $o): ?>
                        <div style="padding:4px 0; border-bottom:1px solid #eee;">
                            <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?>: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?> (<?= number_format($o['order_amount']) ?>蜀・
                            <span class="badge" style="background:#555;"><?= htmlspecialchars($o['status'], ENT_QUOTES) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?> <!-- 邂｡逅・・お繝ｪ繧｢邨ゆｺ・-->
        </div>
    </div>
</body>
</html>
