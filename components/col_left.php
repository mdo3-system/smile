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

            <?php require __DIR__ . '/col_estimate_files.php'; ?>

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
            <!-- 協力業者ダッシュボードへの切り替えリンク -->
            <div style="margin-top:10px; text-align:center;">
                <a href="project_subcontractor.php?id=<?= $project_id ?>" target="_blank" style="display:inline-block; background:#3b82f6; color:white; padding:7px 15px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">👷 協力業者への発注・管理ダッシュボードを開く</a>
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