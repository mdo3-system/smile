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
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">基本情報</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <strong>案件名:</strong> <?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?><br>
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

            <div class="box" style="background:#fff3cd; border-color:#ffeeba;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 ご請求・お支払い状況</h3>
                <div style="font-size:13px; line-height:1.8;">
                    <?php
                        $latest_est = !empty($all_estimates) ? $all_estimates[0] : null;
                        $formal_estimate = $latest_est ? $latest_est['total_price'] : ($project_info['total_amount'] ?? 0);
                        $tax = round($formal_estimate * 0.1);
                        $grand_total = $formal_estimate + $tax;
                        
                        $deposit = $project_info['deposit_amount'] ?? 0;
                        $additional = $project_info['additional_amount'] ?? 0;
                        $total_req = $grand_total + $additional;
                        $balance = $total_req - $deposit;
                    ?>
                    <div style="display:flex; justify-content:space-between;">
                        <span>正式お見積額 (税込):</span> <strong><?= number_format($grand_total) ?> 円</strong>
                    </div>
                    <?php if ($additional > 0): ?>
                    <div style="display:flex; justify-content:space-between; color:#c0392b;">
                        <span>追加費用:</span> <strong>+ <?= number_format($additional) ?> 円</strong>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px dashed #ccc; padding-top:5px;">
                        <span>ご請求総額:</span> <strong><?= number_format($total_req) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; color:#28a745;">
                        <span>入金済額 (50%等):</span> <strong>- <?= number_format($deposit) ?> 円</strong>
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
                    
                    <?php if ($is_common): ?>
                    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f8fafc;">
                        <strong style="display:block; margin-bottom:10px; color:#1e40af;">【共通図書（構造計算等）】</strong>
                        
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">意匠CADデータ（平面・立面・配置・矩計） <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[cad_design_all][]" multiple required style="width:100%; font-size:12px;">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">確認申請書（2面〜5面） <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[app_doc][]" accept=".pdf" multiple required style="width:100%; font-size:12px;">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">地盤調査報告書 <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[soil_report][]" accept=".pdf,.zip" multiple required style="width:100%; font-size:12px;">
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($is_sky): ?>
                    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f8fafc;">
                        <strong style="display:block; margin-bottom:10px; color:#1e40af;">【天空率計算 図書】</strong>
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">道路の資料（座標、測量図、道路台帳等） <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[road_data][]" accept=".pdf,.zip,.jpg,.png" multiple required style="width:100%; font-size:12px;">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">真北の資料 <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[true_north][]" accept=".pdf,.zip,.jpg,.png" multiple required style="width:100%; font-size:12px;">
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($is_skin): ?>
                    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f8fafc;">
                        <strong style="display:block; margin-bottom:10px; color:#1e40af;">【外皮計算 図書】</strong>
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">断熱材・サッシ等の仕様書 <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[insulation_spec][]" accept=".pdf,.zip" multiple required style="width:100%; font-size:12px;">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:3px;">設備仕様書（換気・エアコン等） <span style="color:red;">*</span></label>
                            <input type="file" name="upload_files[equipment_spec][]" accept=".pdf,.zip" multiple required style="width:100%; font-size:12px;">
                        </div>
                    </div>
                    <?php endif; ?>
                    
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
            
            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">ご提出いただいた図書</h3>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $categories = ['pdf_plan' => '平面図', 'pdf_elevation' => '立面図', 'pdf_layout' => '配置図', 'pdf_section' => '矩計図'];
                    foreach ($categories as $cat => $label) {
                        if (isset($files_by_cat[$cat])) {
                            $f = $files_by_cat[$cat][0];
                            $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                            echo "<div><strong>{$label}:</strong> <br><a href='{$url}' target='_blank' class='file-link'>📄 {$f['file_name']}</a></div>";
                        } else {
                            echo "<div><strong>{$label}:</strong> <span style='color:#999; font-size:12px;'>未提出</span></div>";
                        }
                    }
                    ?>
                </div>
            </div>

            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">納品された成果物</h3>
                <div style="font-size:12px; color:#555; margin-bottom:10px;">
                    完成した構造図や計算書はこちらからダウンロードしてください。
                </div>
                <?php if (isset($files_by_cat['structural_dwg'])): ?>
                    <?php 
                        $f = $files_by_cat['structural_dwg'][0];
                        $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                    ?>
                    <div style="padding:15px; border:1px solid #3b82f6; background:#eff6ff; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#1e40af; margin-bottom:5px;">構造図・計算書 (最新版 V<?= $f['version'] ?>)</div>
                        <a href="<?= $url ?>" target="_blank" class="file-link" style="font-size:14px; padding:10px 15px; background:#3b82f6; color:white;">
                            📄 ダウンロード
                        </a>
                    </div>
                <?php else: ?>
                    <div style="padding:20px; text-align:center; color:#999; border:1px dashed #ccc; border-radius:6px;">
                        まだ納品された成果物はありません。
                    </div>
                <?php endif; ?>
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
        
    </div>
</div>
