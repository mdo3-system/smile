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

// 見積情報の取得
$stmtEst = $pdo->prepare("SELECT pdf_drive_file_id FROM estimates WHERE project_id = :pid");
$stmtEst->execute(['pid' => $project_id]);
$estimate_info = $stmtEst->fetch();
$pdf_drive_id = $estimate_info['pdf_drive_file_id'] ?? null;

// 案件に関連する全ファイル（最新のみ）を取得 (依頼主提出物用)
$stmtFiles = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND is_latest = 1");
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
        <div style="display:flex; align-items:center; gap:15px;">
            <a href="logout.php" style="color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
        </div>
    </div>

    <div class="container">
        <!-- 左パネル：依頼主と案件情報 -->
        <?php require __DIR__ . '/components/col_left.php'; ?>



        <!-- 中央パネル：成果物一覧 -->
        <?php require __DIR__ . '/components/col_center.php'; ?>

        <!-- 右パネル：チャット・管理ツール -->
        <?php require __DIR__ . '/components/col_right.php'; ?>
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
    window.addEventListener('DOMContentLoaded', () => {
        scrollToBottom();
        if (typeof toggleEstContainers === 'function') {
            toggleEstContainers();
            calcClientEstimate();
        }
    });

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
        const targetSelect = document.getElementById('chatTargetFile');
        const msg = text || textarea.value.trim();
        if (!msg && fileInput.files.length === 0) return;

        const formData = new FormData();
        formData.append('project_id', PROJECT_ID);
        formData.append('message_text', msg);
        if (targetSelect && targetSelect.value) {
            formData.append('target_file', targetSelect.value);
        }
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

    // ===== 見積シミュレーターアコーディオン制御 =====
    function toggleEstContainers() {
        if(document.getElementById('container_permit')) {
            document.getElementById('container_permit').style.display = document.getElementById('est_active_permit').checked ? 'block' : 'none';
        }
        if(document.getElementById('container_wall')) {
            document.getElementById('container_wall').style.display = document.getElementById('est_active_wall').checked ? 'block' : 'none';
        }
        if(document.getElementById('container_skin')) {
            document.getElementById('container_skin').style.display = document.getElementById('est_active_skin').checked ? 'block' : 'none';
        }
        if(document.getElementById('container_sky')) {
            document.getElementById('container_sky').style.display = document.getElementById('est_active_sky').checked ? 'block' : 'none';
        }
    }

    // ===== 見積計算ロジック =====
    let currentEstimate = 0, currentTax = 0, currentTotal = 0;
    let estimateItems = [];
    
    function calcClientEstimate() {
        currentEstimate = 0;
        estimateItems = [];
        
        const el_permit = document.getElementById('est_active_permit');
        const permit_active = el_permit ? el_permit.checked : false;
        
        const el_wall = document.getElementById('est_active_wall');
        const wall_active = el_wall ? el_wall.checked : false;
        
        const el_skin = document.getElementById('est_active_skin');
        const skin_active = el_skin ? el_skin.checked : false;
        
        const el_sky = document.getElementById('est_active_sky');
        const sky_active = el_sky ? el_sky.checked : false;
        
        // 1. 許容応力度計算
        if (permit_active) {
            let base = parseInt(document.getElementById('est_base_permit').value) || 0;
            let area = parseFloat(document.getElementById('est_area_permit').value) || 0;
            let area_extra = area > 150 ? Math.ceil(area - 150) * 600 : 0;
            let base_with_area = base + area_extra;
            
            let multiplier = 0;
            document.querySelectorAll('.est_mult_permit:checked').forEach(cb => multiplier += parseFloat(cb.value));
            let shape_extra = Math.round(base_with_area * multiplier);
            
            let grade = parseInt(document.getElementById('est_grade_permit').value) || 0;
            let kanamono = parseInt(document.getElementById('est_kanamono_permit').value) || 0;
            let special = parseInt(document.getElementById('est_special_permit').value) || 0;
            
            let kanamono_extra = kanamono * 15000;
            let special_extra = special * 15000;
            
            let subtotal = base_with_area + shape_extra + grade + kanamono_extra + special_extra;
            currentEstimate += subtotal;
            
            estimateItems.push({ name: "許容応力度計算 基本料金", qty: 1, unit: "式", price: base, amount: base });
            if (area_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 構造床面積割増 (150㎡超)", qty: Math.ceil(area - 150), unit: "㎡", price: 600, amount: area_extra });
            }
            if (shape_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 形状・仕様割増 (" + Math.round(multiplier * 100) + "%)", qty: 1, unit: "式", price: shape_extra, amount: shape_extra });
            }
            if (grade > 0) {
                let gradeName = "許容応力度計算 等級加算";
                if(grade == 40000) gradeName = "許容応力度計算 等級加算(耐震3/耐風2等)";
                if(grade == 20000) gradeName = "許容応力度計算 等級加算(耐震2)";
                estimateItems.push({ name: gradeName, qty: 1, unit: "式", price: grade, amount: grade });
            }
            if (kanamono_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 金物工法割増", qty: kanamono, unit: "階", price: 15000, amount: kanamono_extra });
            }
            if (special_extra > 0) {
                estimateItems.push({ name: "許容応力度計算 特殊箇所割増", qty: special, unit: "箇所", price: 15000, amount: special_extra });
            }
        }
        
        // 2. 性能表示壁量計算
        if (wall_active) {
            let base = parseInt(document.getElementById('est_base_wall').value) || 0;
            let area = parseFloat(document.getElementById('est_area_wall').value) || 0;
            let area_extra = area > 150 ? Math.ceil(area - 150) * 500 : 0;
            let base_with_area = base + area_extra;
            
            let multiplier = 0;
            document.querySelectorAll('.est_mult_wall:checked').forEach(cb => multiplier += parseFloat(cb.value));
            let shape_extra = Math.round(base_with_area * multiplier);
            
            let dwg = parseInt(document.getElementById('est_dwg_wall').value) || 0;
            let jintsu = parseInt(document.getElementById('est_jintsu_wall').value) || 0;
            
            let kisohari = document.getElementById('est_kisohari_wall').checked;
            let kisohari_extra = 0;
            if (kisohari) {
                kisohari_extra = 20000;
                if (area > 150) {
                    kisohari_extra += Math.ceil(area - 150) * 500;
                }
            }
            
            let subtotal = base_with_area + shape_extra + dwg + jintsu + kisohari_extra;
            currentEstimate += subtotal;
            
            estimateItems.push({ name: "性能表示壁量計算 基本料金", qty: 1, unit: "式", price: base, amount: base });
            if (area_extra > 0) {
                estimateItems.push({ name: "性能表示壁量計算 構造床面積割増 (150㎡超)", qty: Math.ceil(area - 150), unit: "㎡", price: 500, amount: area_extra });
            }
            if (shape_extra > 0) {
                estimateItems.push({ name: "性能表示壁量計算 形状割増 (" + Math.round(multiplier * 100) + "%)", qty: 1, unit: "式", price: shape_extra, amount: shape_extra });
            }
            if (dwg > 0) {
                let dwgName = "性能表示壁量計算 構造図作成";
                estimateItems.push({ name: dwgName, qty: 1, unit: "式", price: dwg, amount: dwg });
            }
            if (jintsu > 0) {
                estimateItems.push({ name: "性能表示壁量計算 人通孔箇所割増", qty: 1, unit: "式", price: jintsu, amount: jintsu });
            }
            if (kisohari_extra > 0) {
                estimateItems.push({ name: "性能表示壁量計算 基礎梁許容応力度計算", qty: 1, unit: "式", price: kisohari_extra, amount: kisohari_extra });
            }
        }
        
        // 3. 外皮計算
        if (skin_active) {
            let base = parseInt(document.getElementById('est_base_skin').value) || 0;
            let area = parseFloat(document.getElementById('est_area_skin').value) || 0;
            let area_extra = area > 100 ? Math.ceil(area - 100) * 500 : 0;
            let base_with_area = base + area_extra;
            
            let multiplier = 0;
            document.querySelectorAll('.est_mult_skin:checked').forEach(cb => multiplier += parseFloat(cb.value));
            let shape_extra = Math.round(base_with_area * multiplier);
            
            let kisotachi = parseInt(document.getElementById('est_kisotachi_skin').value) || 0;
            let kisotachi_extra = kisotachi * 15000;
            
            let setsumei = document.getElementById('est_setsumei_skin').checked ? 15000 : 0;
            let energy_extra = 15000; // 一次エネルギー計算は常にセットで加算
            
            let subtotal = base_with_area + shape_extra + kisotachi_extra + setsumei + energy_extra;
            currentEstimate += subtotal;
            
            estimateItems.push({ name: "外皮計算 基本料金", qty: 1, unit: "式", price: base, amount: base });
            if (area_extra > 0) {
                estimateItems.push({ name: "外皮計算 外皮床面積割増 (100㎡超)", qty: Math.ceil(area - 100), unit: "㎡", price: 500, amount: area_extra });
            }
            if (shape_extra > 0) {
                estimateItems.push({ name: "外皮計算 形状割増 (" + Math.round(multiplier * 100) + "%)", qty: 1, unit: "式", price: shape_extra, amount: shape_extra });
            }
            if (kisotachi_extra > 0) {
                estimateItems.push({ name: "外皮計算 基礎立上り400超割増", qty: kisotachi, unit: "箇所", price: 15000, amount: kisotachi_extra });
            }
            if (setsumei > 0) {
                estimateItems.push({ name: "外皮計算 設計内容説明書作成", qty: 1, unit: "式", price: 15000, amount: setsumei });
            }
            estimateItems.push({ name: "一次消費エネルギー量計算", qty: 1, unit: "式", price: 15000, amount: energy_extra });
        }
        
        // 4. 天空率計算
        if (sky_active) {
            let road = document.getElementById('est_road_sky').checked ? 50000 : 0;
            let north = document.getElementById('est_north_sky').checked ? 50000 : 0;
            
            let extra = parseInt(document.getElementById('est_extra_sky').value) || 0;
            let extra_extra = extra * 25000;
            
            let site_area = parseFloat(document.getElementById('est_site_area_sky').value) || 0;
            let site_area_extra = site_area > 150 ? Math.ceil(site_area - 150) * 200 : 0;
            
            let building_area = parseFloat(document.getElementById('est_building_area_sky').value) || 0;
            let building_area_extra = building_area > 150 ? Math.ceil(building_area - 150) * 200 : 0;
            
            let detail = document.getElementById('est_detail_sky').checked ? 15000 : 0;
            
            let subtotal = road + north + extra_extra + site_area_extra + building_area_extra + detail;
            currentEstimate += subtotal;
            
            if (road > 0) {
                estimateItems.push({ name: "天空率 道路斜線基本料金", qty: 1, unit: "式", price: 50000, amount: road });
            }
            if (north > 0) {
                estimateItems.push({ name: "天空率 北側斜線基本料金", qty: 1, unit: "式", price: 50000, amount: north });
            }
            if (extra_extra > 0) {
                estimateItems.push({ name: "天空率 追加斜線面検討", qty: extra, unit: "面", price: 25000, amount: extra_extra });
            }
            if (site_area_extra > 0) {
                estimateItems.push({ name: "天空率 敷地面積割増 (150㎡超)", qty: Math.ceil(site_area - 150), unit: "㎡", price: 200, amount: site_area_extra });
            }
            if (building_area_extra > 0) {
                estimateItems.push({ name: "天空率 建物床面積割増 (150㎡超)", qty: Math.ceil(building_area - 150), unit: "㎡", price: 200, amount: building_area_extra });
            }
            if (detail > 0) {
                estimateItems.push({ name: "天空率 詳細モデル検討", qty: 1, unit: "式", price: 15000, amount: detail });
            }
        }
        
        currentTax = Math.round(currentEstimate * 0.1);
        currentTotal = currentEstimate + currentTax;
        
        const elTotal = document.getElementById('est_total_disp');
        if (elTotal) elTotal.innerText = currentEstimate.toLocaleString();
        
        const elTax = document.getElementById('est_tax_disp');
        if (elTax) elTax.innerText = currentTax.toLocaleString();
        
        const elGrand = document.getElementById('est_grand_total_disp');
        if (elGrand) elGrand.innerText = currentTotal.toLocaleString();
    }
    
    // ===== チャットに見積を送信 =====
    function sendClientEstimate() {
        calcClientEstimate();
        if (currentEstimate === 0) {
            alert('計算する対象を選択してください。');
            return;
        }
        
        let msg = "【概算お見積り内訳】\n";
        estimateItems.forEach(item => {
            msg += `・${item.name} x ${item.qty}${item.unit} : ${item.amount.toLocaleString()}円\n`;
        });
        msg += `\n税抜金額: ${currentEstimate.toLocaleString()}円\n`;
        msg += `消費税: ${currentTax.toLocaleString()}円\n`;
        msg += `税込合計: ${currentTotal.toLocaleString()}円\n\n`;
        msg += "よろしければ正式にご依頼ください。";
        
        sendMessage(msg);
    }
    
    // ===== 見積書の保存とPDF自動発行 =====
    function saveAndPrintEstimate() {
        calcClientEstimate();
        if (currentEstimate === 0) {
            alert('計算する対象を選択してください。');
            return;
        }
        
        const btn = document.getElementById('pdf_issue_btn');
        btn.disabled = true;
        btn.innerText = 'PDF発行中...';
        
        let base_val = 0;
        let area_val = 0;
        let grade_val = 0;
        
        const permit_active = document.getElementById('est_active_permit')?.checked || false;
        const wall_active = document.getElementById('est_active_wall')?.checked || false;
        const skin_active = document.getElementById('est_active_skin')?.checked || false;
        const sky_active = document.getElementById('est_active_sky')?.checked || false;
        
        if (permit_active) {
            base_val += parseInt(document.getElementById('est_base_permit').value) || 0;
            area_val = Math.max(area_val, parseFloat(document.getElementById('est_area_permit').value) || 0);
            grade_val += parseInt(document.getElementById('est_grade_permit').value) || 0;
        }
        if (wall_active) {
            base_val += parseInt(document.getElementById('est_base_wall').value) || 0;
            area_val = Math.max(area_val, parseFloat(document.getElementById('est_area_wall').value) || 0);
        }
        if (skin_active) {
            base_val += parseInt(document.getElementById('est_base_skin').value) || 0;
            area_val = Math.max(area_val, parseFloat(document.getElementById('est_area_skin').value) || 0);
        }
        if (sky_active) {
            base_val += (document.getElementById('est_road_sky').checked ? 50000 : 0) + (document.getElementById('est_north_sky').checked ? 50000 : 0);
        }
        
        const formData = new FormData();
        formData.append('project_id', PROJECT_ID);
        formData.append('base_price', base_val);
        formData.append('area', area_val);
        formData.append('grade_price', grade_val);
        formData.append('total_price', currentEstimate);
        formData.append('note', JSON.stringify(estimateItems));
        formData.append('req_permit', permit_active ? 1 : 0);
        formData.append('req_wall', wall_active ? 1 : 0);
        formData.append('req_skin', skin_active ? 1 : 0);
        formData.append('req_sky', sky_active ? 1 : 0);
        
        fetch('api_save_estimate.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.drive_file_id) {
                    alert('お見積書PDFが自動作成され、Google Driveおよびチャットに共有されました。');
                    window.open(`https://drive.google.com/file/d/${data.drive_file_id}/view?usp=drivesdk`, '_blank');
                    location.reload();
                } else {
                    alert('見積保存・PDF発行に失敗しました: ' + (data.error || '不明なエラー'));
                }
            })
            .catch(e => {
                alert('通信エラー: ' + e);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerText = '印刷用PDFを発行';
            });
    }
    
    window.addEventListener('DOMContentLoaded', () => {
        toggleEstContainers();
        calcClientEstimate();
    });
    </script>

</body>
</html>


