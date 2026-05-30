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
                    <strong>ステータス:</strong> <span class="badge" style="background:#007bff;"><?= htmlspecialchars($project_info['status'], ENT_QUOTES) ?></span>
                </div>
            </div>

            <div class="box" style="background:#fff3cd; border-color:#ffeeba;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 ご請求・お支払い状況</h3>
                <div style="font-size:13px; line-height:1.8;">
                    <?php
                        $formal_estimate = $project_info['total_amount'] ?? 0; // TODO: DBから正式見積額を取得
                        $deposit = $project_info['deposit_amount'] ?? 0;
                        $additional = $project_info['additional_amount'] ?? 0;
                        $total_req = $formal_estimate + $additional;
                        $balance = $total_req - $deposit;
                    ?>
                    <div style="display:flex; justify-content:space-between;">
                        <span>正式お見積額:</span> <strong><?= number_format($formal_estimate) ?> 円</strong>
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
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">最新の見積書PDF</h3>
                <form action="estimate_print.php" method="GET" target="_blank">
                    <input type="hidden" name="id" value="<?= $project_id ?>">
                    <button type="submit" style="width:100%; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;">
                        📄 最新の見積書を開く（印刷・PDF保存）
                    </button>
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
            <div class="box">
                <div class="chat-container">
                    <?php foreach ($chat_messages as $msg): ?>
                        <div class="chat-msg">
                            <?php 
                                $isAdminMsg = ($msg['sender_id'] == 1);
                                $name = $isAdminMsg ? 'サポート担当者' : 'あなた';
                                $color = $isAdminMsg ? '#0056b3' : '#28a745';
                            ?>
                            <div style="font-weight:bold; color:<?= $color ?>; margin-bottom:3px;"><?= $name ?></div>
                            <div style="white-space:pre-wrap;"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($chat_messages)): ?>
                        <span style="color:#999; font-size:12px;">メッセージはありません。</span>
                    <?php endif; ?>
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:10px;">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="message_text" placeholder="メッセージを入力してください..." style="width:100%; height:60px; margin-bottom:5px; font-size:12px; box-sizing:border-box; padding:8px; border:1px solid #ccc; border-radius:4px;" required></textarea>
                    <button type="submit" style="width:100%; background:#17a2b8; color:white; border:none; padding:8px; cursor:pointer; font-size:12px; font-weight:bold; border-radius:4px;">送信</button>
                </form>
            </div>
        </div>
        
    </div>
</div>
