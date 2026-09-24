<div class="column col-right" style="padding: 15px;">
            <?php if ($is_admin && count($delivered_orders) > 0): ?>
                <div class="box" style="background:#fff3cd; border: 1px solid #ffeeba; margin-bottom:15px;">
                    <h3 style="margin-top:0; color:#856404; font-size:13px;">🔔 納品確認エリア（成果物の承認待ち）</h3>
                    <?php foreach ($delivered_orders as $del): ?>
                        <div style="font-size:11px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px dashed #ffeeba; color:#666;">
                            <strong>担当者:</strong> <?= htmlspecialchars($del['contact_name'], ENT_QUOTES) ?> 様<br>
                            <strong>タスク:</strong> <?= htmlspecialchars($del['task_title'], ENT_QUOTES) ?><br>
                            <strong>納品物:</strong><br>
                            <?php if ($del['pdf_id']): 
                                $pdf_url = (strpos($del['pdf_id'], 'uploads/') !== 0 && !empty($del['pdf_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['pdf_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['pdf_id'], ENT_QUOTES);
                            ?>
                                - <a href="<?= $pdf_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">📄 構造図PDF (V<?= $del['pdf_ver'] ?>)</a><br>
                            <?php endif; ?>
                            <?php if ($del['arc_d_id']): 
                                $arc_d_url = (strpos($del['arc_d_id'], 'uploads/') !== 0 && !empty($del['arc_d_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['arc_d_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['arc_d_id'], ENT_QUOTES);
                            ?>
                                - <a href="<?= $arc_d_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">📁 意匠用アーキデータ (V<?= $del['arc_d_ver'] ?>)</a><br>
                            <?php endif; ?>
                            <?php if ($del['arc_s_id']): 
                                $arc_s_url = (strpos($del['arc_s_id'], 'uploads/') !== 0 && !empty($del['arc_s_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['arc_s_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['arc_s_id'], ENT_QUOTES);
                            ?>
                                - <a href="<?= $arc_s_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">📁 構造用アーキデータ (V<?= $del['arc_s_ver'] ?>)</a><br>
                            <?php endif; ?>
                            
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:8px; display:flex; flex-direction:column; gap:6px;">
                                <input type="hidden" name="action" value="approve_delivery">
                                <input type="hidden" name="order_id" value="<?= $del['id'] ?>">
                                <div style="display:flex; flex-direction:column; gap:2px;">
                                    <label style="font-size:10px; color:#555; font-weight:bold;">公開用ファイル名 (任意リネーム):</label>
                                    <input type="text" name="custom_file_name" value="<?= htmlspecialchars(($project_info['project_name'] ?? '成果物') . '_構造図.pdf', ENT_QUOTES) ?>" style="padding:3px 6px; font-size:11px; border:1px solid #cbd5e1; border-radius:4px; width:100%; box-sizing:border-box;" placeholder="例: ○○様邸_構造図.pdf">
                                </div>
                                <div style="display:flex; align-items:center; gap:5px;">
                                    <label style="font-size:10px; color:#666;">完了日を指定:</label>
                                    <input type="date" name="completed_at" value="<?= date('Y-m-d') ?>" style="padding:2px 5px; font-size:11px; border:1px solid #ccc; border-radius:4px;" required>
                                </div>
                                <div style="display:flex; gap:5px;">
                                    <button type="submit" style="background:#28a745; color:white; border:none; padding:4px 10px; font-size:11px; border-radius:3px; cursor:pointer; font-weight:bold; flex:1;">承諾して依頼主に公開</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h2 class="section-title" style="background:#17a2b8; margin:0;">💬 依頼主チャット <span style="font-size:10px; font-weight:normal; margin-left:10px; color:#fff3cd;">※チェックバックは添付ファイルを添えてチャットにUPして下さい。</span></h2>
            </div>

            <!-- チャットエリア -->
            <div class="chat-wrapper">
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($chat_messages as $msg):
                        $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                        $rowClass = $isMe ? 'from-me' : '';
                        
                        $senderRole = $msg['sender_role'] ?? (($msg['sender_id'] == 1) ? 'admin' : 'client');
                        $bubbleClass = 'bubble-client';
                        $avatarClass = 'client-avatar';
                        $avatarIcon  = '👤';
                        $senderName  = htmlspecialchars($project_info['client_name'] ?? '依頼主', ENT_QUOTES);
                        
                        if ($senderRole === 'admin') {
                            $bubbleClass = 'bubble-admin';
                            $avatarClass = 'admin-avatar';
                            $avatarIcon  = '👷';
                            $senderName  = '設計担当';
                        } elseif ($senderRole === 'accountant') {
                            $bubbleClass = 'bubble-admin';
                            $avatarClass = 'accountant-avatar';
                            $avatarIcon  = '💼';
                            $senderName  = '経理担当';
                        }
                        
                        $timeStr = date('m/d H:i', strtotime($msg['created_at'] ?? 'now'));
                    ?>
                        <div class="chat-bubble-row <?= $rowClass ?>" data-msg-id="<?= $msg['id'] ?>">
                            <?php if (!$isMe): ?>
                                <div class="chat-avatar <?= $avatarClass ?>" title="<?= $senderName ?>"><?= $avatarIcon ?></div>
                            <?php endif; ?>
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
                                        // Google Drive IDかローカルパスかを判定
                                        $isGdrive = (strlen($fpath) > 15 && strpos($fpath, '/') === false && strpos($fpath, 'uploads/') !== 0);
                                        $isImage = ($ftype === 'image') || preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $fpath);
                                        $furl = $isGdrive ? 'https://drive.google.com/file/d/' . htmlspecialchars($fpath, ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($fpath, ENT_QUOTES);
                                        $thumbUrl = $isGdrive ? 'https://drive.google.com/thumbnail?id=' . htmlspecialchars($fpath, ENT_QUOTES) . '&sz=w400' : htmlspecialchars($fpath, ENT_QUOTES);
                                    ?>
                                    <?php if ($isImage): ?>
                                        <a href="<?= $furl ?>" target="_blank" style="display:block; margin-top:4px;">
                                            <img src="<?= $thumbUrl ?>" class="chat-image-thumb" style="max-width:220px; max-height:220px; border-radius:6px; display:block; border:1px solid #ccc;" alt="添付画像">
                                        </a>
                                        <a href="<?= $furl ?>" target="_blank" style="color:#0056b3; font-size:11px; text-decoration:none; font-weight:bold;">🖼 画像を拡大表示</a>
                                    <?php else: ?>
                                        <a href="<?= $furl ?>" target="_blank" class="chat-pdf-link">📄 添付ファイルを開く</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="chat-time">
                                    <?= $timeStr ?>
                                    <?php if ($isMe || $_SESSION['role'] === 'admin'): ?>
                                        <span class="chat-delete-btn" style="cursor:pointer; color:#ef4444; font-size:10px; margin-left:8px; text-decoration:underline;" onclick="deleteChatMessage(<?= $msg['id'] ?>, 'project')">取り消し</span>
                                    <?php endif; ?>
                                </div>
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
                            } catch (Exception $e) {
                                echo '<option value="">(ファイルの読み込みに失敗しました)</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="chat-input-row">
                        <label class="chat-attach-btn" title="ファイルを添付">
                            📎
                            <input type="file" id="chatFileInput" style="display:none;" onchange="previewFile(this)" multiple>
                        </label>
                        <textarea id="chatTextarea" class="chat-textarea" placeholder="メッセージを入力..." rows="1" oninput="autoExpandTextarea(this)" onkeydown="handleKey(event)"></textarea>
                        <button class="chat-send-btn" onclick="sendMessage()" title="送信">➤</button>
                    </div>
                    <?php if (!$is_admin): ?>
                        <div style="margin-top:6px; font-size:10.5px; color:#b45309; background:#fffbeb; border:1px solid #fef3c7; border-radius:4px; padding:4px 8px; display:flex; align-items:center; gap:5px;">
                            <span>💡</span>
                            <span><strong>補正通知書・質疑書をお持ちの方へ:</strong> チャットではなく下の<strong>【補正通知書スロット】</strong>にUPしてください（自動で管理者ボールに切り替わり通知されます）。</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- チェックバック（修正指示）入力・更新枠 専用ボックス -->
            <div style="margin-top:15px; padding:12px; background:#fff5f5; border:1px solid #feb2b2; border-radius:8px;">
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin:0; display:flex; flex-direction:column; gap:8px;">
                    <input type="hidden" name="action" value="submit_client_checkback">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab ?? '', ENT_QUOTES) ?>">
                    
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label style="font-size:12px; font-weight:bold; color:#c53030;">📝 チェックバック（修正指示）入力・更新枠:</label>
                        <span style="font-size:10px; color:#9b2c2c;">※送信内容はチャットに自動UPされます</span>
                    </div>

                    <div style="display:flex; gap:6px;">
                        <select name="target_file" style="width:100%; padding:4px; border:1px solid #cbd5e1; border-radius:4px; font-size:11px; color:#475569;">
                            <option value="">-- 対象図書を選択（任意） --</option>
                            <?php if (!empty($uploaded_file_names)): ?>
                                <?php foreach ($uploaded_file_names as $fname): ?>
                                    <option value="<?= htmlspecialchars($fname, ENT_QUOTES) ?>">📎 <?= htmlspecialchars($fname, ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <textarea name="checkback_text" style="width:100%; min-height:70px; padding:8px; box-sizing:border-box; font-size:12px; border:1px solid #cbd5e1; border-radius:4px; resize:vertical;" placeholder="修正指示・指示内容を入力してください..."></textarea>

                    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:11px; flex-wrap:wrap; background:#fff; padding:6px 8px; border:1px solid #e2e8f0; border-radius:4px;">
                        <div style="display:flex; align-items:center; gap:5px;">
                            <label style="font-weight:bold; color:#475569;">📎 指示用ファイルをUP:</label>
                            <input type="file" name="checkback_file" style="font-size:11px;">
                        </div>
                        <button type="submit" style="background:#dc3545; color:white; border:none; padding:6px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;" onclick="return confirm('チェックバック（修正指示）を保存してチャットに投稿しますか？')">
                            チェックバックを保存・チャット送信
                        </button>
                    </div>
                </form>
            </div>

            <!-- 補正通知書・追加質疑書 専用スロット -->
            <div id="chat_correction_slot" style="margin-top:12px; padding:12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:12px; font-weight:bold; color:#1d4ed8;">📂 補正通知書・質疑書スロット:</label>
                    <span style="font-size:10px; color:#2563eb;">※UPで自動「補正対応中」移行＆管理者へ通知</span>
                </div>
                
                <?php
                $corr_history = $files_by_cat['correction_notice'] ?? [];
                $corr_actual_history = [];
                if (!empty($corr_history)) {
                    foreach ($corr_history as $h) {
                        if (!empty($h['drive_file_id']) || $h['file_name'] === '【他ファイルに記載】') {
                            $corr_actual_history[] = $h;
                        }
                    }
                }
                $has_corr_file = !empty($corr_actual_history);
                ?>

                <div style="background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:8px;">
                    <?php if ($has_corr_file): 
                        $corr_latest = $corr_actual_history[0];
                        $corr_url = (strpos($corr_latest['drive_file_id'], 'uploads/') !== 0 && !empty($corr_latest['drive_file_id'])) 
                            ? 'https://drive.google.com/file/d/' . htmlspecialchars($corr_latest['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                            : htmlspecialchars($corr_latest['drive_file_id'], ENT_QUOTES);
                    ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px; margin-bottom:5px;">
                            <a href="<?= $corr_url ?>" target="_blank" class="file-link" style="background:#2563eb; color:white; border-color:#1d4ed8; padding:3px 8px; border-radius:3px; font-size:11px; text-decoration:none; font-weight:bold;">
                                📄 最新の補正通知書 (V<?= $corr_latest['version'] ?>)
                            </a>
                            
                            <?php if (count($corr_actual_history) > 1): ?>
                                <select onchange="if(this.value) window.open(this.value, '_blank');" style="font-size:11px; padding:3px; max-width:140px;">
                                    <option value="">過去バージョン (<?= count($corr_actual_history) - 1 ?>件)...</option>
                                    <?php foreach ($corr_actual_history as $idx => $h): 
                                        if ($idx === 0) continue; 
                                        $h_url = (strpos($h['drive_file_id'], 'uploads/') !== 0 && !empty($h['drive_file_id'])) 
                                            ? 'https://drive.google.com/file/d/' . htmlspecialchars($h['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                            : htmlspecialchars($h['drive_file_id'], ENT_QUOTES);
                                        $dateStr = date('m/d H:i', strtotime($h['created_at']));
                                    ?>
                                        <option value="<?= $h_url ?>">V<?= $h['version'] ?> (<?= $dateStr ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <select disabled style="font-size:11px; padding:3px; max-width:140px; opacity:0.6; cursor:not-allowed;">
                                    <option value="">過去バージョンなし</option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:10px; color:#64748b; margin-bottom:6px; word-break:break-all;">
                            <?= htmlspecialchars($corr_latest['file_name'], ENT_QUOTES) ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:11px; color:#ef4444; margin-bottom:5px;">現在、登録されている補正通知書はありません（未提出）</div>
                    <?php endif; ?>

                    <?php if (!$is_admin): ?>
                        <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin:0; display:flex; flex-direction:column; gap:4px; border-top:1px dashed #e2e8f0; padding-top:6px;">
                            <input type="hidden" name="file_category" value="correction_notice">
                            <input type="hidden" name="action_type" value="single_upload">
                            
                            <div style="display:flex; gap:4px; align-items:center;">
                                <input type="file" name="upload_file" id="chat_correction_file_input" required style="font-size:11px; flex:1; min-width:120px; padding:2px;">
                                <button type="submit" style="font-size:11px; background:#2563eb; color:white; border:none; padding:4px 8px; border-radius:3px; cursor:pointer; font-weight:bold; white-space:nowrap;" onclick="return confirm('補正通知書をアップロードしますか？\n（案件ステータスが「補正対応中」になり、管理者に通知されます）')">
                                    <?= $has_corr_file ? '差し替えUP' : '補正通知書をUP' ?>
                                </button>
                            </div>
                            <?php if ($has_corr_file): ?>
                                <input type="text" name="update_reason" placeholder="差し替え・追加理由（例：2回目質疑通知書、再審査通知等）" required style="font-size:10px; width:100%; padding:3px; border:1px solid #cbd5e1; border-radius:3px; box-sizing:border-box;">
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>

