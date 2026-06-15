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
                        if (!empty($project_info['primary_due_date'])) {
                            $contract_badge = '<span class="badge" style="background:#8b5cf6; margin-left:5px;">✅ 契約完了</span>';
                        } else {
                            $contract_badge = '<span class="badge" style="background:#8b5cf6; margin-left:5px;">✅ 契約完了 (着手日未定)</span>';
                        }
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

            <?php 
            $is_accountant = ($_SESSION['role'] === 'accountant');
            if ($is_admin || $is_accountant): 
            ?>
            <div class="box" style="background:#fff3cd; border-color:#ffeeba; margin-top:15px; padding:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 経理・請求管理（管理者・経理用）</h3>
                
                <?php
                    $initial = $project_info['initial_est_amount'] ?? 0;
                    $initial_date = $project_info['initial_est_date'] ?? '';
                    $formal = $project_info['formal_est_amount'] ?? 0;
                    $formal_date = $project_info['formal_est_date'] ?? '';
                    
                    // 複数追加見積のパース
                    $add_estimates = json_decode($project_info['additional_estimates'] ?? '[]', true) ?: [];
                    
                    $dep_50 = $project_info['deposit_amount_50'] ?? 0;
                    $dep_date_50 = $project_info['deposit_date_50'] ?? '';
                    $dep_rem = $project_info['deposit_amount_rem'] ?? 0;
                    $dep_date_rem = $project_info['deposit_date_rem'] ?? '';

                    // 合計追加費用
                    $total_add = 0;
                    foreach ($add_estimates as $ae) {
                        $total_add += intval($ae['amount']);
                    }

                    $total_req = $formal + $total_add;
                    $total_deposit = $dep_50 + $dep_rem;
                    $balance = $total_req - $total_deposit;

                    // 一次請求額の計算 (消費税加算前税抜の50% + 消費税10%)
                    $primary_invoice_amount = 0;
                    if ($formal > 0) {
                        $base_formal = round($formal / 1.1);
                        $subtotal_primary = round($base_formal * 0.5);
                        $tax_primary = round($subtotal_primary * 0.1);
                        $primary_invoice_amount = $subtotal_primary + $tax_primary;
                    }
                ?>
                <div style="font-size:13px; line-height:1.8; margin-bottom:15px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>初期お見積額 (<?= $initial_date ? htmlspecialchars($initial_date) : '-' ?>):</span> <strong><?= number_format($initial) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>本見積額 (<?= $formal_date ? htmlspecialchars($formal_date) : '-' ?>):</span> <strong><span id="disp_formal"><?= number_format($formal) ?></span> 円</strong>
                    </div>
                    
                    <!-- 追加見積一覧の表示 -->
                    <div id="disp_add_list" style="margin-left: 10px; font-size:12px; color:#555;">
                        <?php foreach ($add_estimates as $idx => $ae): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・追加見積 #<?= $idx+1 ?> (<?= htmlspecialchars($ae['date'] ?: '-') ?>):</span>
                                <strong>+ <?= number_format($ae['amount']) ?> 円</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px dashed #ccc; padding-top:5px;">
                        <span>合計請求額 (本見積＋追加):</span> <strong><span id="disp_total_req" style="color:#d32f2f;"><?= number_format($total_req) ?></span> 円</strong>
                    </div>
                    
                    <div style="display:flex; justify-content:space-between; color:#4a5568; margin-top: 5px; margin-bottom: 2px;">
                        <span>└ 一次請求予定額 (50%):</span> <strong><?= number_format($primary_invoice_amount) ?> 円</strong>
                    </div>
                    
                    <div style="display:flex; justify-content:space-between; color:#28a745; margin-top: 5px;">
                        <span>入金済合計 (50% + 残金):</span> <strong>- <span id="disp_total_deposit"><?= number_format($total_deposit) ?></span> 円</strong>
                    </div>
                    <div style="font-size:11px; color:#666; margin-left: 10px; line-height:1.4;">
                        <div>・50%着手金: <?= $dep_50 > 0 ? number_format($dep_50).'円 ('.$dep_date_50.')' : '未入金' ?></div>
                        <div>・残金入金: <?= $dep_rem > 0 ? number_format($dep_rem).'円 ('.$dep_date_rem.')' : '未入金' ?></div>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:8px; border-top:1px solid #ccc; padding-top:8px; font-size:15px; font-weight:bold; color:#d32f2f;">
                        <span>請求残金 (最終精算残高):</span> <span><span id="disp_balance"><?= number_format($balance) ?></span> 円</span>
                    </div>
                </div>

                <!-- 協力業者との発注・承認・納品ステータス一覧 -->
                <div style="margin-top:10px; padding:8px; background:#f1f5f9; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:10px;">
                    <strong style="display:block; font-size:11px; color:#334155; margin-bottom:5px;">🤝 外注発注・納品ステータス一覧</strong>
                    <?php if (empty($orders)): ?>
                        <div style="font-size:10px; color:#666; text-align:center; padding:3px;">発注履歴はありません。</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <?php foreach ($orders as $o): 
                                $status_lbl = '不明';
                                $badge_bg = '#64748b';
                                if ($o['status'] === 'requested') { $status_lbl = '承諾待ち'; $badge_bg = '#f59e0b'; }
                                elseif ($o['status'] === 'accepted') { $status_lbl = '作業中'; $badge_bg = '#3b82f6'; }
                                elseif ($o['status'] === 'rejected') { $status_lbl = '辞退済'; $badge_bg = '#ef4444'; }
                                elseif ($o['status'] === 'delivered') { $status_lbl = '納品済(確認待ち)'; $badge_bg = '#10b981'; }
                                elseif ($o['status'] === 'completed') { $status_lbl = '完了(承認済)'; $badge_bg = '#059669'; }
                                elseif ($o['status'] === 'cancelled') { $status_lbl = 'キャンセル'; $badge_bg = '#94a3b8'; }
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; font-size:10px; border-bottom:1px dashed #e2e8f0; padding-bottom:3px;">
                                    <span>👷 <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?>: <?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?></span>
                                    <span class="badge" style="background:<?= $badge_bg ?>; color:white; font-size:9px; padding:2px 5px; border-radius:4px; font-weight:bold;"><?= $status_lbl ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 請求書発行エリア -->
                <?php if ($formal > 0): ?>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; margin-bottom: 10px;">
                        <button type="button" id="issue_primary_invoice_btn" onclick="issuePrimaryInvoice()" style="background:#2563eb; color:white; border:none; padding:8px 5px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:11px;">
                            <?= isset($files_by_cat['inv_primary'][0]) ? '一次請求書(50%)再発行' : '一次請求(50%)発行' ?>
                        </button>
                        <button type="button" id="issue_final_invoice_btn" onclick="issueFinalInvoice()" style="background:#dc3545; color:white; border:none; padding:8px 5px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:11px;">
                            <?= isset($files_by_cat['inv_final'][0]) ? '最終請求書 再発行' : '最終請求書(残金)発行' ?>
                        </button>
                    </div>
                    
                    <script>
                    function issuePrimaryInvoice() {
                        if (!confirm('本見積額の50%分（消費税加算前50%＋消費税）の一次請求書を発行しますか？\n（発行するとGoogle Driveへアップロードされ、クライアントチャットに自動通知されます）')) {
                            return;
                        }
                        const btn = document.getElementById('issue_primary_invoice_btn');
                        if (btn) {
                            btn.disabled = true;
                            btn.innerText = '発行中...';
                        }
                        const formData = new FormData();
                        formData.append('project_id', <?= (int)$project_id ?>);
                        
                        fetch('api_issue_primary_invoice.php', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(data => {
                                if (data.success && data.drive_file_id) {
                                    alert('一次請求書(50%)を発行しました。');
                                    window.open(`https://drive.google.com/file/d/${data.drive_file_id}/view?usp=drivesdk`, '_blank');
                                    location.reload();
                                } else {
                                    alert('一次請求書の発行に失敗しました: ' + (data.error || '不明なエラー'));
                                }
                            })
                            .catch(e => alert('通信エラー: ' + e))
                            .finally(() => {
                                if (btn) {
                                    btn.disabled = false;
                                    btn.innerText = '一次請求(50%)発行';
                                }
                            });
                    }

                    function issueFinalInvoice() {
                        if (!confirm('最終請求書（本見積額＋追加見積額 − 50%着手金入金済額）を発行しますか？\n（発行するとGoogle Driveへアップロードされ、クライアントチャットに自動通知されます）')) {
                            return;
                        }
                        const btn = document.getElementById('issue_final_invoice_btn');
                        if (btn) {
                            btn.disabled = true;
                            btn.innerText = '発行中...';
                        }
                        const formData = new FormData();
                        formData.append('project_id', <?= (int)$project_id ?>);
                        
                        fetch('api_issue_final_invoice.php', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(data => {
                                if (data.success && data.drive_file_id) {
                                    alert('最終請求書を発行しました。');
                                    window.open(`https://drive.google.com/file/d/${data.drive_file_id}/view?usp=drivesdk`, '_blank');
                                    location.reload();
                                } else {
                                    alert('最終請求書の発行に失敗しました: ' + (data.error || '不明なエラー'));
                                }
                            })
                            .catch(e => alert('通信エラー: ' + e))
                            .finally(() => {
                                if (btn) {
                                    btn.disabled = false;
                                    btn.innerText = '最終請求書(残金)発行';
                                }
                            });
                    }
                    </script>
                <?php endif; ?>

                <!-- 金銭データの更新フォーム -->
                <div style="border-top:1px dashed #ffeeba; padding-top:10px; margin-top:10px;">
                    <strong style="display:block; font-size:12px; color:#856404; margin-bottom:10px;">✏️ 金銭データ・追加見積の更新</strong>
                    <form method="POST" action="actions/admin_finance_post.php" style="font-size:12px;">
                        <input type="hidden" name="project_id" value="<?= $project_id ?>">
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                            <div>
                                <label style="display:block;margin-bottom:2px;">初期見積額 (円):</label>
                                <input type="number" name="initial_est_amount" value="<?= htmlspecialchars($initial) ?>" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:2px;">初期見積日:</label>
                                <input type="date" name="initial_est_date" value="<?= htmlspecialchars($initial_date) ?>" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:2px;">本見積額 (円):</label>
                                <input type="number" id="input_formal" name="formal_est_amount" value="<?= htmlspecialchars($formal) ?>" oninput="recalcFinance()" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:2px;">本見積日:</label>
                                <input type="date" name="formal_est_date" value="<?= htmlspecialchars($formal_date) ?>" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                        </div>

                        <!-- 複数追加見積入力エリア -->
                        <div style="background:#fffcf5; padding:8px; border:1px solid #fed7aa; border-radius:6px; margin-bottom:10px;">
                            <div style="display:flex; justify-content:between; align-items:center; margin-bottom:5px;">
                                <strong style="font-size:11px; color:#c2410c;">➕ 追加見積もり（複数登録可）</strong>
                                <button type="button" onclick="addEstimateRow()" style="background:#ea580c; color:white; border:none; padding:2px 8px; border-radius:4px; font-size:10px; cursor:pointer; font-weight:bold; margin-left:auto;">追加</button>
                            </div>
                            <div id="additional_estimates_container">
                                <?php foreach ($add_estimates as $idx => $ae): ?>
                                    <div class="add-est-row" style="display:flex; gap:5px; margin-bottom:5px; align-items:center;">
                                        <input type="number" name="add_est_amounts[]" value="<?= htmlspecialchars($ae['amount']) ?>" oninput="recalcFinance()" placeholder="金額" style="width:80px; padding:3px;" class="form-control-fin-add">
                                        <input type="date" name="add_est_dates[]" value="<?= htmlspecialchars($ae['date'] ?: '') ?>" style="flex:1; padding:3px;" class="form-control-fin-add-date">
                                        <button type="button" onclick="this.parentElement.remove(); recalcFinance();" style="background:#ef4444; color:white; border:none; padding:2px 5px; border-radius:3px; cursor:pointer;">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 入金情報入力エリア -->
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; background:#f0fdf4; padding:8px; border:1px solid #bbf7d0; border-radius:6px;">
                            <div style="grid-column:1 / -1; font-weight:bold; color:#166534; font-size:11px;">💵 入金履歴管理</div>
                            <div>
                                <label style="display:block;margin-bottom:2px; font-weight:bold;">50%着手金入金 (円):</label>
                                <input type="number" id="input_dep_50" name="deposit_amount_50" value="<?= htmlspecialchars($dep_50) ?>" oninput="recalcFinance()" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:2px;">50%入金日:</label>
                                <input type="date" name="deposit_date_50" value="<?= htmlspecialchars($dep_date_50) ?>" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:2px; font-weight:bold;">残金入金額 (円):</label>
                                <input type="number" id="input_dep_rem" name="deposit_amount_rem" value="<?= htmlspecialchars($dep_rem) ?>" oninput="recalcFinance()" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:2px;">残金入金日:</label>
                                <input type="date" name="deposit_date_rem" value="<?= htmlspecialchars($dep_date_rem) ?>" class="form-control-fin" style="width:100%; padding:3px; box-sizing:border-box;">
                            </div>
                        </div>

                        <!-- 協力業者支払状況入力エリア -->
                        <div style="background:#fffcf5; padding:8px; border:1px solid #fed7aa; border-radius:6px; margin-bottom:10px;">
                            <strong style="display:block; font-size:11px; color:#c2410c; margin-bottom:5px;">🤝 協力業者への支払状況管理</strong>
                            <?php if (empty($orders)): ?>
                                <div style="font-size:10px; color:#666; text-align:center; padding:5px;">発注履歴はありません。</div>
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; gap:5px;">
                                    <?php foreach ($orders as $o): 
                                        $pay_status = $o['payment_status'] ?: 'unpaid';
                                    ?>
                                        <div style="border-bottom:1px dashed #fed7aa; padding-bottom:5px; margin-bottom:5px; padding-top:2px;">
                                            <div style="display:flex; justify-content:space-between; font-size:10px; font-weight:bold; margin-bottom:2px;">
                                                <span>👷 <?= htmlspecialchars($o['contact_name'], ENT_QUOTES) ?> (<?= htmlspecialchars($o['task_title'], ENT_QUOTES) ?>)</span>
                                                <span style="color:#c2410c;"><?= number_format($o['order_amount']) ?>円</span>
                                            </div>
                                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 5px;">
                                                <div>
                                                    <label style="font-size:9px; display:block; margin-bottom:1px;">支払日:</label>
                                                    <input type="date" name="order_payment_dates[<?= $o['id'] ?>]" value="<?= htmlspecialchars($o['payment_date'] ?? '') ?>" style="width:100%; padding:2px; box-sizing:border-box; font-size:10px;">
                                                </div>
                                                <div>
                                                    <label style="font-size:9px; display:block; margin-bottom:1px;">状況:</label>
                                                    <select name="order_payment_statuses[<?= $o['id'] ?>]" style="width:100%; padding:2px; box-sizing:border-box; font-size:10px; font-weight:bold;">
                                                        <option value="unpaid" <?= $pay_status === 'unpaid' ? 'selected' : '' ?>>未払</option>
                                                        <option value="paid" <?= $pay_status === 'paid' ? 'selected' : '' ?>>支払済</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom:10px;">
                            <label style="display:block;margin-bottom:2px;">見積書・請求書 宛先名称:</label>
                            <input type="text" name="billing_company_name" value="<?= htmlspecialchars($project_info['billing_company_name'] ?? '') ?>" placeholder="空欄時は会社名＋担当者名" style="width:100%; padding:4px; box-sizing:border-box;">
                        </div>

                        <button type="submit" class="btn" style="width:100%; padding:6px; background:#28a745; font-weight:bold;">上記金銭データを保存</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            
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

            <?php if (isset($files_by_cat['inv_primary'][0])): ?>
            <div class="box" style="background:#eff6ff; border-color:#bfdbfe; margin-top:10px;">
                <h3 style="margin-top:0; font-size:14px; color:#1e40af; border-bottom:1px solid #bfdbfe; padding-bottom:5px;">📄 最新の一次請求書 (50%分)</h3>
                <div style="font-size:12px; color:#666; margin-bottom:10px;">発行された一次請求書（着手金50%）をPDFとして表示・印刷できます。</div>
                <a href="https://drive.google.com/file/d/<?= htmlspecialchars($files_by_cat['inv_primary'][0]['drive_file_id'], ENT_QUOTES) ?>/view?usp=drivesdk" target="_blank" style="display:block; width:100%; text-align:center; background:#2563eb; color:white; text-decoration:none; padding:8px; border-radius:4px; font-weight:bold;">
                    📄 一次請求書PDFを表示
                </a>
            </div>
            <?php endif; ?>

            <?php if (!($_SESSION['role'] === 'accountant')): ?>
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
                    // ===== 共通: 確認申請書（全依頼で必須・後出し可）=====
                    echo '<div style="font-size:11px; font-weight:bold; color:#d97706; margin-bottom:2px;">【確認申請書（全依頼共通・後出し可）】</div>';
                    echo '<div style="font-size:11px; margin-left:10px;">';
                    echo '・確認申請書: ' . (isset($files_by_cat['app_doc']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:#d97706;">⏳未提出（後出し可）</span>') . '<br>';
                    echo '</div>';

                    // ===== 許容応力度・壁量（共通図書）=====
                    if ($project_info['req_permit'] || $project_info['req_wall']) {
                        echo '<div style="font-size:11px; font-weight:bold; color:#374151; margin-top:5px; margin-bottom:2px;">【許容応力度・壁量計算 図書】</div>';
                        echo '<div style="font-size:11px; margin-left:10px;">';
                        $has_cad_any = isset($files_by_cat['cad_design_all']) || isset($files_by_cat['cad_layout']) || isset($files_by_cat['cad_plan_1f']) || isset($files_by_cat['cad_elevation']) || isset($files_by_cat['all_in_one_zip']);
                        echo '・意匠CADデータ: ' . ($has_cad_any ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">❌未提出（依頼時必須）</span>') . '<br>';
                        if ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                            echo '・地盤調査報告書: ' . (isset($files_by_cat['soil_report']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:#d97706;">⏳未提出（後出し可）</span>');
                        }
                        echo '</div>';
                    }
                    // ===== 天空率 =====
                    if ($project_info['req_sky']) {
                        echo '<div style="font-size:11px; font-weight:bold; color:#374151; margin-top:5px; margin-bottom:2px;">【天空率計算図書】</div>';
                        echo '<div style="font-size:11px; margin-left:10px;">';
                        echo '・道路の資料: ' . (isset($files_by_cat['road_data']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">❌未提出</span>') . '<br>';
                        echo '・真北の資料: ' . (isset($files_by_cat['true_north']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">❌未提出</span>');
                        echo '</div>';
                    }
                    // ===== 外皮計算 =====
                    if ($project_info['req_skin']) {
                        echo '<div style="font-size:11px; font-weight:bold; color:#374151; margin-top:5px; margin-bottom:2px;">【外皮計算図書】</div>';
                        echo '<div style="font-size:11px; margin-left:10px;">';
                        echo '・断熱材/サッシ仕様: ' . (isset($files_by_cat['insulation_spec']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">❌未提出</span>') . '<br>';
                        echo '・設備仕様書: ' . (isset($files_by_cat['equipment_spec']) ? '<span style="color:green;">✅提出済</span>' : '<span style="color:red;">❌未提出</span>');
                        echo '</div>';
                    }

                    // ===== 後出し図書の未提出警告（primary_prep中のみ）=====
                    if ($project_info['status'] === 'primary_prep') {
                        $pending = [];
                        if (!isset($files_by_cat['app_doc'])) $pending[] = '確認申請書';
                        if (($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1) && !isset($files_by_cat['soil_report'])) $pending[] = '地盤調査報告書';
                        if (!empty($pending)) {
                            $pending_str = implode('、', $pending);
                            echo '<div style="margin-top:10px; padding:8px; background:#fff8e1; border:1px solid #f59e0b; border-radius:4px; font-size:11px; color:#92400e;">';
                            echo '⚠️ <strong>一次回答期限の起算待ち</strong><br>';
                            echo '未提出図書: ' . $pending_str . '<br>';
                            echo '上記図書の提出が完了した時点を「一次回答期限」の起算日とします。';
                            echo '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
            <!-- ▲▲▲ 管理者用：必要図書ステータス確認パネル ▲▲▲ -->
            <?php endif; ?>
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

            <?php if ($is_admin && $project_info['status'] === 'contracted'): ?>
            <div class="box" style="background:#ecfdf5; border-color:#a7f3d0; margin-top:15px; padding:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#047857; border-bottom:1px solid #a7f3d0; padding-bottom:5px;">
                    🚀 設計開始のトリガー
                </h3>
                <div style="font-size:12px; color:#065f46; margin-bottom:12px; line-height:1.4;">
                    一次回答期日（<?= $project_info['primary_due_date'] ?>）に向けて、構造計算・設計を開始します。<br>
                    <strong>「設計（着手）を開始する」</strong> を押すと、ステータスが「構造図作成中」に進み、依頼主に着手が通知されます。
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST">
                    <input type="hidden" name="action" value="start_design">
                    <button type="submit" style="width:100%; background:#10b981; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;" onclick="return confirm('設計開始を確定し、着手をクライアントに通知します。よろしいですか？')">設計（着手）を開始する</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($is_admin && $project_info['status'] === 'structural_dwg'): ?>
            <div class="box" style="background:#e0f2fe; border-color:#bae6fd; margin-top:15px; padding:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#0369a1; border-bottom:1px solid #bae6fd; padding-bottom:5px;">
                    📢 一次回答 ＆ 50%請求書の発行
                </h3>
                <div style="font-size:12px; color:#0284c7; margin-bottom:12px; line-height:1.4;">
                    設計が完了した一次回答図書（計算書等）をアップロードし、本見積額を入力してください。<br>
                    <strong>「一次回答と50%請求書を発行する」</strong> を実行すると、一次回答ファイルが登録され、自動的に50%分の一次請求書が生成・通知されます。
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_primary_response">
                    
                    <div style="margin-bottom:10px;">
                        <label style="display:block; font-weight:bold; font-size:11px; margin-bottom:4px;">① 一次回答ファイル (計算書等・必須)</label>
                        <input type="file" name="primary_file" required style="font-size:11px; width:100%;">
                    </div>
                    
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; font-size:11px; margin-bottom:4px;">② 本見積額 (円・税込・必須)</label>
                        <input type="number" name="formal_est_amount" value="<?= htmlspecialchars($project_info['formal_est_amount'] ?: '') ?>" required placeholder="例: 110000" style="width:100%; padding:6px; font-size:12px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                    </div>
                    
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; font-size:11px; margin-bottom:4px;">③ 本見積日</label>
                        <input type="date" name="formal_est_date" value="<?= htmlspecialchars($project_info['formal_est_date'] ?: date('Y-m-d')) ?>" required style="width:100%; padding:6px; font-size:12px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                    </div>

                    <button type="submit" style="width:100%; background:#0284c7; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; cursor:pointer;" onclick="return confirm('一次回答ファイルをアップロードし、50%分の一次請求書を発行してクライアントへ通知します。よろしいですか？')">一次回答 ＆ 50%請求書を発行</button>
                </form>
            </div>
            <?php endif; ?>

                    </div>