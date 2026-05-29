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
// 協力業者一覧を取得
$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// この案件への発注履歴を取得
$stmt = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid");
$stmt->execute(['pid' => $project_id]);
$orders = $stmt->fetchAll();

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

// ...（以降、前回までと同じ表示ロジック）...
?>

<!DOCTYPE html>
<html lang="ja">
<body>
    <div class="container">
        <div class="column col-right">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; font-size:12px; border-bottom:1px solid #eee; padding-bottom:8px;">
                <a href="index.php" style="color:#0056b3; text-decoration:none; font-weight:bold;">➔ 案件一覧に戻る</a>
                <a href="logout.php" style="color:#c0392b; text-decoration:none; font-weight:bold;">ログアウト</a>
            </div>
            <?php if (count($delivered_orders) > 0): ?>
                <div class="box" style="background:#fff3cd; border: 1px solid #ffeeba; margin-bottom:15px; border-radius:6px; padding:15px;">
                    <h3 style="margin-top:0; color:#856404; font-size:13px; display:flex; align-items:center; gap:5px;">
                        🔔 納品確認エリア（成果物の承認待ち）
                    </h3>
                    <?php foreach ($delivered_orders as $del): ?>
                        <div style="font-size:11px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px dashed #ffeeba; color:#666; line-height:1.5;">
                            <strong>担当者:</strong> <?= htmlspecialchars($del['contact_name'], ENT_QUOTES) ?> 様<br>
                            <strong>タスク:</strong> <?= htmlspecialchars($del['task_title'], ENT_QUOTES) ?><br>
                            <strong>金額:</strong> <?= number_format($del['order_amount']) ?>円<br>
                            <strong>納品物:</strong> 
                            <?php if ($del['drive_file_id']): 
                                $download_url = htmlspecialchars($del['drive_file_id'], ENT_QUOTES);
                                if (strpos($del['drive_file_id'], 'uploads/') !== 0 && !empty($del['drive_file_id'])) {
                                    $download_url = 'https://drive.google.com/file/d/' . htmlspecialchars($del['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk';
                                }
                            ?>
                                <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">
                                    📄 <?= htmlspecialchars($del['file_name'], ENT_QUOTES) ?> (V<?= $del['version'] ?>)
                                </a>
                            <?php else: ?>
                                <span style="color:#c0392b;">（図書ファイルが見つかりません）</span>
                            <?php endif; ?><br>
                            
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:8px;">
                                <input type="hidden" name="action" value="approve_delivery">
                                <input type="hidden" name="order_id" value="<?= $del['id'] ?>">
                                <button type="submit" style="background:#28a745; color:white; border:none; padding:4px 10px; font-size:11px; border-radius:3px; cursor:pointer; font-weight:bold;">この納品を承認・確認する</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($is_admin && $project['status'] === 'quote_req'): ?>
            <h2 class="section-title" style="background:#28a745; margin-top:20px;">💰 依頼主宛 自動見積シミュレーター</h2>
            <div class="box" style="background:#e8f5e9;">
                <div style="font-size:12px; margin-bottom:10px;">
                    この案件の見積を算出し、チャットに送信できます。
                </div>
                
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
                        <span style="color:#666;">※150㎡以上は1㎡につき600円加算</span>
                    </div>
                    <div>
                        <strong>目標等級加算</strong><br>
                        <select id="est_grade" style="width:100%; font-size:11px; padding:3px;">
                            <option value="0">なし (0円)</option>
                            <option value="40000">耐震等級3+耐風等級2 (+40,000円)</option>
                            <option value="20000">耐震等級2 (+20,000円)</option>
                            <option value="40000">耐震等級3 (+40,000円)</option>
                            <option value="40000">耐風等級2 (+40,000円)</option>
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
                        <label>金物工法階数: <input type="number" id="est_kanamono" value="0" style="width:40px; font-size:11px;"> 階 (+15,000円/階)</label><br>
                        <label>斜め壁等特殊箇所数: <input type="number" id="est_special" value="0" style="width:40px; font-size:11px;"> 箇所 (+15,000円/箇所)</label>
                    </div>
                </div>

                <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
                    見積合計: <span id="est_total_disp" style="color:#d32f2f; font-size:14px;">0</span> 円 (税別)
                </div>

                <div style="margin-top:10px; display:flex; gap:10px;">
                    <button type="button" onclick="calcClientEstimate()" style="flex:1; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">再計算</button>
                    <button type="button" onclick="sendClientEstimate()" style="flex:1; background:#28a745; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">チャットに見積を送信</button>
                </div>
            </div>

            <script>
            let currentEstimate = 0;
            function calcClientEstimate() {
                let base = parseInt(document.getElementById('est_base').value) || 0;
                let area = parseFloat(document.getElementById('est_area').value) || 0;
                
                // 面積割増 (150平米以上)
                let area_extra = 0;
                if (area > 150) {
                    area_extra = Math.ceil(area - 150) * 600;
                }
                
                let base_with_area = base + area_extra;

                // 形状加算 (乗算)
                let multiplier = 0;
                document.querySelectorAll('.est_multiplier:checked').forEach(cb => {
                    multiplier += parseFloat(cb.value);
                });
                let shape_extra = Math.round(base_with_area * multiplier);

                // 等級加算
                let grade_extra = parseInt(document.getElementById('est_grade').value) || 0;

                // その他加算
                let kanamono = parseInt(document.getElementById('est_kanamono').value) || 0;
                let special = parseInt(document.getElementById('est_special').value) || 0;
                let other_extra = (kanamono * 15000) + (special * 15000);

                currentEstimate = base_with_area + shape_extra + grade_extra + other_extra;
                document.getElementById('est_total_disp').innerText = currentEstimate.toLocaleString();
            }

            function sendClientEstimate() {
                calcClientEstimate();
                if (currentEstimate === 0) return;
                
                const tax = Math.round(currentEstimate * 0.1);
                const total = currentEstimate + tax;
                
                let msg = `【概算お見積り】\n構造計算等の概算見積を算出いたしました。\n\n`;
                msg += `税抜金額: ${currentEstimate.toLocaleString()}円\n`;
                msg += `消費税: ${tax.toLocaleString()}円\n`;
                msg += `税込合計: ${total.toLocaleString()}円\n\n`;
                msg += `よろしければ正式にご依頼ください。`;

                // フォームにセットして送信
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
                inputText.value = msg;
                form.appendChild(inputText);

                document.body.appendChild(form);
                form.submit();
            }

            // 初回計算
            window.addEventListener('DOMContentLoaded', calcClientEstimate);
            </script>
            <?php endif; ?>

            <h2 class="section-title" style="background:#e67e22;">🤝 協力業者への発注・タスク管理</h2>
            
            <div class="box" style="background:#fff9f0;">
                <div style="font-size:11px; margin-bottom:5px;"><strong>自動見積シミュレーター</strong></div>
                <div style="display:flex; gap:5px;">
                    <input type="number" id="sub_area" placeholder="面積(㎡)" style="width:60px; font-size:12px;">
                    <button type="button" onclick="calcSubcontractorEstimate()" style="font-size:11px; padding:2px 5px;">算出</button>
                </div>
                <div id="sub_calc_result" style="margin-bottom:10px;"></div>
                <script>
                function calcSubcontractorEstimate() {
                    const area = parseFloat(document.getElementById('sub_area').value) || 0;
                    if (area <= 0) {
                        document.getElementById('sub_calc_result').innerHTML = '<span style="color:red;font-size:11px;">面積を入力してください</span>';
                        return;
                    }
                    // 単価の例: 1㎡あたり 500円 とする（仮のロジック）
                    const pricePerSqm = 500;
                    const basePrice = 30000; // 基本料金の例
                    const total = basePrice + Math.round(area * pricePerSqm);
                    document.getElementById('sub_calc_result').innerHTML = 
                        '<span style="color:#28a745;font-size:12px;font-weight:bold;">推奨発注額: ' + total.toLocaleString() + '円</span>';
                    
                    // 発注フォームの金額に自動セット
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
            <div class="box" style="background:#f0f8ff;">
                <!-- メッセージ履歴 -->
                <div style="max-height: 180px; overflow-y: auto; margin-bottom: 10px; font-size:11px; padding:5px; background:white; border:1px solid #ddd; border-radius:4px;">
                    <?php
                    $stmtMsgs = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'client_admin' ORDER BY id ASC");
                    $stmtMsgs->execute(['pid' => $project_id]);
                    $chat_messages = $stmtMsgs->fetchAll();
                    foreach ($chat_messages as $msg) {
                        echo '<div style="padding:4px 0; border-bottom:1px solid #eee; margin-bottom:4px;">';
                        echo '<strong>' . htmlspecialchars($msg['thread_type'], ENT_QUOTES) . ':</strong> ';
                        echo htmlspecialchars($msg['message_text'], ENT_QUOTES);
                        echo '</div>';
                    }
                    if (empty($chat_messages)) {
                        echo '<span style="color:#999;">メッセージはありません。</span>';
                    }
                    ?>
                </div>
                <!-- 送信フォーム -->
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="message_text" placeholder="メッセージを入力してください..." style="width:100%; height:50px; margin-bottom:5px; font-size:11px; box-sizing:border-box;" required></textarea>
                    <button type="submit" style="width:100%; background:#17a2b8; color:white; border:none; padding:5px; cursor:pointer; font-size:11px; font-weight:bold; border-radius:3px;">メッセージを送信</button>
                </form>
            </div>
            </div>
    </div>
</body>
</html>