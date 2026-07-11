<?php
// index.php
require_once 'auth.php';
require_once 'functions.php';
check_auth(['admin', 'client', 'accountant']);

// ==========================================
// Google Drive 接続チェック＆自動同期 (管理者のみ)
// ==========================================
$drive_connection_error = false;
if ($_SESSION['role'] === 'admin') {
    require_once __DIR__ . '/google_drive_client.php';
    if (check_google_drive_connection()) {
        // 連携が正常な場合、ローカル保存されている未同期ファイルを Drive へ自動転送（データ移動）
        sync_local_files_to_google_drive($pdo);
    } else {
        // 連携切れを検知した場合、警告モーダル表示用のフラグを立てる
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
    <div style="margin-bottom: 20px; text-align: right;">
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
                            <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>;">詳細を開く</a>
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
                            <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>;">詳細を開く</a>
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
                            <a href="project_detail.php?id=<?= $project['id'] ?>" class="btn" style="background-color: <?= $ball['color'] ?>;">詳細を開く</a>
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

    <!-- Google Drive 連携切れ警告モーダル (管理者のみ) -->
    <?php if ($_SESSION['role'] === 'admin' && !empty($drive_connection_error)): ?>
    <div id="driveErrorModal" style="display:flex; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:#fff; border-radius:16px; padding:32px; max-width:500px; width:90%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.4); border-top: 6px solid #ef4444;">
            <div style="font-size:48px; margin-bottom:16px;">⚠️</div>
            <h2 style="font-size:20px; margin-bottom:12px; color:#b91c1c; font-weight:bold;">Google Drive の連携が切れています</h2>
            <p style="font-size:14px; color:#475569; line-height:1.8; margin-bottom:20px; text-align:left; background:#fef2f2; padding:15px; border-radius:8px;">
                Google Drive との OAuth2 認証の有効期限が切れているか、連携が解除されています。<br>
                このままでは見積書や請求書の発行、またはファイルの自動保存が正常に行われません。<br><br>
                連携を回復するには、下の「再連携する」ボタンをクリックして再ログイン認証を行ってください。
            </p>
            <div style="display:flex; gap:10px; justify-content:center;">
                <a href="google_auth.php" style="display:inline-block; padding:12px 30px; background:#ef4444; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:bold; cursor:pointer; text-decoration:none; box-shadow:0 4px 6px rgba(239,68,68,0.2);">
                    🔄 再連携する (Googleログイン)
                </a>
                <button onclick="document.getElementById('driveErrorModal').style.display='none';" style="padding:12px 20px; background:#94a3b8; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:bold; cursor:pointer;">
                    閉じる
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>