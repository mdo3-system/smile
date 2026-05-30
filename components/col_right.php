<div class="column col-right" style="padding: 15px;">
            <?php if ($is_admin && count($delivered_orders) > 0): ?>
                <div class="box" style="background:#fff3cd; border: 1px solid #ffeeba; margin-bottom:15px;">
                    <h3 style="margin-top:0; color:#856404; font-size:13px;">🔔 納品確認エリア（成果物の承認待ち）</h3>
                    <?php foreach ($delivered_orders as $del): ?>
                        <div style="font-size:11px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px dashed #ffeeba; color:#666;">
                            <strong>担当者:</strong> <?= htmlspecialchars($del['contact_name'], ENT_QUOTES) ?> 様<br>
                            <strong>タスク:</strong> <?= htmlspecialchars($del['task_title'], ENT_QUOTES) ?><br>
                            <strong>納品物:</strong> 
                            <?php if ($del['drive_file_id']): 
                                $download_url = (strpos($del['drive_file_id'], 'uploads/') !== 0 && !empty($del['drive_file_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($del['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk' 
                                    : htmlspecialchars($del['drive_file_id'], ENT_QUOTES);
                            ?>
                                <a href="<?= $download_url ?>" target="_blank" style="color:#0056b3; font-weight:bold; text-decoration:none;">📄 確認する (V<?= $del['version'] ?>)</a>
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
            <!-- 管理者専用エリア -->
            <div style="margin-top: 20px; border-top: 2px dashed #ccc; padding-top: 15px;">
                <div style="font-size:11px; font-weight:bold; color:#c0392b; margin-bottom:10px;">🔒 以下は管理者のみに表示されます</div>
                
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2 class="section-title" style="background:#28a745; margin:0; width:auto; display:inline-block; padding:5px 10px;">💰 自動見積シミュレーター</h2>
                    <div style="display:flex; align-items:center; gap:10px; background:#e8f5e9; border:1px solid #28a745; padding:4px 10px; border-radius:5px; font-size:11px;">
                        <strong>📂 Drive連携:</strong>
                        <?php if (file_exists(__DIR__ . '/../token.json')): ?>
                            <span style="color:#28a745; font-weight:bold;">🟢 完了</span>
                        <?php else: ?>
                            <span style="color:#dc3545; font-weight:bold;">🔴 未連携</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="box" style="background:#e8f5e9; font-size:11px; display:flex; flex-direction:column; gap:10px;">
                    <!-- 計算タイプの選択 -->
                    <div>
                        <strong>計算タイプ（複数選択可）</strong><br>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_permit" onchange="toggleEstContainers(); calcClientEstimate();" <?= ($project_info['req_permit'] == 1) ? 'checked' : '' ?>> 許容応力度計算</label>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_wall" onchange="toggleEstContainers(); calcClientEstimate();" <?= ($project_info['req_wall'] == 1) ? 'checked' : '' ?>> 性能表示壁量計算（性能表示のみ）</label>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_skin" onchange="toggleEstContainers(); calcClientEstimate();" <?= ($project_info['req_skin'] == 1) ? 'checked' : '' ?>> 外皮計算（一次エネ計算セット）</label>
                        <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_sky" onchange="toggleEstContainers(); calcClientEstimate();" <?= ($project_info['req_sky'] == 1) ? 'checked' : '' ?>> 天空率計算</label>
                    </div>

                    <!-- 1. 許容応力度計算用フォーム -->
                    <div id="container_permit" class="box" style="background:#ffffff; border:1px solid #ccc; display:block; padding:8px; margin:0;">
                        <strong style="color:#2e7d32;">【許容応力度計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                基本料金<br>
                                <select id="est_base_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="75000">平屋建・2階建 (75,000円)</option>
                                    <option value="100000">3階建 (100,000円)</option>
                                </select>
                            </div>
                            <div>
                                構造床面積 (㎡) <span style="color:#666;">*150㎡超は600円/㎡加算</span><br>
                                <input type="number" id="est_area_permit" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                目標等級加算<br>
                                <select id="est_grade_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="0">なし (0円)</option>
                                    <option value="40000">耐震等級3+耐風等級2 (+40,000円)</option>
                                    <option value="20000">耐震等級2 (+20,000円)</option>
                                    <option value="40000">耐震等級3 (+40,000円)</option>
                                </select>
                            </div>
                            <div>
                                形状・仕様加算（基本料金+面積割増に乗算）<br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.2" onchange="calcClientEstimate()"> 準耐火/耐火構造 (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.2" onchange="calcClientEstimate()"> PH階がある (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.1" onchange="calcClientEstimate()"> 小屋裏収納がある (+10%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="0.1" onchange="calcClientEstimate()"> スキップ等レベル違い (+10%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="1.0" onchange="calcClientEstimate()"> 平面不整形 (+100%)</label><br>
                                <label><input type="checkbox" class="est_mult_permit" value="1.0" onchange="calcClientEstimate()"> 立面不整形 (+100%)</label>
                            </div>
                            <div>
                                その他加算（固定額）<br>
                                <label>金物工法階数: <input type="number" id="est_kanamono_permit" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 階 (+15,000円/階)</label><br>
                                <label>斜め壁等特殊箇所数: <input type="number" id="est_special_permit" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 2. 性能表示壁量計算用フォーム -->
                    <div id="container_wall" class="box" style="background:#ffffff; border:1px solid #ccc; display:none; padding:8px; margin:0;">
                        <strong style="color:#c0392b;">【性能表示壁量計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                基本料金<br>
                                <select id="est_base_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="35000">性能表示 平屋建 (35,000円)</option>
                                    <option value="50000">性能表示 2階建 (50,000円)</option>
                                </select>
                            </div>
                            <div>
                                構造床面積 (㎡) <span style="color:#666;">*150㎡超は500円/㎡加算</span><br>
                                <input type="number" id="est_area_wall" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                構造図（基礎伏図）作成<br>
                                <select id="est_dwg_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="0">依頼なし (0円)</option>
                                    <option value="15000">建築面積 50㎡未満 (+15,000円)</option>
                                    <option value="20000">建築面積 100㎡未満 (+20,000円)</option>
                                    <option value="25000">建築面積 150㎡未満 (+25,000円)</option>
                                    <option value="30000">建築面積 150㎡以上 (+30,000円)</option>
                                </select>
                            </div>
                            <div>
                                人通孔箇所数割増<br>
                                <select id="est_jintsu_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="0">5箇所未満 (0円)</option>
                                    <option value="5000">5箇所以上10箇所未満 (+5,000円)</option>
                                    <option value="10000">10箇所以上 (+10,000円)</option>
                                </select>
                            </div>
                            <div>
                                基礎梁許容応力度計算<br>
                                <label><input type="checkbox" id="est_kisohari_wall" onchange="calcClientEstimate()"> 依頼する (+20,000円、※150㎡超は500円/㎡加算)</label>
                            </div>
                            <div>
                                形状加算（基本料金+面積割増に乗算）<br>
                                <label><input type="checkbox" class="est_mult_wall" value="0.2" onchange="calcClientEstimate()"> PH階がある (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_wall" value="0.1" onchange="calcClientEstimate()"> 小屋裏収納がある (+10%)</label><br>
                                <label><input type="checkbox" class="est_mult_wall" value="0.1" onchange="calcClientEstimate()"> スキップレベル違いがある (+10%)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 3. 外皮計算用フォーム -->
                    <div id="container_skin" class="box" style="background:#ffffff; border:1px solid #ccc; display:none; padding:8px; margin:0;">
                        <strong style="color:#d35400;">【外皮計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                基本料金<br>
                                <select id="est_base_skin" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                                    <option value="20000">平屋建 (20,000円)</option>
                                    <option value="35000">2階建 (35,000円)</option>
                                    <option value="50000">3階建 (50,000円)</option>
                                </select>
                            </div>
                            <div>
                                外皮床面積 (㎡) <span style="color:#666;">*100㎡超は500円/㎡加算</span><br>
                                <input type="number" id="est_area_skin" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                形状加算（基本料金+面積割増に乗算）<br>
                                <label><input type="checkbox" class="est_mult_skin" value="0.2" onchange="calcClientEstimate()"> PH階がある (+20%)</label><br>
                                <label><input type="checkbox" class="est_mult_skin" value="0.1" onchange="calcClientEstimate()"> スキップレベル違いがある (+10%)</label>
                            </div>
                            <div>
                                その他加算（固定額）<br>
                                <label>基礎立上り400超箇所数: <input type="number" id="est_kisotachi_skin" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label><br>
                                <label><input type="checkbox" id="est_setsumei_skin" onchange="calcClientEstimate()"> 設計内容説明書を作成する (+15,000円)</label><br>
                                <label><input type="checkbox" id="est_energy_skin" checked disabled> 一次消費エネルギー計算書 (+15,000円 ※セット)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 4. 天空率用フォーム -->
                    <div id="container_sky" class="box" style="background:#ffffff; border:1px solid #ccc; display:none; padding:8px; margin:0;">
                        <strong style="color:#2980b9;">【天空率計算オプション】</strong>
                        <div style="margin-top:5px; display:grid; gap:6px;">
                            <div>
                                対象斜線<br>
                                <label><input type="checkbox" id="est_road_sky" onchange="calcClientEstimate()" checked> 道路斜線天空率 (50,000円)</label><br>
                                <label><input type="checkbox" id="est_north_sky" onchange="calcClientEstimate()"> 北側斜線天空率 (50,000円)</label>
                            </div>
                            <div>
                                追加検討斜線面数<br>
                                <label>追加面数: <input type="number" id="est_extra_sky" value="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 面 (+25,000円/面、※1面目は基本料金に含む)</label>
                            </div>
                            <div>
                                敷地面積 (㎡) <span style="color:#666;">*150㎡超は200円/㎡加算</span><br>
                                <input type="number" id="est_site_area_sky" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                建物床面積 (㎡) <span style="color:#666;">*150㎡超は200円/㎡加算</span><br>
                                <input type="number" id="est_building_area_sky" value="100" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            </div>
                            <div>
                                詳細モデル検討加算<br>
                                <label><input type="checkbox" id="est_detail_sky" onchange="calcClientEstimate()"> 建物の詳細モデルによる検討を行う (+15,000円)</label>
                            </div>
                        </div>
                    </div>

                    <!-- 計算結果表示 -->
                    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
                        見積合計 (税抜): <span id="est_total_disp" style="color:#d32f2f; font-size:12px;">0</span> 円<br>
                        消費税 (10%): <span id="est_tax_disp" style="color:#555; font-size:11px;">0</span> 円<br>
                        税込合計: <span id="est_grand_total_disp" style="color:#28a745; font-size:12px;">0</span> 円
                    </div>

                    <div style="margin-top:10px; display:flex; gap:10px; flex-direction:column;">
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="calcClientEstimate()" style="flex:1; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">再計算</button>
                            <button type="button" id="pdf_issue_btn" onclick="saveAndPrintEstimate()" style="flex:2; background:#ff9800; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">印刷用PDFを発行</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

