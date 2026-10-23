<?php
// index.php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'client', 'accountant']);

// ==========================================
// Google Drive 接続チェック＆自動同期 (管理者のみ)
// ==========================================
$drive_connection_error = false;
if (($_SESSION['role'] ?? '') === 'admin') {
    require_once __DIR__ . '/google_drive_client.php';
    if (check_google_drive_connection()) {
        // 未同期のローカル保存ファイルがある場合のみ Drive への同期を実行
        $stmtPending = $pdo->query("SELECT COUNT(*) FROM project_files WHERE drive_file_id LIKE 'uploads/%'");
        if ($stmtPending && $stmtPending->fetchColumn() > 0) {
            sync_local_files_to_google_drive($pdo);
        }
    } else {
        // 連携切れを検知した場合、警告バナー表示用のフラグを立てる
        $drive_connection_error = true;
    }
}

// 依頼主による見積中案件の非表示（手動アーカイブ）処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_client_project') {
    $project_id = intval($_POST['project_id'] ?? 0);
    if ($project_id > 0) {
        $stmtCheck = $pdo->prepare("SELECT client_id FROM projects WHERE id = :id");
        $stmtCheck->execute(['id' => $project_id]);
        $owner_id = $stmtCheck->fetchColumn();
        
        $my_company_id = $_SESSION['parent_id'] ?: $_SESSION['user_id'];
        if ($_SESSION['role'] === 'client' && (int)$owner_id === (int)$my_company_id) {
            $stmtUpd = $pdo->prepare("UPDATE projects SET is_client_archived = 1 WHERE id = :id");
            $stmtUpd->execute(['id' => $project_id]);
            header("Location: index.php?msg=" . urlencode("見積案件をダッシュボードから非表示にしました。"));
            exit;
        }
    }
}

// 1. ログインユーザーの情報を取得
$current_user_id = $_SESSION['user_id'];
require_once 'Repositories/UserRepository.php';

$userRepo = new UserRepository($pdo);
$current_user = $userRepo->findById($_SESSION['user_id']);
$user_role = $current_user ? $current_user['role'] : '';

// 2. 案件の取得（ロールに応じたフィルタ）
require_once 'Repositories/ProjectRepository.php';
$projectRepo = new ProjectRepository($pdo);

if ($_SESSION['role'] === 'client') {
    // クライアントの場合は、自身または親（企業代表）が依頼主の案件のみ取得
    $client_id_to_fetch = $_SESSION['parent_id'] ?: $current_user_id;
    $projects = $projectRepo->findByClientIdWithClientInfo($client_id_to_fetch);
} else {
    // 管理者または経理の場合は全案件を取得
    $projects = $projectRepo->findAllWithClientInfo();
}

// 各案件のステータスをスケジュール実績に基づいて自己修復同期
foreach ($projects as $pj) {
    syncProjectStatusWithSchedule($pj['id'], $pdo);
}

// 同期後に最新のデータを再フェッチ
if ($_SESSION['role'] === 'client') {
    $projects = $projectRepo->findByClientIdWithClientInfo($client_id_to_fetch);
} else {
    $projects = $projectRepo->findAllWithClientInfo();
}

// ステータスを日本語表示に変換する用の配列
$status_labels = [
                'quote_req'      => '見積依頼',
                'contracted'     => '受注済',
                'primary_prep'   => '一次回答準備中',
                'structural_dwg' => '申請図書作成中',
                'submission'     => '審査・待機',
                'submitting'     => '審査・待機',
                'correction'     => '補正対応中',
                'completed'      => '完了'
            ];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>業務管理ポータル</title>
    <style>
        /* 簡単なデザイン（CSS）を適用します */
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 5px solid #0056b3; }
        .card h3 { margin: 0 0 10px 0; font-size: 18px; color: #0056b3; }
        .badge { display: inline-block; padding: 5px 10px; background: #e9ecef; border-radius: 12px; font-size: 12px; font-weight: bold; margin-bottom: 10px; }
        .client-name { font-size: 14px; color: #666; margin-bottom: 10px; }
        .btn { display: inline-block; padding: 8px 15px; background: #0056b3; color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px; }
        .btn:hover { background: #004494; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; padding: 24px; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; }
        .modal-title { font-size: 16px; font-weight: bold; margin-bottom: 16px; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>💼 <?= ($_SESSION['role'] === 'client') ? '木造住宅設計サポート案件ダッシュボード' : '案件ダッシュボード' ?></h1>
        <div style="display:flex; align-items:center; gap:15px;">
            <div style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '' ?></div>
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'accountant'): ?>
                <a href="subcontractors_list.php" style="font-weight:bold; color:white; background:#3b82f6; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:12px;">👷 協力業者マスター</a>
            <?php endif; ?>
            <a href="completed_projects.php" style="font-weight:bold; color:white; background:#10b981; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:12px;">📂 完了案件DB</a>
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'accountant'): ?>
                <a href="admin_sales.php" style="font-weight:bold; color:white; background:#e67e22; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:12px;">📊 経理・売上管理</a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <div style="display:flex; align-items:center; gap:10px; background:#e8f5e9; border:1px solid #28a745; padding:4px 10px; border-radius:5px; font-size:11px;">
                    <strong>📂 Drive連携:</strong>
                    <?php 
                    $is_service_account = false;
                    $cred_path = __DIR__ . '/credentials.json';
                    if (file_exists($cred_path)) {
                        $cred_data = json_decode(file_get_contents($cred_path), true);
                        if (is_array($cred_data) && isset($cred_data['type']) && $cred_data['type'] === 'service_account') {
                            $is_service_account = true;
                        }
                    }
                    ?>
                    <?php if ($is_service_account): ?>
                        <span style="color:#28a745; font-weight:bold;">🟢 サービスアカウント</span>
                    <?php elseif (file_exists(__DIR__ . '/token.json')): ?>
                        <span style="color:#28a745; font-weight:bold;">🟢 完了 (OAuth)</span>
                        <a href="google_auth.php" target="_blank" style="font-weight:bold; color:white; background:#4285F4; padding:3px 8px; border-radius:4px; text-decoration:none;">認証更新</a>
                        <a href="api_sync_all_calendar.php" style="font-weight:bold; color:white; background:#10b981; padding:3px 8px; border-radius:4px; text-decoration:none; margin-left:5px;" onclick="return confirm('既存の全案件のスケジュールをGoogleカレンダーへ一括再同期します。よろしいですか？')">📅 カレンダー全件同期</a>
                    <?php else: ?>
                        <span style="color:#dc3545; font-weight:bold;">🔴 未連携</span>
                        <a href="google_auth.php" target="_blank" style="font-weight:bold; color:white; background:#4285F4; padding:3px 8px; border-radius:4px; text-decoration:none;">連携ログイン</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div>ログイン中: <?= htmlspecialchars($current_user['contact_name'], ENT_QUOTES) ?> 様 <span style="font-size:11px; background:#4b5563; color:white; padding:2px 6px; border-radius:4px; margin-left:5px;"><?= htmlspecialchars($_SESSION['role'], ENT_QUOTES) ?></span></div>
            <?php
            $user_role = $_SESSION['role'] ?? 'client';
            if ($user_role === 'client') {
                echo '<a href="manual_client.php" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:bold; margin-right:10px;">📖 操作マニュアル (依頼主向け)</a>';
            } elseif ($user_role === 'subcontractor') {
                echo '<a href="manual_subcontractor.php" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:bold; margin-right:10px;">📖 操作マニュアル (協力業者向け)</a>';
            } elseif ($user_role === 'admin' || $user_role === 'accountant') {
                echo '<a href="admin_sales.php" style="font-size:12px; color:#10b981; text-decoration:none; font-weight:bold; margin-right:15px;">📊 経理・売上管理</a>';
                echo '<a href="api_backup_db.php" target="_blank" style="font-size:12px; color:#8b5cf6; text-decoration:none; font-weight:bold; margin-right:15px;" onclick="return confirm(\'現在のデータベースのバックアップ（ZIP圧縮SQL）をダウンロードします。よろしいですか？\')">🗄️ DBバックアップ</a>';
                if ($user_role === 'admin' && file_exists(__DIR__ . '/token.json')) {
                    echo '<a href="api_sync_all_calendar.php" style="font-size:12px; color:#10b981; text-decoration:none; font-weight:bold; margin-right:15px;" onclick="return confirm(\'既存の全案件のスケジュールをGoogleカレンダーへ一括再同期します。よろしいですか？\')">📅 カレンダー全件同期</a>';
                }
                echo '<a href="manual_client.php" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:bold; margin-right:10px;">📖 依頼主マニュアル</a>';
                echo '<a href="manual_subcontractor.php" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:bold; margin-right:10px;">📖 協力業者マニュアル</a>';
            }
            ?>
            <a href="logout.php" style="font-size:12px; color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
        </div>
    </div>

    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'accountant'): ?>
        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
            <a href="index.php" style="padding: 10px 20px; background: #3b82f6; color: white; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; box-shadow: 0 2px 4px rgba(59,130,246,0.3);">📈 進行中案件</a>
            <a href="admin_estimates.php" style="padding: 10px 20px; background: #fff; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#f8fafc'" onmouseout="this.style.backgroundColor='#fff'">
                📝 見積中案件 (<?php
                    $stmtCount = $pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'quote_req' AND is_archived = 0");
                    echo $stmtCount->fetchColumn();
                ?>件)
            </a>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg'])): ?>
        <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:12px 20px; border-radius:6px; margin-bottom:20px; font-size:14px; font-weight:bold;">
            ✅ <?= htmlspecialchars($_GET['msg'], ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <?php 
    // 招待リンク生成の準備
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    $script_dir = str_replace('\\', '/', $script_dir);
    $script_dir = rtrim($script_dir, '/');
    ?>

    <?php if ($_SESSION['role'] === 'admin'): ?>
    <?php
        $invite_url_client = "{$protocol}://{$host}{$script_dir}/register.php?invite_role=client";
    ?>
    <div style="margin-bottom: 20px; display:flex; justify-content:flex-end; gap:10px; align-items:center;">
        <button onclick="openClientManagerModal()" style="background:#4f46e5; color:white; padding:8px 15px; border-radius:4px; border:none; font-size:14px; font-weight:bold; cursor:pointer; box-shadow:0 2px 4px rgba(79,70,229,0.3);">
            👥 依頼主ログインリンク・メアド管理
        </button>
        <button onclick="navigator.clipboard.writeText('<?= $invite_url_client ?>'); alert('新しい依頼主企業をこのシステムへ招待するための登録リンクをコピーしました！\nメールやチャットで依頼主にこのURLを送ってください。');" style="background:#8b5cf6; color:white; padding:8px 15px; border-radius:4px; border:none; font-size:14px; font-weight:bold; cursor:pointer;">
            🏢 新しい依頼主企業を招待
        </button>
    </div>
    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'client'): ?>
    <?php
        // 親の依頼主（企業アカウント代表）のみスタッフ招待リンクを発行できる
        $invite_url_staff = '';
        if (empty($_SESSION['parent_id'])) {
            $invite_url_staff = "{$protocol}://{$host}{$script_dir}/register.php?invite_parent_id=" . $_SESSION['user_id'];
        }
    ?>
    <div style="margin-bottom: 20px; display:flex; justify-content:flex-end; gap:10px;">
        <?php if (!empty($invite_url_staff)): ?>
            <button onclick="navigator.clipboard.writeText('<?= $invite_url_staff ?>'); alert('社内スタッフ（同じ企業アカウントで全案件を共有）を招待するための登録リンクをコピーしました！\nメールやチャットでスタッフにこのURLを送ってください。');" style="background:#8b5cf6; color:white; padding:8px 15px; border-radius:4px; border:none; font-size:14px; font-weight:bold; cursor:pointer;">
                👥 社内スタッフを招待
            </button>
        <?php endif; ?>
        <a href="new_request.php" class="btn" style="background:#28a745; margin:0;">➕ 新規見積・計算依頼</a>
    </div>
    <?php endif; ?>

    <?php
    $isAdminOrAccountant = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'accountant');

    if ($isAdminOrAccountant) {
        $admin_ball_projects = [];
        $client_ball_projects = [];
        $subcontractor_ball_projects = [];

        foreach ($projects as $project) {
            $ball = \App\Helpers\StatusHelper::getBallStatus($project, $pdo, $_SESSION['role'] ?? null);
            $project['_ball_info'] = $ball;
            
            if ($ball['ball_owner'] === 'admin') {
                $admin_ball_projects[] = $project;
            } elseif ($ball['ball_owner'] === 'client' || $ball['ball_owner'] === 'shared_waiting') {
                $client_ball_projects[] = $project;
            } else {
                // subcontractor or others
                $subcontractor_ball_projects[] = $project;
            }
        }
    ?>
        <!-- 1段目：管理者ボール -->
        <div style="margin-bottom: 40px;">
            <h2 style="font-size: 18px; border-bottom: 2px solid #3b82f6; padding-bottom: 8px; margin-bottom: 15px; color: #3b82f6; display: flex; align-items: center; gap: 8px;">
                👤 管理者ボールの案件 (<?= count($admin_ball_projects) ?>件)
            </h2>
            <?php if (empty($admin_ball_projects)): ?>
                <p style="color: #666; font-size: 14px;">該当する案件はありません。</p>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($admin_ball_projects as $project): 
                        $ball = $project['_ball_info'];
                        $current_step = getCurrentStepInfo($project, $pdo);
                    ?>
                        <div class="card" style="border-left: 5px solid <?= $ball['color'] ?>;">
                            <span class="badge"><?= htmlspecialchars(getDynamicStatusLabel($project, $pdo), ENT_QUOTES) ?></span>
                            <span class="badge" style="background-color: <?= $ball['color'] ?>; color: white; font-weight: bold;"><?= htmlspecialchars($ball['label'], ENT_QUOTES) ?></span>
                            <div style="font-size: 11px; color: #475569; margin: 5px 0 8px 0; font-weight: bold;">
                                📅 予定日: <?= !empty($current_step['plan_date']) ? date('Y/m/d', strtotime($current_step['plan_date'])) : '<span style="color:#94a3b8; font-weight:normal;">未設定</span>' ?>
                                <span style="font-size: 10px; color: #64748b; font-weight: normal; display: block; margin-top: 2px;">現在の工程: <?= htmlspecialchars($current_step['step_name'], ENT_QUOTES) ?></span>
                            </div>
                            <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                            <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                            <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
                                <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>; flex:1; text-align:center;">詳細を開く</a>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <button type="button" onclick="openClientLoginLinkModal(<?= $project['id'] ?>, <?= (int)($project['client_id'] ?? 0) ?>)" style="background:#4f46e5; color:white; border:none; padding:8px 10px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; white-space:nowrap; box-shadow:0 1px 3px rgba(79,70,229,0.3);" title="この案件のログインリンク・依頼主ポータルログインリンクを発行">
                                        🔑 ログインリンク
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2段目：依頼主ボール -->
        <div style="margin-bottom: 40px;">
            <h2 style="font-size: 18px; border-bottom: 2px solid #e67e22; padding-bottom: 8px; margin-bottom: 15px; color: #e67e22; display: flex; align-items: center; gap: 8px;">
                👤 依頼主ボールの案件 (<?= count($client_ball_projects) ?>件)
            </h2>
            <?php if (empty($client_ball_projects)): ?>
                <p style="color: #666; font-size: 14px;">該当する案件はありません。</p>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($client_ball_projects as $project): 
                        $ball = $project['_ball_info'];
                        $current_step = getCurrentStepInfo($project, $pdo);
                    ?>
                        <div class="card" style="border-left: 5px solid <?= $ball['color'] ?>;">
                            <span class="badge"><?= htmlspecialchars(getDynamicStatusLabel($project, $pdo), ENT_QUOTES) ?></span>
                            <span class="badge" style="background-color: <?= $ball['color'] ?>; color: white; font-weight: bold;"><?= htmlspecialchars($ball['label'], ENT_QUOTES) ?></span>
                            <div style="font-size: 11px; color: #475569; margin: 5px 0 8px 0; font-weight: bold;">
                                📅 予定日: <?= !empty($current_step['plan_date']) ? date('Y/m/d', strtotime($current_step['plan_date'])) : '<span style="color:#94a3b8; font-weight:normal;">未設定</span>' ?>
                                <span style="font-size: 10px; color: #64748b; font-weight: normal; display: block; margin-top: 2px;">現在の工程: <?= htmlspecialchars($current_step['step_name'], ENT_QUOTES) ?></span>
                            </div>
                            <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                            <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                            <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
                                <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>; flex:1; text-align:center;">詳細を開く</a>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <button type="button" onclick="openClientLoginLinkModal(<?= $project['id'] ?>, <?= (int)($project['client_id'] ?? 0) ?>)" style="background:#4f46e5; color:white; border:none; padding:8px 10px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; white-space:nowrap; box-shadow:0 1px 3px rgba(79,70,229,0.3);" title="この案件のログインリンク・依頼主ポータルログインリンクを発行">
                                        🔑 ログインリンク
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3段目：協力業者・確認機関ボール -->
        <div style="margin-bottom: 40px;">
            <h2 style="font-size: 18px; border-bottom: 2px solid #8b5cf6; padding-bottom: 8px; margin-bottom: 15px; color: #8b5cf6; display: flex; align-items: center; gap: 8px;">
                👷 協力者（協力業者・確認機関）ボールの案件 (<?= count($subcontractor_ball_projects) ?>件)
            </h2>
            <?php if (empty($subcontractor_ball_projects)): ?>
                <p style="color: #666; font-size: 14px;">該当する案件はありません。</p>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($subcontractor_ball_projects as $project): 
                        $ball = $project['_ball_info'];
                        $current_step = getCurrentStepInfo($project, $pdo);
                        
                        // 進行中の協力業者タスクを取得
                        $stmtActiveTask = $pdo->prepare("
                            SELECT task_title, order_type, due_date, expected_delivery_date, status 
                            FROM subcontractor_orders 
                            WHERE project_id = :pid 
                            AND status IN ('requested', 'accepted', 'cb_requested')
                            ORDER BY id DESC LIMIT 1
                        ");
                        $stmtActiveTask->execute(['pid' => $project['id']]);
                        $active_task = $stmtActiveTask->fetch();
                    ?>
                        <div class="card" style="border-left: 5px solid <?= $ball['color'] ?>;">
                            <span class="badge"><?= htmlspecialchars(getDynamicStatusLabel($project, $pdo), ENT_QUOTES) ?></span>
                            <span class="badge" style="background-color: <?= $ball['color'] ?>; color: white; font-weight: bold;"><?= htmlspecialchars($ball['label'], ENT_QUOTES) ?></span>
                            <div style="font-size: 11px; color: #475569; margin: 5px 0 8px 0; font-weight: bold; line-height: 1.4;">
                                <?php if ($active_task): 
                                    $type_label = ($active_task['order_type'] === 'design') ? '意匠図作図' : '構造図作図';
                                    $due_val = !empty($active_task['expected_delivery_date']) ? $active_task['expected_delivery_date'] : $active_task['due_date'];
                                    $due_disp = !empty($due_val) ? date('Y/m/d', strtotime($due_val)) : '未定';
                                ?>
                                    <div style="color: #6d28d9; font-size: 11px; margin-bottom: 2px;">
                                        👷 進行タスク: <strong><?= htmlspecialchars($active_task['task_title'], ENT_QUOTES) ?></strong> (<?= $type_label ?>)
                                        <?php if ($active_task['status'] === 'requested' || $active_task['status'] === 'cb_requested'): ?>
                                            <span style="color: #ef4444; font-weight: bold; margin-left: 5px;">⚠️ 未承諾 (承諾待ち)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="color: #dc2626; font-size: 11px;">
                                        📅 納品予定日: <strong><?= $due_disp ?></strong>
                                    </div>
                                <?php else: ?>
                                    📅 予定日: <?= !empty($current_step['plan_date']) ? date('Y/m/d', strtotime($current_step['plan_date'])) : '<span style="color:#94a3b8; font-weight:normal;">未設定</span>' ?>
                                    <span style="font-size: 10px; color: #64748b; font-weight: normal; display: block; margin-top: 2px;">現在の工程: <?= htmlspecialchars($current_step['step_name'], ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                            <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                            <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
                                <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>; flex:1; text-align:center;">詳細を開く</a>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <button type="button" onclick="openClientLoginLinkModal(<?= $project['id'] ?>, <?= (int)($project['client_id'] ?? 0) ?>)" style="background:#4f46e5; color:white; border:none; padding:8px 10px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; white-space:nowrap; box-shadow:0 1px 3px rgba(79,70,229,0.3);" title="この案件のログインリンク・依頼主ポータルログインリンクを発行">
                                        🔑 ログインリンク
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php } else { ?>
        <div class="grid">
            <?php foreach ($projects as $project): 
                $ball = \App\Helpers\StatusHelper::getBallStatus($project, $pdo, $_SESSION['role'] ?? null);
                $current_step = getCurrentStepInfo($project, $pdo);
            ?>
                <div class="card" style="border-left: 5px solid <?= $ball['color'] ?>;">
                    <span class="badge"><?= htmlspecialchars(getDynamicStatusLabel($project, $pdo), ENT_QUOTES) ?></span>
                    <span class="badge" style="background-color: <?= $ball['color'] ?>; color: white; font-weight: bold;"><?= htmlspecialchars($ball['label'], ENT_QUOTES) ?></span>
                    <div style="font-size: 11px; color: #475569; margin: 5px 0 8px 0; font-weight: bold;">
                        📅 予定日: <?= !empty($current_step['plan_date']) ? date('Y/m/d', strtotime($current_step['plan_date'])) : '<span style="color:#94a3b8; font-weight:normal;">未設定</span>' ?>
                        <span style="font-size: 10px; color: #64748b; font-weight: normal; display: block; margin-top: 2px;">現在の工程: <?= htmlspecialchars($current_step['step_name'], ENT_QUOTES) ?></span>
                    </div>
                    <h3><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h3>
                    <?php if (($_SESSION['role'] ?? '') !== 'client'): ?>
                        <div class="client-name">🏢 依頼主: <?= htmlspecialchars($project['company_name'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>; flex: 1; text-align: center;">詳細を開く</a>
                        <?php if (($_SESSION['role'] ?? '') === 'client' && $project['status'] === 'quote_req'): ?>
                            <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('この見積案件をダッシュボードから非表示にしますか？\n※案件自体は削除されず、後から復旧も可能です。');">
                                <input type="hidden" name="action" value="archive_client_project">
                                <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                                <button type="submit" class="btn" style="background-color: #64748b; font-size: 12px; height: 100%;">📦 非表示</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php } ?>

    <!-- Google Drive 連携切れ警告バナー (管理者のみ・非ブロッキング) -->
    <?php if ($_SESSION['role'] === 'admin' && !empty($drive_connection_error)): ?>
    <div style="background:#fef2f2; border:2px solid #ef4444; border-radius:8px; padding:15px 20px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:15px; box-shadow:0 2px 4px rgba(239,68,68,0.1);">
        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:24px;">⚠️</span>
            <div>
                <strong style="color:#b91c1c; font-size:14px; display:block;">Google Drive の連携が切れています</strong>
                <span style="color:#475569; font-size:12px;">OAuth2 認証の有効期限が切れているか、連携が解除されています。ファイルの自動保存や見積書発行を正常に行うには再連携してください。</span>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-shrink:0;">
            <a href="google_auth.php" target="_blank" style="padding:8px 16px; background:#ef4444; color:#fff; border-radius:6px; font-size:12px; font-weight:bold; text-decoration:none; box-shadow:0 2px 4px rgba(239,68,68,0.2);">
                🔄 再連携する (Googleログイン)
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
    <!-- ===== 依頼主ログインリンク発行モーダル ===== -->
    <div class="modal-overlay" id="clientLoginLinkModal">
        <div class="modal-box" style="max-width:580px; text-align:left;">
            <div class="modal-title" style="display:flex; justify-content:space-between; align-items:center;">
                <span>🔑 依頼主ログインリンクの発行・設定</span>
                <span style="cursor:pointer; color:#94a3b8; font-size:16px;" onclick="closeClientLoginLinkModal()">✕</span>
            </div>
            
            <div id="clientLoginLinkLoading" style="padding:20px; text-align:center; color:#64748b; font-size:13px;">
                ⏳ リンクを発行中...
            </div>
            
            <div id="clientLoginLinkContent" style="display:none; font-size:13px;">
                <div style="background:#f1f5f9; padding:10px 14px; border-radius:6px; margin-bottom:15px; font-size:12px; border:1px solid #e2e8f0;">
                    <div>🏢 <strong>依頼主:</strong> <span id="clm_company"></span>（<span id="clm_contact"></span> 様）</div>
                    <div id="clm_project_info_row" style="margin-top:4px; display:none;">
                        📁 <strong>対象案件:</strong> <span id="clm_project_name"></span>
                    </div>
                    <div style="margin-top:6px; display:flex; align-items:center; gap:8px;">
                        <span>✉️ <strong>登録メール:</strong></span>
                        <input type="email" id="clm_email_input" style="flex:1; padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
                        <button type="button" onclick="updateClientEmailAndRegenerate()" style="background:#2563eb; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer; white-space:nowrap;">メアド変更して再発行</button>
                    </div>
                    <div style="margin-top:6px; color:#10b981; font-weight:bold; font-size:11px;">
                        ⏱️ 有効期限: 発行から7日間（パスワード不要でワンクリックログイン）
                    </div>
                </div>

                <!-- 1. 個別案件ダッシュボードリンク -->
                <div id="clm_project_block" style="margin-bottom:15px; display:none;">
                    <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px; color:#1e293b;">
                        📌 ① 個別の案件ダッシュボードへのログインリンク
                    </label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" id="clm_project_url" readonly style="flex:1; padding:6px 8px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:4px; font-size:11px; font-family:monospace; color:#334155;" onclick="this.select()">
                        <button type="button" onclick="copyLoginLink('clm_project_url', '案件ダッシュボードのログインリンクをコピーしました！')" style="background:#0284c7; color:white; border:none; padding:6px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer; white-space:nowrap;">
                            📋 コピー
                        </button>
                    </div>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">※クリックするとこの案件詳細画面が直接開きます。</div>
                </div>

                <!-- 2. 依頼者ポータルリンク -->
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px; color:#1e293b;">
                        🏢 ② 依頼者の全案件ポータルへのログインリンク
                    </label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" id="clm_portal_url" readonly style="flex:1; padding:6px 8px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:4px; font-size:11px; font-family:monospace; color:#334155;" onclick="this.select()">
                        <button type="button" onclick="copyLoginLink('clm_portal_url', '依頼者ポータルのログインリンクをコピーしました！')" style="background:#0284c7; color:white; border:none; padding:6px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer; white-space:nowrap;">
                            📋 コピー
                        </button>
                    </div>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">※クリックすると依頼主が管理している全案件の一覧ポータル（index.php）が開きます。</div>
                </div>

                <!-- 3. 案内文テンプレート丸ごとコピー -->
                <div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:6px; padding:10px 12px; margin-bottom:15px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="font-weight:bold; font-size:12px; color:#92400e;">✉️ メール・チャット案内用メッセージ</span>
                        <button type="button" onclick="copyClientLoginTemplate()" style="background:#d97706; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">
                            📋 案内文を丸ごとコピー
                        </button>
                    </div>
                    <textarea id="clm_template_text" readonly style="width:100%; height:90px; padding:6px; border:1px solid #fde68a; border-radius:4px; font-size:11px; box-sizing:border-box; background:white; font-family:sans-serif; resize:vertical;"></textarea>
                </div>

                <div style="text-align:right;">
                    <button type="button" onclick="closeClientLoginLinkModal()" style="padding:6px 16px; background:#64748b; color:white; border:none; border-radius:4px; font-size:12px; cursor:pointer;">閉じる</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 依頼主一覧・メアド管理モーダル ===== -->
    <div class="modal-overlay" id="clientManagerModal">
        <div class="modal-box" style="max-width:800px; text-align:left;">
            <div class="modal-title" style="display:flex; justify-content:space-between; align-items:center;">
                <span>👥 依頼主ログインリンク・メールアドレス管理</span>
                <span style="cursor:pointer; color:#94a3b8; font-size:16px;" onclick="closeClientManagerModal()">✕</span>
            </div>

            <div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
                <input type="text" id="clientSearchInput" oninput="filterClientsList()" placeholder="🔍 会社名、担当者名、メールアドレスで検索..." style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                <button type="button" onclick="loadClientsList()" style="background:#f1f5f9; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:bold; color:#475569;">🔄 更新</button>
            </div>

            <div id="clientsListLoading" style="padding:30px; text-align:center; color:#64748b; font-size:13px;">
                ⏳ 依頼主一覧を読み込み中...
            </div>

            <div id="clientsListContainer" style="display:none; max-height:450px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1; color:#475569; position:sticky; top:0; z-index:10;">
                            <th style="padding:8px 10px;">ID</th>
                            <th style="padding:8px 10px;">会社名 / 担当者</th>
                            <th style="padding:8px 10px;">登録メールアドレス</th>
                            <th style="padding:8px 10px; text-align:center;">案件数</th>
                            <th style="padding:8px 10px; text-align:center;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTableBody">
                    </tbody>
                </table>
            </div>

            <div style="margin-top:15px; text-align:right;">
                <button type="button" onclick="closeClientManagerModal()" style="padding:6px 16px; background:#64748b; color:white; border:none; border-radius:4px; font-size:12px; cursor:pointer;">閉じる</button>
            </div>
        </div>
    </div>

    <script>
    let currentClmProjectId = 0;
    let currentClmUserId = 0;
    let allClientsData = [];

    function openClientLoginLinkModal(projectId = 0, userId = 0) {
        currentClmProjectId = projectId || 0;
        currentClmUserId = userId || 0;

        const modal = document.getElementById('clientLoginLinkModal');
        const loading = document.getElementById('clientLoginLinkLoading');
        const content = document.getElementById('clientLoginLinkContent');

        modal.classList.add('active');
        loading.style.display = 'block';
        content.style.display = 'none';

        fetchLoginLinkData();
    }

    function closeClientLoginLinkModal() {
        document.getElementById('clientLoginLinkModal').classList.remove('active');
    }

    function fetchLoginLinkData(newEmail = null) {
        const payload = {
            project_id: currentClmProjectId,
            user_id: currentClmUserId
        };
        if (newEmail !== null) {
            payload.new_email = newEmail;
        }

        fetch('api_generate_client_login_url.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('エラー: ' + (data.error || 'ログインリンクの生成に失敗しました。'));
                closeClientLoginLinkModal();
                return;
            }
            document.getElementById('clientLoginLinkLoading').style.display = 'none';
            document.getElementById('clientLoginLinkContent').style.display = 'block';

            document.getElementById('clm_company').textContent = data.company_name || '';
            document.getElementById('clm_contact').textContent = data.contact_name || '';
            document.getElementById('clm_email_input').value = data.email || '';
            document.getElementById('clm_portal_url').value = data.portal_login_url || '';

            const pBlock = document.getElementById('clm_project_block');
            const pRow = document.getElementById('clm_project_info_row');
            if (data.project_login_url) {
                pBlock.style.display = 'block';
                pRow.style.display = 'block';
                document.getElementById('clm_project_name').textContent = data.project_name || '';
                document.getElementById('clm_project_url').value = data.project_login_url;
            } else {
                pBlock.style.display = 'none';
                pRow.style.display = 'none';
            }

            currentClmUserId = data.user_id;

            // 案内文テンプレート作成
            const tmpl = `${data.contact_name || data.company_name} 様\n\n平素より大変お世話になっております。\n木造住宅設計サポートのログインリンクを発行いたしました。\n下記URLをクリックいただくだけで、直接ログインいただけます。\n\n` +
                (data.project_login_url ? `■ 本案件ダッシュボード:\n${data.project_login_url}\n\n` : '') +
                `■ 依頼主ポータル（全案件一覧）:\n${data.portal_login_url}\n\n` +
                `※このリンクは発行から7日間有効です。`;
            document.getElementById('clm_template_text').value = tmpl;

            if (data.email_updated) {
                alert('登録メールアドレスを「' + data.email + '」に更新し、新しいログインリンクを発行しました！');
                // クライアント一覧も再読み込み
                if (allClientsData.length > 0) {
                    loadClientsList();
                }
            }
        })
        .catch(err => {
            alert('通信エラーが発生しました: ' + err);
            closeClientLoginLinkModal();
        });
    }

    function updateClientEmailAndRegenerate() {
        const newEmail = document.getElementById('clm_email_input').value.trim();
        if (!newEmail) {
            alert('メールアドレスを入力してください。');
            return;
        }
        if (!confirm('依頼主の登録メールアドレスを「' + newEmail + '」に変更し、新しいログインリンクを再発行します。よろしいですか？')) {
            return;
        }
        document.getElementById('clientLoginLinkLoading').style.display = 'block';
        document.getElementById('clientLoginLinkContent').style.display = 'none';
        fetchLoginLinkData(newEmail);
    }

    function copyLoginLink(elementId, msg) {
        const el = document.getElementById(elementId);
        el.select();
        navigator.clipboard.writeText(el.value).then(() => {
            alert(msg);
        });
    }

    function copyClientLoginTemplate() {
        const el = document.getElementById('clm_template_text');
        el.select();
        navigator.clipboard.writeText(el.value).then(() => {
            alert('メール・チャット用案内メッセージをコピーしました！\nそのままメールやチャットに貼り付けてご案内いただけます。');
        });
    }

    // ===== 依頼主一覧モーダル関連 =====
    function openClientManagerModal() {
        document.getElementById('clientManagerModal').classList.add('active');
        loadClientsList();
    }

    function closeClientManagerModal() {
        document.getElementById('clientManagerModal').classList.remove('active');
    }

    function loadClientsList() {
        const loading = document.getElementById('clientsListLoading');
        const container = document.getElementById('clientsListContainer');
        loading.style.display = 'block';
        container.style.display = 'none';

        fetch('api_get_clients.php')
        .then(res => res.json())
        .then(data => {
            loading.style.display = 'none';
            container.style.display = 'block';
            if (data.success && Array.isArray(data.clients)) {
                allClientsData = data.clients;
                renderClientsTable(allClientsData);
            } else {
                alert('依頼主一覧の取得に失敗しました。');
            }
        })
        .catch(err => {
            loading.style.display = 'none';
            alert('通信エラー: ' + err);
        });
    }

    function renderClientsTable(clients) {
        const tbody = document.getElementById('clientsTableBody');
        tbody.innerHTML = '';
        if (clients.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;">該当する依頼主が見つかりません。</td></tr>';
            return;
        }

        clients.forEach(c => {
            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid #f1f5f9';
            tr.innerHTML = `
                <td style="padding:8px 10px; font-weight:bold; color:#64748b;">${c.id}</td>
                <td style="padding:8px 10px;">
                    <div style="font-weight:bold; color:#1e293b;">${escapeHtml(c.company_name || '名称未設定')}</div>
                    <div style="font-size:11px; color:#64748b;">${escapeHtml(c.contact_name || '')} 様</div>
                </td>
                <td style="padding:8px 10px;">
                    <span style="font-family:monospace; color:#0f766e;">${escapeHtml(c.email || '未設定')}</span>
                </td>
                <td style="padding:8px 10px; text-align:center;">
                    <span style="background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:11px;">${c.project_count}件</span>
                </td>
                <td style="padding:8px 10px; text-align:center; white-space:nowrap;">
                    <button type="button" onclick="openClientLoginLinkModal(0, ${c.id})" style="background:#4f46e5; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;" title="ログインリンク発行・メアド編集">
                        🔑 リンク発行 / 設定
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function filterClientsList() {
        const query = document.getElementById('clientSearchInput').value.toLowerCase().trim();
        if (!query) {
            renderClientsTable(allClientsData);
            return;
        }
        const filtered = allClientsData.filter(c => {
            const comp = (c.company_name || '').toLowerCase();
            const cont = (c.contact_name || '').toLowerCase();
            const mail = (c.email || '').toLowerCase();
            return comp.includes(query) || cont.includes(query) || mail.includes(query);
        });
        renderClientsTable(filtered);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    </script>
    <?php endif; ?>

</body>
</html>