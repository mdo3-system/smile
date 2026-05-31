<div class="column col-left">
            <h2 class="section-title" style="background:#4a5568;">📋 案件情報と依頼主図書</h2>
            
            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">基本情報</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <strong>案件名:</strong> <?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?><br>
                    <strong>依頼主:</strong> <?= htmlspecialchars($project_info['company_name'] . ' ' . $project_info['client_name'], ENT_QUOTES) ?><br>
                    <?php if ($is_admin && !empty($project_info['client_phone'])): ?>
                    <strong>📱 電話番号:</strong> <a href="tel:<?= htmlspecialchars($project_info['client_phone'], ENT_QUOTES) ?>" style="color:#0056b3; font-weight:bold;"><?= htmlspecialchars($project_info['client_phone'], ENT_QUOTES) ?></a><br>
                    <?php elseif ($is_admin): ?>
                    <strong>📱 電話番号:</strong> <span style="color:#e53e3e; font-size:11px;">未登録（依頼主に入力を依頼してください）</span><br>
                    <?php endif; ?>
                    <strong>地盤調査:</strong> <?= htmlspecialchars($project_info['soil_status'] ?? '未定', ENT_QUOTES) ?><br>
                    <?php
                    // ステータス日本語化
                    global $status_options;
                    $status_ja = $status_options[$project_info['status']] ?? $project_info['status'];
                    
                    // 契約状態の判定
                    $has_cad = isset($files_by_cat['cad_design_all']) || isset($files_by_cat['all_in_one_zip']);
                    $contract_badge = '';
                    if ($has_cad) {
                        $contract_badge = '<span class="badge" style="background:#8b5cf6; margin-left:5px;">✅ 契約完了 (納期未定)</span>';
                    }

                    // 依頼内容の文字列化
                    $req_types = [];
                    if ($project_info['req_permit'] == 1) $req_types[] = '許容応力度設計';
                    if ($project_info['req_wall'] == 1) $req_types[] = '壁量計算';
                    if ($project_info['req_skin'] == 1) $req_types[] = '外皮計算';
                    if ($project_info['req_sky'] == 1) $req_types[] = '天空率';
                    if ($project_info['req_opt_kisohari'] == 1) $req_types[] = '基礎・横架材許容応力度';
                    $req_str = empty($req_types) ? '未指定' : implode(' / ', $req_types);
                    ?>
                    <strong>依頼内容:</strong> <span style="color:#d97706; font-weight:bold;"><?= htmlspecialchars($req_str, ENT_QUOTES) ?></span><br>
                    <strong>ステータス:</strong> <span class="badge" style="background:#007bff;"><?= htmlspecialchars($status_ja, ENT_QUOTES) ?></span><?= $contract_badge ?>
                </div>
            </div>


            
            <div class="box" style="background:#e8f5e9; border-color:#c8e6c9;">
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">最新の見積書PDF</h3>
                <div style="font-size:12px; color:#666; margin-bottom:10px;">シミュレーターで作成された見積書をPDFとして表示・印刷できます。</div>
                <?php if (!empty($all_estimates)): ?>
                    <a href="estimate_print.php?id=<?= $project_id ?>&est_id=<?= $all_estimates[0]['id'] ?>" target="_blank" style="display:block; width:100%; text-align:center; background:#28a745; color:white; text-decoration:none; padding:8px; border-radius:4px; font-weight:bold; margin-bottom:5px;">
                        📄 最新の見積書PDFを表示
                    </a>
                    <?php if (count($all_estimates) > 1): ?>
                        <details style="font-size:11px; margin-top:10px; border-top:1px dashed #c8e6c9; padding-top:5px;">
                            <summary style="cursor:pointer; font-weight:bold;">🕒 過去の履歴（再発行分）</summary>
                            <ul style="margin:5px 0 0 0; padding-left:20px; color:#555;">
                            <?php for ($i = 1; $i < count($all_estimates); $i++): $hist = $all_estimates[$i]; ?>
                                <li style="margin-bottom:3px;">
                                    <a href="estimate_print.php?id=<?= $project_id ?>&est_id=<?= $hist['id'] ?>" target="_blank" style="color:#2e7d32; text-decoration:none;">
                                        📄 <?= date('Y/m/d H:i', strtotime($hist['created_at'])) ?> 発行分
                                    </a>
                                </li>
                            <?php endfor; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                <?php else: ?>
                    <button style="width:100%; background:#777777; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:not-allowed;" disabled>
                        📄 見積書未発行
                    </button>
                <?php endif; ?>
            </div>

            <div class="box" style="background:#f1f5f9; border-color:#cbd5e1; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#334155; border-bottom:1px solid #cbd5e1; padding-bottom:5px;">📋 見積時の受領図面</h3>
                <div style="font-size:11px; color:#64748b; margin-bottom:10px;">※見積依頼時にご提示いただいた参考図面です。</div>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $est_pdf_cats = ['pdf_plan' => '平面図', 'pdf_elevation' => '立面図', 'pdf_layout' => '配置図', 'pdf_section' => '矩計図', 'pdf_area_calc' => '求積図'];
                    $has_est_files = false;
                    foreach ($est_pdf_cats as $cat => $label) {
                        if (!empty($files_by_cat[$cat])) {
                            $has_est_files = true;
                            echo "<div style='margin-bottom:8px;'><strong style='color:#1e40af; font-size:12px;'>{$label}:</strong><br>";
                            foreach ($files_by_cat[$cat] as $f) {
                                $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                    : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                                echo "<div style='margin-bottom:3px;'><a href='{$url}' target='_blank' class='file-link' style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:90%;'>📄 {$f['file_name']}</a></div>";
                            }
                            echo "</div>";
                        }
                    }
                    if (!$has_est_files) {
                        echo "<div style='color:#999; font-size:12px;'>提出された図面はありません。</div>";
                    }
                    ?>
                </div>
            </div>

            <?php if ($is_admin && $project_info['status'] !== 'completed'): ?>
            <!-- ▼▼▼ 管理者用：必要図書ステータス確認パネル ▼▼▼ -->
            <div class="box" style="background:#f8fafc; border:1px solid #cbd5e1; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#1e293b; border-bottom:1px solid #cbd5e1; padding-bottom:5px;">
                    📂 必要図書の提出ステータス
                </h3>
                <div style="font-size:12px; margin-bottom:10px;">
                    現在の依頼種別に応じて、以下の図書が必要です。すべて揃うと通知されます。
                </div>
                
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php 
                    // 許容応力度・壁量（共通図書）
                    if ($project_info['req_permit'] || $project_info['req_wall'] || (!($project_info['req_permit']||$project_info['req_wall']||$project_info['req_skin']||$project_info['req_sky']))) {
                        echo '<div style="font-size:12px;"><strong>【共通図書（構造計算等）】</strong></div>';
                        echo '<div style="font-size:11px; margin-left:10px;">';
                        echo '・意匠CADデータ: ' . (isset($files_by_cat['cad_design_all']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>') . '<br>';
                        echo '・確認申請書: ' . (isset($files_by_cat['app_doc']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>') . '<br>';
                        echo '・地盤調査報告書: ' . (isset($files_by_cat['soil_report']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>');
                        echo '</div>';
                    }
                    // 天空率
                    if ($project_info['req_sky']) {
                        echo '<div style="font-size:12px; margin-top:5px;"><strong>【天空率計算図書】</strong></div>';
                        echo '<div style="font-size:11px; margin-left:10px;">';
                        echo '・道路の資料: ' . (isset($files_by_cat['road_data']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>') . '<br>';
                        echo '・真北の資料: ' . (isset($files_by_cat['true_north']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>');
                        echo '</div>';
                    }
                    // 外皮計算
                    if ($project_info['req_skin']) {
                        echo '<div style="font-size:12px; margin-top:5px;"><strong>【外皮計算図書】</strong></div>';
                        echo '<div style="font-size:11px; margin-left:10px;">';
                        echo '・断熱材/サッシ仕様: ' . (isset($files_by_cat['insulation_spec']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>') . '<br>';
                        echo '・設備仕様書: ' . (isset($files_by_cat['equipment_spec']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">未提出</span>');
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
            <!-- ▲▲▲ 管理者用：必要図書ステータス確認パネル ▲▲▲ -->
            <?php endif; ?>

            <?php if ($is_admin && $project_info['status'] === 'primary_prep'): ?>
            <div class="box" style="background:#fff3cd; border-color:#ffeeba; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">
                    🎯 一次回答期日の設定
                </h3>
                <div style="font-size:12px; color:#666; margin-bottom:10px;">
                    依頼主から必要図書が提出されました。一次回答の期日を設定して設計スケジュールを確定させてください。
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                    <input type="hidden" name="action" value="set_primary_due_date">
                    <input type="date" name="primary_due_date" value="<?= $project_info['primary_due_date'] ?? '' ?>" required style="padding:6px; font-size:13px; border:1px solid #ccc; border-radius:4px; margin-bottom:10px; width:100%; box-sizing:border-box;">
                    <button type="submit" style="width:100%; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;">期日を設定してスケジュールを確定</button>
                </form>
            </div>
            <?php endif; ?>

            

            <?php if ($is_admin): ?>
            <!-- 管理者専用：協力業者への発注 -->
            <h2 class="section-title" style="background:#e67e22;">🤝 協力業者への発注・タスク管理</h2>
            <div class="box" style="background:#fff9f0;">
                <div style="font-size:11px; margin-bottom:5px;"><strong>自動発注額算出・発注</strong></div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                    <input type="hidden" name="action" value="order_subcontractor">
                    
                    <div style="margin-bottom:5px;">
                        <label style="font-size:11px;">
                            <input type="radio" name="order_type" value="design" checked onchange="calcSubcontractorEstimate()"> 構造用・外皮用意匠図作図
                        </label><br>
                        <label style="font-size:11px;">
                            <input type="radio" name="order_type" value="structure" onchange="calcSubcontractorEstimate()"> 構造図作図
                        </label>
                    </div>

                    <div style="display:flex; gap:5px; align-items:center; margin-bottom:5px;">
                        <input type="number" id="sub_area" name="floor_area" placeholder="床面積(㎡)" style="width:70px; font-size:12px;" oninput="calcSubcontractorEstimate()" step="0.01">
                        <span style="font-size:11px;">㎡</span>
                    </div>

                    <div id="struct_options" style="display:none; margin-bottom:5px; font-size:11px; border:1px solid #ccc; padding:5px; background:#fff;">
                        <label><input type="checkbox" name="opt_kiso" id="opt_kiso" onchange="calcSubcontractorEstimate()"> 基礎伏図 凡例・断面図 (+1,000円)</label><br>
                        <label><input type="checkbox" name="opt_yuka" id="opt_yuka" onchange="calcSubcontractorEstimate()"> 床小屋伏図 凡例 (+1,000円)</label>
                    </div>

                    <div id="sub_calc_result" style="margin-bottom:10px;"></div>
                    
                    <script>
                    function calcSubcontractorEstimate() {
                        const type = document.querySelector('input[name="order_type"]:checked').value;
                        const area = parseFloat(document.getElementById('sub_area').value) || 0;
                        const structOpts = document.getElementById('struct_options');
                        
                        let unitPrice = 0;
                        let total = 0;
                        let taskTitle = "";
                        
                        if (type === 'design') {
                            structOpts.style.display = 'none';
                            taskTitle = "構造用・外皮用意匠図作図";
                            if (area > 200) {
                                total = 50 * 100 + 40 * 100 + 30 * (area - 200);
                            } else if (area > 100) {
                                total = 50 * 100 + 40 * (area - 100);
                            } else {
                                total = 50 * area;
                            }
                        } else {
                            structOpts.style.display = 'block';
                            taskTitle = "構造図作図";
                            if (area > 200) {
                                total = 60 * 100 + 50 * 100 + 40 * (area - 200);
                            } else if (area > 100) {
                                total = 60 * 100 + 50 * (area - 100);
                            } else {
                                total = 60 * area;
                            }
                            
                            if (document.getElementById('opt_kiso').checked) total += 1000;
                            if (document.getElementById('opt_yuka').checked) total += 1000;
                        }
                        
                        // 金額の丸め処理 (切り捨て)
                        total = Math.floor(total);

                        if (area > 0) {
                            let formulaText = "";
                            if (type === 'design') {
                                if (area > 200) formulaText = `(50円×100㎡ + 40円×100㎡ + 30円×${area - 200}㎡)`;
                                else if (area > 100) formulaText = `(50円×100㎡ + 40円×${area - 100}㎡)`;
                                else formulaText = `(50円×${area}㎡)`;
                            } else {
                                if (area > 200) formulaText = `(60円×100㎡ + 50円×100㎡ + 40円×${area - 200}㎡)`;
                                else if (area > 100) formulaText = `(60円×100㎡ + 50円×${area - 100}㎡)`;
                                else formulaText = `(60円×${area}㎡)`;
                            }
                            if (type === 'structure') {
                                let optAmount = 0;
                                if (document.getElementById('opt_kiso').checked) optAmount += 1000;
                                if (document.getElementById('opt_yuka').checked) optAmount += 1000;
                                if (optAmount > 0) formulaText += ` + オプション: ${optAmount}円`;
                            }
                            
                            document.getElementById('sub_calc_result').innerHTML = 
                                `<span style="color:#28a745;font-size:12px;font-weight:bold;">算出額: ${total.toLocaleString()}円</span><br>` + 
                                `<span style="color:#666;font-size:11px;">計算式: ${formulaText}</span>`;
                        } else {
                            document.getElementById('sub_calc_result').innerHTML = '';
                        }
                        
                        document.querySelector('input[name="order_amount"]').value = total;
                        document.querySelector('input[name="task_title"]').value = taskTitle;
                    }
                    </script>

                    <select name="subcontractor_id" style="width:100%; margin-bottom:5px; font-size:12px;" required>
                        <option value="">発注先を選択</option>
                        <?php foreach($subcontractors as $sub): ?>
                            <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['contact_name'], ENT_QUOTES) ?> 様</option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="text" name="task_title" placeholder="依頼内容（自動入力）" style="width:100%; margin-bottom:5px; font-size:12px;" readonly required>
                    <input type="number" name="order_amount" placeholder="金額(税込) 自動入力" style="width:100%; margin-bottom:5px; font-size:12px;" readonly required>
                    
                    <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:5px; font-size:12px; cursor:pointer; border-radius:3px;" onclick="return confirm('発注してよろしいですか？（納期は3日後に自動設定されます）')">発注を確定・送信</button>
                </form>
            </div>

            <div style="font-size:11px; color:#555;">
                <h3 style="font-size:12px; border-bottom:1px solid #ccc; margin-top:0;">発注履歴</h3>
                <?php foreach($orders as $o): ?>
                    <div style="padding:4px 0; border-bottom:1px solid #eee;">
                        <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?>: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?> (<?= number_format($o['order_amount']) ?>円)
                        <span class="badge" style="background:#555;"><?= htmlspecialchars($o['status'], ENT_QUOTES) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                    <div style="color:#999;">発注履歴はありません。</div>
                <?php endif; ?>
            </div>

            <!-- 金銭管理フォーム -->
            <div class="box" style="background:#fff3cd; border-color:#ffeeba; margin-top:15px; padding:10px;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 金銭・請求管理</h3>
                <form method="POST" action="actions/admin_finance_post.php" style="font-size:12px;">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                        <div>
                            <label style="display:block;margin-bottom:2px;">初期見積額 (円):</label>
                            <input type="number" name="initial_est_amount" value="<?= htmlspecialchars($project_info['initial_est_amount'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">初期見積日:</label>
                            <input type="date" name="initial_est_date" value="<?= htmlspecialchars($project_info['initial_est_date'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">本見積額 (円):</label>
                            <input type="number" name="formal_est_amount" value="<?= htmlspecialchars($project_info['formal_est_amount'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">本見積日:</label>
                            <input type="date" name="formal_est_date" value="<?= htmlspecialchars($project_info['formal_est_date'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">追加費用 (円):</label>
                            <input type="number" name="add_est_amount" value="<?= htmlspecialchars($project_info['add_est_amount'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">追加見積日:</label>
                            <input type="date" name="add_est_date" value="<?= htmlspecialchars($project_info['add_est_date'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">入金済額 (円):</label>
                            <input type="number" name="deposit_amount" value="<?= htmlspecialchars($project_info['deposit_amount'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:2px;">入金日:</label>
                            <input type="date" name="deposit_date" value="<?= htmlspecialchars($project_info['deposit_date'] ?? '') ?>" style="width:100%; padding:3px; box-sizing:border-box;">
                        </div>
                    </div>
                    <button type="submit" class="btn" style="width:100%; padding:6px; background:#28a745;">金銭データを保存</button>
                </form>
            </div>

            <!-- 協力業者ダッシュボードへの切り替えリンク -->
            <div style="margin-top:15px; padding:10px; background:#e8f0fe; border:1px solid #93c5fd; border-radius:6px; text-align:center;">
                <div style="font-size:11px; color:#555; margin-bottom:8px;">この案件を協力業者視点で確認する</div>
                <a href="project_subcontractor.php?id=<?= $project_id ?>" target="_blank" style="display:inline-block; background:#3b82f6; color:white; padding:7px 15px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">👷 協力業者ダッシュボードで見る</a>
            </div>
            <?php endif; ?>
        </div>