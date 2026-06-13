<?php
// project_detail.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'client', 'accountant']);

$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$is_accountant = ($_SESSION['role'] === 'accountant');
$has_finance_access = ($is_admin || $is_accountant);

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

require_once 'Repositories/ProjectRepository.php';
$projectRepo = new ProjectRepository($pdo);

require_once __DIR__ . '/actions/project_detail_post.php';

// ==========================================
// データ取得
// ==========================================
// 案件と仕様情報を取得
$stmtProj = $pdo->prepare("
    SELECT p.*, s.*, u.company_name, u.contact_name as client_name, u.phone_number as client_phone
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

$upload_mode = $project_info['upload_mode'] ?? 'individual';

// 見積情報（全履歴）の取得
$stmtAllEst = $pdo->prepare("SELECT * FROM estimates WHERE project_id = :pid ORDER BY id DESC");
$stmtAllEst->execute(['pid' => $project_id]);
$all_estimates = $stmtAllEst->fetchAll();

// 案件に関連する全ファイル（最新のみ）を取得 (依頼主提出物用)
$stmtFiles = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND is_latest = 1 ORDER BY version DESC, id DESC");
$stmtFiles->execute(['pid' => $project_id]);
$all_files = $stmtFiles->fetchAll();

// カテゴリごとに整理 (最新のもの。複数ファイル対応のため配列化)
$files_by_cat = [];
foreach($all_files as $f) {
    if (!isset($files_by_cat[$f['file_category']])) {
        $files_by_cat[$f['file_category']] = [];
    }
    $files_by_cat[$f['file_category']][] = $f;
}

// 成果物の全履歴を取得 (成果物管理パネル用)
$artifact_categories = [
    'structural_dwg', 'standard_dwg', 'calc_doc', 'safety_cert', 'inv_primary', 'inv_primary_rev',
    'wall_calc_doc', 'wall_kiso_dwg', 'wall_perf_doc',
    'skin_calc_doc', 'skin_energy_doc', 'skin_desc_doc',
    'sky_calc_doc', 'sky_dwg', 'other_artifact'
];
$placeholders = implode(',', array_fill(0, count($artifact_categories), '?'));
$stmtHistory = $pdo->prepare("SELECT * FROM project_files WHERE project_id = ? AND file_category IN ($placeholders) ORDER BY file_category, version DESC");
$params = array_merge([$project_id], $artifact_categories);
$stmtHistory->execute($params);
$artifact_history = $stmtHistory->fetchAll();

$artifacts_by_cat = [];
foreach($artifact_history as $f) {
    $artifacts_by_cat[$f['file_category']][] = $f;
}

// 協力業者一覧を取得
$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// この案件への発注履歴を取得
$stmtOrders = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid ORDER BY o.created_at DESC");
$stmtOrders->execute(['pid' => $project_id]);
$orders = $stmtOrders->fetchAll();

// 未承認の納品を取得
$stmtDelivered = $pdo->prepare("
    SELECT o.*, u.contact_name, 
           f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
           f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
           f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
    FROM subcontractor_orders o 
    JOIN users u ON o.subcontractor_id = u.id 
    LEFT JOIN project_files f1 ON o.project_id = f1.project_id AND f1.file_category = 'sub_structural_pdf' AND f1.is_latest = 1
    LEFT JOIN project_files f2 ON o.project_id = f2.project_id AND f2.file_category = 'sub_architrend_design' AND f2.is_latest = 1
    LEFT JOIN project_files f3 ON o.project_id = f3.project_id AND f3.file_category = 'sub_architrend_struct' AND f3.is_latest = 1
    WHERE o.project_id = :pid AND o.status = 'delivered'
");
$stmtDelivered->execute(['pid' => $project_id]);
$delivered_orders = $stmtDelivered->fetchAll();

// チャット履歴を取得 (送信者のロールをJOINして取得)
$stmtMsgs = $pdo->prepare("
    SELECT m.*, u.role as sender_role, u.contact_name as sender_name 
    FROM messages m 
    LEFT JOIN users u ON m.sender_id = u.id 
    WHERE m.project_id = :pid AND m.thread_type = 'client_admin' 
    ORDER BY m.id ASC
");
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
        .container { display: flex; gap: 20px; width: 98%; max-width: none; margin: 0 auto; align-items: flex-start; }
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
        .chat-wrapper { display: flex; flex-direction: column; height: 75vh; min-height: 600px; }
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
        .chat-attach-btn { background: #6c757d; color: white; border: none; border-radius: 50%; width: 38px; height: 38px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; transition: all 0.2s ease; }
        .chat-attach-btn.attached { background: #10b981; animation: pulse-green 2s infinite; }
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .chat-file-preview { font-size: 11px; color: #555; margin-top: 4px; padding: 5px 10px; background: white; border-radius: 8px; display: none; border: 1px solid #cbd5e1; }
        .chat-file-preview:not(:empty) { display: block; }
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
        <div style="display:flex; align-items:center; gap:15px;">
            <a href="index.php" style="color:#0056b3; text-decoration:none; font-weight:bold;">➔ 案件一覧に戻る</a>
            <?php if ($has_finance_access): ?>
                <a href="project_subcontractor.php?id=<?= $project_id ?>" target="_blank" style="background:#3b82f6; color:white; padding:5px 12px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">👷 協力業者ダッシュボードを開く</a>
            <?php endif; ?>
        </div>
        <div style="display:flex; align-items:center; gap:15px;">
            <div style="font-size:12px; color:#aaa; font-weight:bold;">Ver: <?= SYSTEM_VERSION ?></div>
            <a href="logout.php" style="color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
        </div>
    </div>

        <?php if ($has_finance_access): ?>
            <?php require __DIR__ . '/components/dashboard_admin.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/components/dashboard_client.php'; ?>
        <?php endif; ?>

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

ご依頼いただける際は、「設計依頼データの送付」ボタンから以下のファイルをご送付ください：
1. 意匠図CADデータ（JWW/DXF等）
2. 確認申請書 2面〜5面
3. 地盤調査報告書
4. 真北・道路資料（天空率等の場合）
※ 構造材種や金物の指定などは、「設計依頼データの送付」時のコメント欄にご記入ください。

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

    <!-- ===== 一次回答後の本見積・請求書発行モーダル ===== -->
    <?php if ($has_finance_access): ?>
    <div class="modal-overlay" id="billingModal">
        <div class="modal-box" style="max-width:500px;">
            <div class="modal-title">💰 【連続発行】本見積と一次請求書の発行</div>
            <div style="font-size:12px; color:#555; margin-bottom:15px;">
                一次回答が完了しました。引き続き本見積額を設定し、一次請求書（50%分）を発行してください。
            </div>
            <form id="billingModalForm">
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">本見積額 (円・税込)</label>
                    <input type="number" name="formal_est_amount" id="bm_amount" value="<?= htmlspecialchars($project_info['formal_est_amount'] ?? '') ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required placeholder="例: 110000">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">本見積日</label>
                    <input type="date" name="formal_est_date" id="bm_date" value="<?= htmlspecialchars(!empty($project_info['formal_est_date']) ? $project_info['formal_est_date'] : date('Y-m-d')) ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">見積書・請求書の宛先名称</label>
                    <input type="text" name="billing_company_name" id="bm_billing" value="<?= htmlspecialchars($project_info['billing_company_name'] ?: ($project_info['company_name'] . ' ' . $project_info['client_name'])) ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="※空白の場合は会社名＋担当者名">
                </div>
                <div class="modal-btns">
                    <button type="button" onclick="document.getElementById('billingModal').classList.remove('active')" style="padding:8px 15px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer; font-size:12px;">後で設定</button>
                    <button type="button" onclick="submitBillingFlow()" id="btn_billing_submit" style="padding:8px 15px; background:#dc3545; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold; font-size:12px;">保存して一次請求書(50%)を発行</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('show_billing_modal')) {
            document.getElementById('billingModal').classList.add('active');
        }
    });

    function submitBillingFlow() {
        const amount = document.getElementById('bm_amount').value;
        const date = document.getElementById('bm_date').value;
        const billing = document.getElementById('bm_billing').value;

        if (!amount || !date) {
            alert('本見積額と本見積日を入力してください。');
            return;
        }

        const btn = document.getElementById('btn_billing_submit');
        btn.disabled = true;
        btn.innerText = '処理中...';

        // 1. 金銭データを保存
        const saveForm = new FormData();
        saveForm.append('project_id', <?= $project_id ?>);
        saveForm.append('formal_est_amount', amount);
        saveForm.append('formal_est_date', date);
        saveForm.append('billing_company_name', billing);
        saveForm.append('initial_est_amount', '<?= htmlspecialchars(!empty($project_info['initial_est_amount']) ? $project_info['initial_est_amount'] : '') ?>');
        saveForm.append('initial_est_date', '<?= htmlspecialchars(!empty($project_info['initial_est_date']) ? $project_info['initial_est_date'] : '') ?>');
        saveForm.append('add_est_amount', '<?= htmlspecialchars(!empty($project_info['add_est_amount']) ? $project_info['add_est_amount'] : '') ?>');
        saveForm.append('add_est_date', '<?= htmlspecialchars(!empty($project_info['add_est_date']) ? $project_info['add_est_date'] : '') ?>');
        saveForm.append('deposit_amount', '<?= htmlspecialchars(!empty($project_info['deposit_amount']) ? $project_info['deposit_amount'] : '') ?>');
        saveForm.append('deposit_date', '<?= htmlspecialchars(!empty($project_info['deposit_date']) ? $project_info['deposit_date'] : '') ?>');

        fetch('actions/admin_finance_post.php', { method: 'POST', body: saveForm })
            .then(res => {
                // 2. 一次請求書(50%)を発行
                const issueForm = new FormData();
                issueForm.append('project_id', <?= $project_id ?>);
                return fetch('api_issue_primary_invoice.php', { method: 'POST', body: issueForm });
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('本見積を保存し、一次請求書(50%)を発行しました。');
                    window.location.href = 'project_detail.php?id=' + <?= $project_id ?>;
                } else {
                    alert('請求書の発行に失敗しました: ' + (data.error || '不明なエラー'));
                    btn.disabled = false;
                    btn.innerText = '保存して一次請求書(50%)を発行';
                }
            })
            .catch(e => {
                alert('通信エラーが発生しました: ' + e);
                btn.disabled = false;
                btn.innerText = '保存して一次請求書(50%)を発行';
            });
    }
    </script>
    <?php endif; ?>

    <script>
    // ===== チャット変数 (External JS 用) =====
    window.APP_PROJECT_ID = <?= $project_id ?>;
    window.APP_CURRENT_USER_ID = <?= $_SESSION['user_id'] ?>;
    window.APP_IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
    window.APP_CLIENT_NAME = '<?= htmlspecialchars($project_info['client_name'] ?? '依頼主', ENT_QUOTES) ?>';
    window.APP_LAST_MSG_ID = <?= !empty($chat_messages) ? end($chat_messages)['id'] : 0 ?>;
    window.APP_UPDATED_AT = '<?= $project_info['updated_at'] ?? '' ?>';
    </script>
    <script src="assets/js/project_detail.js?v=<?= time() ?>"></script>

</body>
</html>
