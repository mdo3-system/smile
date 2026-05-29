<?php
// project_detail.php
require_once 'db_connect.php';
require_once 'functions.php';

$current_user_id = 1;
$is_admin = true;

$project_id = $_GET['id'] ?? null;
if (!$project_id) { die("案件が指定されていません。"); }

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
    
    // ...（既存のspecs保存、スケジュール保存、ファイルUP処理はそのまま）...
    // 【重要】既存のPOST処理をここに追加してください。省略しましたが前回と同じ内容です。
}

// ==========================================
// データ取得
// ==========================================
// 協力業者一覧を取得
$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// この案件への発注履歴を取得
$stmt = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid");
$stmt->execute(['pid' => $project_id]);
$orders = $stmt->fetchAll();

// ...（以降、前回までと同じ表示ロジック）...
?>

<!DOCTYPE html>
<html lang="ja">
<body>
    <div class="container">
        <div class="column col-right">
            <h2 class="section-title" style="background:#e67e22;">🤝 協力業者への発注・タスク管理</h2>
            
            <div class="box" style="background:#fff9f0;">
                <div style="font-size:11px; margin-bottom:5px;"><strong>自動見積シミュレーター</strong></div>
                <div style="display:flex; gap:5px;">
                    <input type="number" id="sub_area" placeholder="面積(㎡)" style="width:60px; font-size:12px;">
                    <button type="button" onclick="calcSubcontractorEstimate()" style="font-size:11px; padding:2px 5px;">算出</button>
                </div>
                <div id="sub_calc_result" style="margin-bottom:10px;"></div>

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

            <div style="font-size:11px; color:#555;">
                <h3 style="font-size:12px; border-bottom:1px solid #ccc; margin-top:0;">発注履歴</h3>
                <?php foreach($orders as $o): ?>
                    <div style="padding:4px 0; border-bottom:1px solid #eee;">
                        <?= $o['contact_name'] ?>: <?= $o['task_title'] ?> (<?= number_format($o['order_amount']) ?>円)
                        <span class="badge" style="background:#555;"><?= $o['status'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 class="section-title" style="background:#17a2b8; margin-top:20px;">💬 対 依頼主チャット</h2>
            </div>
    </div>
</body>
</html>