<?php
// admin_estimates.php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'accountant']);

$current_user_id = $_SESSION['user_id'];
require_once 'Repositories/UserRepository.php';
$userRepo = new UserRepository($pdo);
$current_user = $userRepo->findById($current_user_id);
$is_admin = ($_SESSION['role'] === 'admin');

// 案件アーカイブ復帰アクションの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_estimate') {
    $project_id = intval($_POST['project_id'] ?? 0);
    if ($project_id > 0) {
        $pdo->beginTransaction();
        try {
            // 見積情報（初期見積、本見積、追加見積）をリセットし、アーカイブを解除
            $stmt = $pdo->prepare("
                UPDATE projects 
                SET is_archived = 0, 
                    is_client_archived = 0,
                    initial_est_amount = NULL,
                    initial_est_date = NULL,
                    formal_est_amount = NULL,
                    formal_est_date = NULL,
                    add_est_amount = 0,
                    add_est_date = NULL,
                    deposit_amount = 0,
                    deposit_date = NULL,
                    deposit_amount_50 = 0,
                    deposit_amount_rem = 0,
                    deposit_date_50 = NULL,
                    deposit_date_rem = NULL,
                    additional_estimates = NULL,
                    additional_deposits = NULL
                WHERE id = :pid
            ");
            $stmt->execute(['pid' => $project_id]);

            // 過去の見積書履歴（estimates）も削除し完全に初期から再スタートさせる
            $stmtDelEst = $pdo->prepare("DELETE FROM estimates WHERE project_id = :pid");
            $stmtDelEst->execute(['pid' => $project_id]);

            // スケジュール実績もリセット (最初の「設計図書の受領」以外の実績日を消す)
            $stmtGetAct = $pdo->prepare("SELECT req_permit, req_wall, req_skin, req_sky, req_opt_kisohari, schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :pid");
            $stmtGetAct->execute(['pid' => $project_id]);
            $act_row = $stmtGetAct->fetch(PDO::FETCH_ASSOC);
            if ($act_row) {
                $cols = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                foreach ($cols as $col) {
                    $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
                    $new_actuals = [];
                    if (isset($actuals[0])) {
                        $new_actuals[0] = $actuals[0]; // 設計図書の受領日だけ引き継ぐ
                    }
                    $stmtUpd = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                    $stmtUpd->execute(['act' => json_encode($new_actuals, JSON_FORCE_OBJECT), 'pid' => $project_id]);
                }
            }

            $pdo->commit();
            header("Location: admin_estimates.php?msg=" . urlencode("案件を再見積もりとして復旧しました。"));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            die("復旧処理に失敗しました: " . $e->getMessage());
        }
    }
}

// 1. アクティブな見積中案件の取得 (is_archived = 0 かつ is_client_archived = 0 かつ status = 'quote_req')
$stmtEst = $pdo->prepare("
    SELECT p.*, u.company_name 
    FROM projects p 
    JOIN users u ON p.client_id = u.id 
    WHERE p.status = 'quote_req'
    AND p.is_archived = 0
    AND p.is_client_archived = 0
    ORDER BY p.id DESC
");
$stmtEst->execute();
$estimate_projects = $stmtEst->fetchAll(PDO::FETCH_ASSOC);

// 2. アーカイブ済みの見積中案件の取得 (is_archived = 1 または is_client_archived = 1 で status = 'quote_req')
$stmtArch = $pdo->prepare("
    SELECT p.*, u.company_name 
    FROM projects p 
    JOIN users u ON p.client_id = u.id 
    WHERE p.status = 'quote_req'
    AND (p.is_archived = 1 OR p.is_client_archived = 1)
    ORDER BY p.id DESC
");
$stmtArch->execute();
$archived_projects = $stmtArch->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>見積中案件ダッシュボード | 木造住宅設計サポート</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&family=Noto+Sans+JP:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Outfit', 'Noto Sans JP', sans-serif; background-color: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; }
        
        .tab-menu { margin-bottom: 20px; display: flex; gap: 10px; }
        .tab-btn { padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .tab-btn.inactive { background: #fff; color: #475569; border: 1px solid #cbd5e1; transition: background-color 0.2s; }
        .tab-btn.inactive:hover { background-color: #f8fafc; }
        .tab-btn.active { background: #3b82f6; color: white; border: none; box-shadow: 0 2px 4px rgba(59,130,246,0.3); }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #64748b; position: relative; }
        .card.archived { border-left-color: #94a3b8; opacity: 0.85; }
        .card h3 { margin: 0 0 10px 0; font-size: 16px; color: #1e293b; }
        .badge { display: inline-block; padding: 4px 8px; background: #e2e8f0; border-radius: 12px; font-size: 11px; font-weight: bold; margin-bottom: 10px; color: #475569; }
        .client-name { font-size: 13px; color: #64748b; margin-bottom: 10px; font-weight: bold; }
        .btn { display: inline-block; padding: 6px 12px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; border: none; }
        .btn:hover { background: #2563eb; }
        .btn-restore { background: #10b981; }
        .btn-restore:hover { background: #059669; }

        .accordion { background-color: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .accordion-header { padding: 15px 20px; font-weight: bold; font-size: 15px; color: #475569; cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; }
        .accordion-content { padding: 20px; border-top: 1px solid #e2e8f0; display: none; background: #f8fafc; }
        .accordion.open .accordion-content { display: block; }
    </style>
</head>
<body>

    <div class="header">
        <h1>💼 見積中案件ダッシュボード</h1>
        <div style="display:flex; align-items:center; gap:15px;">
            <div style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= SYSTEM_VERSION ?></div>
            <a href="index.php" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:bold; margin-right:10px;">➔ メインダッシュボードへ戻る</a>
            <a href="logout.php" style="font-size:12px; color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:12px 20px; border-radius:6px; margin-bottom:20px; font-size:14px; font-weight:bold;">
            ✅ <?= htmlspecialchars($_GET['msg'], ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <div class="tab-menu">
        <a href="index.php" class="tab-btn inactive">📈 進行中案件</a>
        <a href="admin_estimates.php" class="tab-btn active">📝 見積中案件 (<?= count($estimate_projects) ?>件)</a>
    </div>

    <h2>📝 見積依頼・計算シミュレーション中の一覧</h2>

    <?php if (empty($estimate_projects)): ?>
        <div style="background:#fff; padding:30px; border-radius:8px; text-align:center; color:#64748b; border:1px dashed #cbd5e1;">
            現在、新規の見積依頼中案件はありません。
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($estimate_projects as $project): ?>
                <div class="card">
                    <span class="badge">見積中</span>
                    <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                    <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                    <div style="font-size: 11px; color:#94a3b8; margin-bottom: 15px;">
                        作成日: <?= date('Y/m/d H:i', strtotime($project['created_at'])) ?><br>
                        <?php if (!empty($project['initial_est_date'])): ?>
                            見積提示日: <?= date('Y/m/d', strtotime($project['initial_est_date'])) ?>
                        <?php endif; ?>
                    </div>
                    <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn">案件詳細 / 見積シミュレーター ➔</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- アーカイブ案件アコーディオン -->
    <div class="accordion" id="archiveAccordion">
        <div class="accordion-header" onclick="toggleAccordion()">
            <span>📁 見積アーカイブ一覧 (自動アーカイブおよび依頼主非表示案件: <?= count($archived_projects) ?>件)</span>
            <span id="accordionArrow">▼</span>
        </div>
        <div class="accordion-content">
            <?php if (empty($archived_projects)): ?>
                <div style="text-align:center; color:#94a3b8; font-size:13px; padding:20px 0;">
                    アーカイブされた見積案件はありません。
                </div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($archived_projects as $project): 
                        $reason = $project['is_client_archived'] ? '依頼主による非表示' : '30日経過による自動アーカイブ';
                    ?>
                        <div class="card archived">
                            <span class="badge" style="background:#cbd5e1;"><?= $reason ?></span>
                            <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                            <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                            <div style="font-size: 11px; color:#94a3b8; margin-bottom:15px;">
                                作成日: <?= date('Y/m/d', strtotime($project['created_at'])) ?><br>
                                <?php if (!empty($project['initial_est_date'])): ?>
                                    当初見積日: <?= date('Y/m/d', strtotime($project['initial_est_date'])) ?>
                                <?php endif; ?>
                            </div>
                            
                            <form method="POST" style="margin:0;" onsubmit="return confirm('この案件を再見積もりとして復旧させます。よろしいですか？\n※以前の見積もり履歴とスケジュール実績日はクリアされます。');">
                                <input type="hidden" name="action" value="restore_estimate">
                                <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                                <button type="submit" class="btn btn-restore">♻️ 復旧して再見積もりする</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function toggleAccordion() {
        const acc = document.getElementById('archiveAccordion');
        const arrow = document.getElementById('accordionArrow');
        acc.classList.toggle('open');
        if (acc.classList.contains('open')) {
            arrow.textContent = '▲';
        } else {
            arrow.textContent = '▼';
        }
    }
    </script>
</body>
</html>
