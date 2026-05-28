<?php
// project_detail.php
require_once 'db_connect.php';
require_once 'functions.php';

$current_user_id = 1;
$is_admin = true;

$project_id = $_GET['id'] ?? null;
if (!$project_id) { die("譯井ｻｶ縺梧欠螳壹＆繧後※縺・∪縺帙ｓ縲・); }

// ==========================================
// POST蜃ｦ逅・ｼ育匱豕ｨ萓晞ｼ縺ｮ逋ｻ骭ｲ縺ｪ縺ｩ・・// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 譁ｰ隕冗匱豕ｨ萓晞ｼ縺ｮ菫晏ｭ・    if ($action === 'order_subcontractor') {
        $stmt = $pdo->prepare("INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount) VALUES (:pid, :sub_id, :task, :amount)");
        $stmt->execute([
            'pid' => $project_id,
            'sub_id' => $_POST['subcontractor_id'],
            'task' => $_POST['task_title'],
            'amount' => $_POST['order_amount']
        ]);
        header("Location: project_detail.php?id=" . $project_id . "&t=" . time()); exit;
    }
    
    // ...・域里蟄倥・specs菫晏ｭ倥√せ繧ｱ繧ｸ繝･繝ｼ繝ｫ菫晏ｭ倥√ヵ繧｡繧､繝ｫUP蜃ｦ逅・・縺昴・縺ｾ縺ｾ・・..
    // 縲宣㍾隕√第里蟄倥・POST蜃ｦ逅・ｒ縺薙％縺ｫ霑ｽ蜉縺励※縺上□縺輔＞縲ら怐逡･縺励∪縺励◆縺悟燕蝗槭→蜷後§蜀・ｮｹ縺ｧ縺吶・}

// ==========================================
// 繝・・繧ｿ蜿門ｾ・// ==========================================
// 蜊泌鴨讌ｭ閠・ｸ隕ｧ繧貞叙蠕・$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// 縺薙・譯井ｻｶ縺ｸ縺ｮ逋ｺ豕ｨ螻･豁ｴ繧貞叙蠕・$stmt = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid");
$stmt->execute(['pid' => $project_id]);
$orders = $stmt->fetchAll();

// ...・井ｻ･髯阪∝燕蝗槭∪縺ｧ縺ｨ蜷後§陦ｨ遉ｺ繝ｭ繧ｸ繝・け・・..
?>

<!DOCTYPE html>
<html lang="ja">
<body>
    <div class="container">
        <div class="column col-right">
            <h2 class="section-title" style="background:#e67e22;">､・蜊泌鴨讌ｭ閠・∈縺ｮ逋ｺ豕ｨ繝ｻ繧ｿ繧ｹ繧ｯ邂｡逅・/h2>
            
            <div class="box" style="background:#fff9f0;">
                <div style="font-size:11px; margin-bottom:5px;"><strong>閾ｪ蜍戊ｦ狗ｩ阪す繝溘Η繝ｬ繝ｼ繧ｿ繝ｼ</strong></div>
                <div style="display:flex; gap:5px;">
                    <input type="number" id="sub_area" placeholder="髱｢遨・緕｡)" style="width:60px; font-size:12px;">
                    <button type="button" onclick="calcSubcontractorEstimate()" style="font-size:11px; padding:2px 5px;">邂怜・</button>
                </div>
                <div id="sub_calc_result" style="margin-bottom:10px;"></div>

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

            <div style="font-size:11px; color:#555;">
                <h3 style="font-size:12px; border-bottom:1px solid #ccc; margin-top:0;">逋ｺ豕ｨ螻･豁ｴ</h3>
                <?php foreach($orders as $o): ?>
                    <div style="padding:4px 0; border-bottom:1px solid #eee;">
                        <?= $o['contact_name'] ?>: <?= $o['task_title'] ?> (<?= number_format($o['order_amount']) ?>蜀・
                        <span class="badge" style="background:#555;"><?= $o['status'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 class="section-title" style="background:#17a2b8; margin-top:20px;">町 蟇ｾ 萓晞ｼ荳ｻ繝√Ε繝・ヨ</h2>
            </div>
    </div>
</body>
</html>
