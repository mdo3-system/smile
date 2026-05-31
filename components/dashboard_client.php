<?php
// components/dashboard_client.php
// 依頼主用ダッシュボード
?>
<div class="container" style="flex-direction: column;">
    <div style="display:flex; gap:20px; width:100%;">
        
        <!-- 左パネル：案件情報と金銭情報 -->
        <div class="column col-left" style="flex: 1;">
            <h2 class="section-title" style="background:#4a5568;">📋 案件情報とご請求状況</h2>
            
            <div class="box">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ccc; padding-bottom:5px; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:14px;">基本情報</h3>
                    <button onclick="document.getElementById('editInfoModal').classList.add('active')" style="background:#e2e8f0; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; color:#475569; font-weight:bold;">編集</button>
                </div>
                <div style="font-size:13px; line-height:1.6;">
                    <strong>案件名:</strong> <?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?><br>
                    <strong>宛先名:</strong> <?= htmlspecialchars($project_info['billing_company_name'] ?: $project_info['company_name'], ENT_QUOTES) ?> 様<br>
                    <strong>お電話:</strong> <?= htmlspecialchars($project_info['client_phone'] ?: '未登録', ENT_QUOTES) ?><br>
                    <?php
                        $status_labels = [
                            'quote_req'      => '見積依頼',
                            'contracted'     => '受注済',
                            'primary_prep'   => '一次回答準備中',
                            'structural_dwg' => '構造図作成中',
                            'submission'     => '提出済・確認中',
                            'correction'     => '補正対応中',
                            'completed'      => '完了'
                        ];
                        $status_ja = $status_labels[$project_info['status']] ?? $project_info['status'];
                    ?>
                    <strong>ステータス:</strong> <span class="badge" style="background:#007bff;"><?= htmlspecialchars($status_ja, ENT_QUOTES) ?></span>
                </div>
            </div>

            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">📋 ご依頼内容</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <?php if ($project_info['req_permit'] ?? 0): ?><div>・確認申請書作成</div><?php endif; ?>
                    <?php if ($project_info['req_wall'] ?? 0): ?><div>・壁量計算書作成</div><?php endif; ?>
                    <?php if ($project_info['req_skin'] ?? 0): ?><div>・外皮計算書作成</div><?php endif; ?>
                    <?php if ($project_info['req_sky'] ?? 0): ?><div>・天空率計算書作成</div><?php endif; ?>
                    <?php if ($project_info['req_opt_kisohari'] ?? 0): ?><div>・【オプション】基礎梁計算</div><?php endif; ?>
                    <?php 
                        if (!($project_info['req_permit'] ?? 0) && !($project_info['req_wall'] ?? 0) && !($project_info['req_skin'] ?? 0) && !($project_info['req_sky'] ?? 0) && !($project_info['req_opt_kisohari'] ?? 0)) {
                            echo "<div>・構造計算等の基本業務</div>";
                        }
                    ?>
                </div>
            </div>

            <div class="box" style="margin-top:15px; background:#f0f8ff; border-color:#cce5ff;">
                <h3 style="margin-top:0; font-size:14px; color:#004085; border-bottom:1px solid #cce5ff; padding-bottom:5px;">📅 全体スケジュール</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <?php if (empty($project_info['primary_due_date'])): ?>
                        <div style="color:#666; font-size:12px;">※スケジュールは、図書提出後に担当者が確認・設定します。</div>
                    <?php else: ?>
                        <strong>一次回答納期:</strong> <?= htmlspecialchars($project_info['primary_due_date']) ?><br>
                        <?php
                            $actuals = json_decode($project_info['schedule_actuals'] ?? '{}', true);
                            if (!empty($actuals['primary_reply'])):
                        ?>
                            <div style="color:#28a745; font-size:11px;">✅ <?= htmlspecialchars($actuals['primary_reply']) ?> に一次回答済</div>
                        <?php endif; ?>
                        
                        <!-- TODO: 他のマイルストーン（構造計算納期など）も必要に応じて追加 -->
                    <?php endif; ?>
                </div>
            </div>

            <div class="box" style="margin-top:15px; background:#fff3cd; border-color:#ffeeba;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 ご請求・お支払い状況</h3>
                <div style="font-size:13px; line-height:1.8;">
                    <?php
                        $initial = $project_info['initial_est_amount'] ?? 0;
                        $initial_date = $project_info['initial_est_date'] ?? '-';
                        $formal = $project_info['formal_est_amount'] ?? 0;
                        $formal_date = $project_info['formal_est_date'] ?? '-';
                        $add = $project_info['add_est_amount'] ?? 0;
                        $add_date = $project_info['add_est_date'] ?? '-';
                        $deposit = $project_info['deposit_amount'] ?? 0;
                        $deposit_date = $project_info['deposit_date'] ?? '-';

                        $total_req = $formal + $add;
                        $balance = $total_req - $deposit;
                    ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>初期お見積額 (<?= htmlspecialchars($initial_date) ?>):</span> <strong><?= number_format($initial) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>本見積額 (<?= htmlspecialchars($formal_date) ?>):</span> <strong><?= number_format($formal) ?> 円</strong>
                    </div>
                    <?php if ($add > 0): ?>
                    <div style="display:flex; justify-content:space-between; color:#c0392b; margin-bottom: 5px;">
                        <span>追加費用 (<?= htmlspecialchars($add_date) ?>):</span> <strong>+ <?= number_format($add) ?> 円</strong>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px dashed #ccc; padding-top:5px;">
                        <span>ご請求総額:</span> <strong><?= number_format($total_req) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; color:#28a745;">
                        <span>入金済額 (<?= htmlspecialchars($deposit_date) ?>):</span> <strong>- <?= number_format($deposit) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px solid #ccc; padding-top:5px; font-size:15px; font-weight:bold; color:#d32f2f;">
                        <span>現在の残金:</span> <span><?= number_format($balance) ?> 円</span>
                    </div>
                </div>
            </div>
            
            <div class="box" style="background:#e8f5e9; border-color:#c8e6c9;">
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">📝 見積書・請求書</h3>
                
                <?php if (!empty($all_estimates)): ?>
                    <div style="margin-bottom:15px; padding:10px; background:#fff; border:2px solid #28a745; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#155724; margin-bottom:5px;">最新の御見積書</div>
                        <a href="estimate_print.php?id=<?= $project_id ?>&est_id=<?= $all_estimates[0]['id'] ?>" target="_blank" style="display:inline-block; padding:8px 15px; background:#28a745; color:white; font-weight:bold; border-radius:4px; text-decoration:none;">
                            📄 見積書を表示・ダウンロード
                        </a>
                    </div>
                    
                    <?php if (count($all_estimates) > 1): ?>
                    <details style="font-size:12px; margin-bottom:10px;">
                        <summary style="cursor:pointer; color:#0056b3;">過去の見積履歴を表示</summary>
                        <ul style="list-style:none; padding-left:10px; margin-top:5px; line-height:1.6;">
                        <?php foreach(array_slice($all_estimates, 1) as $est): ?>
                            <li>
                                <a href="estimate_print.php?id=<?= $project_id ?>&est_id=<?= $est['id'] ?>" target="_blank" style="text-decoration:none; color:#555;">
                                    📄 <?= htmlspecialchars($est['created_at']) ?> 提示分 (税込: ¥<?= number_format($est['total_price'] * 1.1) ?>)
                                </a>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="font-size:12px; color:#666; padding:10px; text-align:center; background:#fff; border-radius:4px;">見積書はまだ発行されていません。</div>
                <?php endif; ?>
                
                <hr style="border:0; border-top:1px dashed #c8e6c9; margin:15px 0;">
                
                <h3 style="margin-top:0; font-size:14px; color:#0056b3; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">正式なご依頼（設計依頼データ送付）</h3>
                
                <?php if ($project_info['status'] === 'quote_req'): ?>
                    <p style="font-size:11px; color:#666; margin-bottom:10px;">見積もり内容をご確認いただき、正式に発注される場合は、こちらから必要な設計データを送付してください。</p>
                    <button onclick="document.getElementById('orderModal').classList.add('active')" style="width:100%; background:#0056b3; color:white; border:none; padding:10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:14px;">
                        📤 設計依頼データの送付
                    </button>
                <?php else: ?>
                    <div style="font-size:12px; color:#155724; background:#d4edda; padding:10px; border-radius:4px; text-align:center; border:1px solid #c3e6cb;">
                        <strong>✅ 正式発注済み（必要図書提出済）</strong><br>
                        <span style="font-size:11px;">現在、担当者が図書を確認し、設計作業を進めています。</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ===== 発注データアップロードモーダル ===== -->
        <div class="modal-overlay" id="orderModal">
            <div class="modal-box" style="max-width:600px;">
                <div class="modal-title">📤 設計依頼データの送付（正式発注）</div>
                <div style="font-size:13px; margin-bottom:15px; color:#555;">以下の必須データをアップロードして、正式に発注してください。</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="request_design_start">
                    
                    <?php
                        $is_common = ($project_info['req_permit'] || $project_info['req_wall'] || (!($project_info['req_permit']||$project_info['req_wall']||$project_info['req_skin']||$project_info['req_sky'])));
                        $is_sky = $project_info['req_sky'];
                        $is_skin = $project_info['req_skin'];
                    ?>
                    
                    <?php include 'upload_slots.php'; ?>
                    
                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">その他補足事項・メッセージ</label>
                        <textarea name="client_notes_extra" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" placeholder="よろしくお願いいたします。"></textarea>
                    </div>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('orderModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#0056b3; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;" onclick="this.innerHTML='送信中...';">送信して正式発注</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 中央パネル：提出図書と成果物 -->
        <div class="column col-center" style="flex: 1;">
            <h2 class="section-title" style="background:#3b82f6;">📁 図書のやり取り</h2>

            <!-- 成果物を最上段へ -->
            <div class="box" style="border:2px solid #3b82f6; box-shadow:0 4px 6px rgba(59,130,246,0.1);">
                <h3 style="margin-top:0; font-size:14px; color:#1e40af; border-bottom:1px solid #bfdbfe; padding-bottom:5px;">🎁 納品された成果物</h3>
                <div style="font-size:12px; color:#555; margin-bottom:10px;">
                    完成した構造図や計算書はこちらからダウンロードしてください。
                </div>
                <?php 
                $has_artifact = false;
                $artifact_categories = [
                    'structural_dwg' => '構造図', 
                    'calc_doc' => '構造計算書', 
                    'wall_calc_doc' => '壁量計算書',
                    'skin_calc_doc' => '外皮計算書',
                    'sky_calc_doc' => '天空率計算書'
                ];
                foreach ($artifact_categories as $cat => $label):
                    if (isset($files_by_cat[$cat])):
                        $has_artifact = true;
                        $f = $files_by_cat[$cat][0];
                        $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                ?>
                    <div style="padding:10px; margin-bottom:10px; border:1px solid #93c5fd; background:#eff6ff; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-weight:bold; color:#1e40af; font-size:13px;">📄 <?= $label ?> (V<?= $f['version'] ?>)</div>
                        <a href="<?= $url ?>" target="_blank" class="file-link" style="font-size:12px; padding:6px 12px; background:#3b82f6; color:white; border-radius:4px; text-decoration:none;">ダウンロード</a>
                    </div>
                <?php 
                    endif;
                endforeach; 
                if (!$has_artifact): 
                ?>
                    <div style="padding:15px; text-align:center; color:#999; border:1px dashed #ccc; border-radius:6px;">
                        まだ納品された成果物はありません。
                    </div>
                <?php endif; ?>
            </div>

            <!-- 提出図書リストと差し替え -->
            <div class="box">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ccc; padding-bottom:5px; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:14px;">ご提出いただいた図書</h3>
                    <?php if ($project_info['status'] !== 'quote_req'): ?>
                        <button onclick="document.getElementById('replaceModal').classList.add('active')" style="background:#dc3545; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:bold;">ファイルの追加・差し替え</button>
                    <?php endif; ?>
                </div>
                
                <div style="display:flex; flex-direction:column; gap:8px; font-size:12px;">
                    <?php
                    // 依頼内容に基づく必要図書の判定 (左パネルと同じロジック)
                    $req_docs = [];
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1 || $project_info['req_skin'] == 1 || $project_info['req_sky'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $req_docs['cad_layout'] = '配置図';
                        $req_docs['cad_plan_1f'] = '1F平面図';
                        $req_docs['cad_plan_2f'] = '2F平面図';
                        $req_docs['cad_elevation'] = '立面図';
                        $req_docs['cad_section'] = '矩計図';
                    }
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1) {
                        $req_docs['app_doc'] = '確認申請書';
                        $req_docs['soil_report'] = '地盤調査資料';
                    }
                    if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり') {
                        $req_docs['soil_impr'] = '地盤改良関連図書';
                    }

                    if (empty($req_docs)) {
                        echo "<div style='color:#666;'>見積依頼前のため、必要な図書はまだ確定していません。</div>";
                    } else {
                        foreach ($req_docs as $key => $label) {
                            if (isset($files_by_cat[$key])) {
                                $f = $files_by_cat[$key][0];
                                $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                                echo "<div style='padding:8px; border:1px solid #e2e8f0; border-radius:4px; background:#f8fafc; display:flex; justify-content:space-between;'>";
                                echo "<span><strong>{$label}:</strong> <a href='{$url}' target='_blank' style='color:#2563eb; text-decoration:none;'>📄 {$f['file_name']}</a></span>";
                                echo "</div>";
                            } else {
                                echo "<div style='padding:8px; border:1px solid #fca5a5; border-radius:4px; background:#fef2f2; color:#b91c1c; font-weight:bold; display:flex; justify-content:space-between;'>";
                                echo "<span><strong>{$label}:</strong> 未提出（不足図書）</span>";
                                echo "</div>";
                            }
                        }
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- 右パネル：チャット -->
        <div class="column col-right" style="flex: 1;">
            <h2 class="section-title" style="background:#17a2b8;">💬 メッセージ</h2>
            <!-- チャットエリア (LINEスタイル) -->
            <div class="chat-wrapper">
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($chat_messages as $msg):
                        $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                        $rowClass = $isMe ? 'from-me' : '';
                        $bubbleClass = ($msg['sender_id'] == 1) ? 'bubble-admin' : 'bubble-client';
                        $avatarClass = ($msg['sender_id'] == 1) ? 'admin-avatar' : 'client-avatar';
                        $avatarIcon  = ($msg['sender_id'] == 1) ? '👷' : '👤';
                        $senderName  = ($msg['sender_id'] == 1) ? 'サポート担当者' : 'あなた';
                        $timeStr     = date('m/d H:i', strtotime($msg['created_at'] ?? 'now'));
                    ?>
                        <div class="chat-bubble-row <?= $rowClass ?>" data-msg-id="<?= $msg['id'] ?>">
                            <div class="chat-avatar <?= $avatarClass ?>"><?= $avatarIcon ?></div>
                            <div class="chat-content">
                                <?php if (!$isMe): ?>
                                <div class="chat-name"><?= $senderName ?></div>
                                <?php endif; ?>
                                <?php if (!empty($msg['message_text'])): ?>
                                <div class="chat-bubble <?= $bubbleClass ?>"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($msg['file_path'])): ?>
                                    <?php
                                        $ftype = $msg['file_type'] ?? '';
                                        $fpath = $msg['file_path'];
                                        $isGdrive = (strlen($fpath) > 15 && strpos($fpath, '/') === false && strpos($fpath, 'uploads/') !== 0);
                                        $furl = $isGdrive ? 'https://drive.google.com/file/d/' . htmlspecialchars($fpath, ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($fpath, ENT_QUOTES);
                                        $thumbUrl = $isGdrive ? 'https://drive.google.com/thumbnail?id=' . htmlspecialchars($fpath, ENT_QUOTES) . '&sz=w200' : '';
                                    ?>
                                    <?php if ($ftype === 'image' && $isGdrive): ?>
                                        <a href="<?= $furl ?>" target="_blank">
                                            <img src="<?= $thumbUrl ?>" class="chat-image-thumb" alt="添付画像">
                                        </a>
                                    <?php elseif ($ftype === 'pdf' || !empty($fpath)): ?>
                                        <a href="<?= $furl ?>" target="_blank" class="chat-pdf-link">📄 添付ファイルを開く</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="chat-time"><?= $timeStr ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($chat_messages)): ?>
                        <div style="text-align:center; color:#aaa; font-size:12px; margin-top:40px;">メッセージはまだありません</div>
                    <?php endif; ?>
                </div>

                <!-- 入力エリア -->
                <div class="chat-input-area">
                    <div id="filePreview" class="chat-file-preview"></div>
                    <div style="margin-bottom:8px;">
                        <select id="chatTargetFile" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; font-size:12px;">
                            <option value="">-- 対象ファイル（全体へのメッセージ） --</option>
                            <?php
                            $uploaded_file_names = [];
                            foreach ($files_by_cat as $cat => $files) {
                                foreach ($files as $f) {
                                    $uploaded_file_names[] = $f['file_name'];
                                }
                            }
                            try {
                                $stmtAllCenter = $pdo->prepare("SELECT file_name FROM project_files WHERE project_id = :pid AND is_latest = 1 ORDER BY id DESC");
                                $stmtAllCenter->execute(['pid' => $project_id]);
                                while ($row = $stmtAllCenter->fetch(PDO::FETCH_ASSOC)) { $uploaded_file_names[] = $row['file_name']; }
                                $uploaded_file_names = array_unique($uploaded_file_names);
                                foreach ($uploaded_file_names as $fname) {
                                    echo '<option value="' . htmlspecialchars($fname, ENT_QUOTES) . '">📎 ' . htmlspecialchars($fname, ENT_QUOTES) . '</option>';
                                }
                            } catch (Exception $e) {}
                            ?>
                        </select>
                    </div>
                    <div class="chat-input-row">
                        <label class="chat-attach-btn" title="ファイルを添付">
                            📎
                            <input type="file" id="chatFileInput" accept="image/*,.pdf" style="display:none;" onchange="previewFile(this)">
                        </label>
                        <textarea id="chatTextarea" class="chat-textarea" placeholder="メッセージを入力..." rows="1" onkeydown="handleKey(event)"></textarea>
                        <button class="chat-send-btn" onclick="sendMessage()" title="送信">➤</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ===== 基本情報編集モーダル ===== -->
        <div class="modal-overlay" id="editInfoModal">
            <div class="modal-box">
                <div class="modal-title">基本情報の編集</div>
                <form method="POST" action="actions/project_detail_post.php">
                    <input type="hidden" name="action" value="update_client_info">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    
                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">案件名</label>
                        <input type="text" name="project_name" value="<?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;" required>
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">お電話番号</label>
                        <input type="text" name="phone_number" value="<?= htmlspecialchars($project_info['client_phone'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">見積書・請求書の宛先名称</label>
                        <input type="text" name="billing_company_name" value="<?= htmlspecialchars($project_info['billing_company_name'] ?? '', ENT_QUOTES) ?>" placeholder="※変更がある場合のみ入力" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    </div>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('editInfoModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#0056b3; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">保存</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===== 図書差し替え・追加モーダル ===== -->
        <div class="modal-overlay" id="replaceModal">
            <div class="modal-box" style="max-width:600px;">
                <div class="modal-title">🔄 図書の追加・差し替え</div>
                <div style="font-size:13px; margin-bottom:15px; color:#555;">不足図書のアップロードや、既存ファイルの差し替えを行います。<br>※差し替えを行う場合は、必ず変更内容（理由）を入力してください。</div>
                <form method="POST" action="actions/project_detail_post.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="replace_documents">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    
                    <?php
                        // upload_slotsに渡すフラグ。既存ファイルがある場合は update_reason を表示させるための処理を内包
                        $is_common = ($project_info['req_permit'] || $project_info['req_wall'] || (!($project_info['req_permit']||$project_info['req_wall']||$project_info['req_skin']||$project_info['req_sky'])));
                        $is_sky = $project_info['req_sky'];
                        $is_skin = $project_info['req_skin'];
                    ?>
                    <?php include 'upload_slots.php'; ?>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('replaceModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#dc3545; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">アップロードして通知</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            // 差し替えモーダル内で、ファイルを選択したら変更理由入力を必須化し、表示するスクリプト
            document.addEventListener('DOMContentLoaded', function() {
                const replaceModal = document.getElementById('replaceModal');
                if (replaceModal) {
                    const fileInputs = replaceModal.querySelectorAll('input[type="file"]');
                    fileInputs.forEach(input => {
                        // required属性を最初は外しておく（必要なものだけアップするため）
                        input.removeAttribute('required');
                        
                        input.addEventListener('change', function() {
                            const reasonInput = this.closest('div').nextElementSibling;
                            if (reasonInput && reasonInput.classList.contains('update-reason-input')) {
                                if (this.files.length > 0) {
                                    reasonInput.style.display = 'block';
                                    reasonInput.setAttribute('required', 'required');
                                } else {
                                    reasonInput.style.display = 'none';
                                    reasonInput.removeAttribute('required');
                                }
                            }
                        });
                    });
                }
            });
        </script>
    </div>
</div>
