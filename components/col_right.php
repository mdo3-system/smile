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
                            <?php if ($del['arc_id']): 
                                $arc_url = (strpos($del['arc_id'], 'uploads/') !== 0 && !empty($del['arc_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['arc_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['arc_id'], ENT_QUOTES);
                            ?>
                                - <a href="<?= $arc_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">📁 アーキトレンドデータ (V<?= $del['arc_ver'] ?>)</a><br>
                            <?php endif; ?>
                            
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:8px;">
                                <input type="hidden" name="action" value="approve_delivery">
                                <input type="hidden" name="order_id" value="<?= $del['id'] ?>">
                                <button type="submit" style="background:#28a745; color:white; border:none; padding:4px 10px; font-size:11px; border-radius:3px; cursor:pointer;">承認してクライアントへ公開</button>
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
                        $bubbleClass = ($msg['sender_id'] == 1) ? 'bubble-admin' : 'bubble-client';
                        $avatarClass = ($msg['sender_id'] == 1) ? 'admin-avatar' : 'client-avatar';
                        $avatarIcon  = ($msg['sender_id'] == 1) ? '👷' : '👤';
                        $senderName  = ($msg['sender_id'] == 1) ? '管理者' : htmlspecialchars($project_info['client_name'], ENT_QUOTES);
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

        <?php if ($is_admin): ?>
            <!-- ==============================================
                 👷 協力業者専用チャット (Admin <-> Subcontractor) 
                 ============================================== -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:30px; margin-bottom:10px;">
                <h2 class="section-title" style="background:#e67e22; margin:0;">👷 協力業者連絡チャット</h2>
            </div>
            
            <div class="chat-wrapper" style="border: 1px solid #e67e22;">
                <div class="chat-messages" id="subChatMessages" style="background: #fdf6e3;">
                    <?php 
                    // 協力業者チャット履歴の取得
                    $stmtSubMsgs = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'sub_admin' ORDER BY id ASC");
                    $stmtSubMsgs->execute(['pid' => $project_id]);
                    $sub_chat_messages = $stmtSubMsgs->fetchAll();
                    ?>
                    <?php foreach ($sub_chat_messages as $msg):
                        $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                        $rowClass = $isMe ? 'from-me' : '';
                        $bubbleClass = ($msg['sender_id'] == 1) ? 'bubble-admin' : 'bubble-sub';
                        $avatarClass = ($msg['sender_id'] == 1) ? 'admin-avatar' : 'sub-avatar';
                        $avatarIcon  = ($msg['sender_id'] == 1) ? '👷' : '🧑‍🔧';
                        $senderName  = ($msg['sender_id'] == 1) ? 'あなた (管理者)' : '協力業者';
                        $fpath       = $msg['file_path'] ?? '';
                    ?>
                        <div class="chat-message-row <?= $rowClass ?>">
                            <div class="chat-avatar <?= $avatarClass ?>"><?= $avatarIcon ?></div>
                            <div class="chat-bubble-container">
                                <div class="chat-sender-name"><?= $senderName ?> <span class="chat-time"><?= date('m/d H:i', strtotime($msg['created_at'])) ?></span></div>
                                <?php if (!empty($msg['message_text'])): ?>
                                    <div class="chat-bubble <?= $bubbleClass ?>">
                                        <?= nl2br(htmlspecialchars($msg['message_text'], ENT_QUOTES)) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($fpath)): 
                                    $furl = (strpos($fpath, 'uploads/') !== 0 && strlen($fpath) > 15 && strpos($fpath, '/') === false) 
                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($fpath, ENT_QUOTES) . '/view?usp=drivesdk' 
                                        : htmlspecialchars($fpath, ENT_QUOTES);
                                ?>
                                    <div class="chat-bubble <?= $bubbleClass ?>" style="margin-top:4px;">
                                        <a href="<?= $furl ?>" target="_blank" style="color:inherit; font-weight:bold; text-decoration:underline;">
                                            <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $fpath)) echo "🖼 画像を見る"; else echo "📄 添付ファイルを見る"; ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($sub_chat_messages)): ?>
                        <div style="text-align:center; color:#aaa; font-size:12px; margin-top:40px;">協力業者とのメッセージはまだありません</div>
                    <?php endif; ?>
                </div>

                <!-- 入力エリア -->
                <div class="chat-input-area">
                    <div id="subFilePreview" class="chat-file-preview"></div>
                    <div class="chat-input-row">
                        <label class="chat-attach-btn" title="ファイルを添付">
                            📎
                            <input type="file" id="subChatFileInput" accept="image/*,.pdf" style="display:none;" onchange="previewSubFile(this)">
                        </label>
                        <textarea id="subChatTextarea" class="chat-textarea" placeholder="業者へのメッセージを入力..." rows="1" onkeydown="handleSubKey(event)"></textarea>
                        <button class="chat-send-btn" style="background:#e67e22;" onclick="sendSubMessage()" title="送信">➤</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        </div>
