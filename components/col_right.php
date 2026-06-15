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
                <h2 class="section-title" style="background:#17a2b8; margin:0;">💬 依頼主チャット</h2>
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
                            } catch (Exception $e) {
                                echo '<option value="">(ファイルの読み込みに失敗しました)</option>';
                            }
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
