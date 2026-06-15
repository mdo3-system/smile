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
                    <strong>宛先名:</strong> <?= htmlspecialchars(!empty($project_info['billing_company_name']) ? $project_info['billing_company_name'] : $project_info['company_name'], ENT_QUOTES) ?> 様<br>
                    <strong>お電話:</strong> <?= htmlspecialchars($project_info['client_phone'] ?: '未登録', ENT_QUOTES) ?><br>
                    <strong>地盤調査:</strong> <?= htmlspecialchars($project_info['soil_status'] ?? '未定', ENT_QUOTES) ?><br>
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

            <div class="box" style="margin-top:15px; background:#e8f5e9; border-color:#c8e6c9;">
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
                
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">📄 請求書</h3>
                <?php 
                $has_invoice = false;
                if (!empty($files_by_cat['inv_primary'])): 
                    $inv = $files_by_cat['inv_primary'][0];
                    $inv_url = (strpos($inv['drive_file_id'], 'uploads/') !== 0 && !empty($inv['drive_file_id'])) 
                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($inv['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                        : htmlspecialchars($inv['drive_file_id'], ENT_QUOTES);
                    $has_invoice = true;
                ?>
                    <div style="margin-bottom:10px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#1e40af; margin-bottom:5px;">一次請求書 (着手金50%分)</div>
                        <a href="<?= $inv_url ?>" target="_blank" style="display:inline-block; padding:6px 12px; background:#2563eb; color:white; font-size:12px; font-weight:bold; border-radius:4px; text-decoration:none;">
                            📄 一次請求書を表示
                        </a>
                    </div>
                <?php endif; ?>

                <?php 
                if (!empty($files_by_cat['inv_final'])): 
                    $inv_f = $files_by_cat['inv_final'][0];
                    $inv_f_url = (strpos($inv_f['drive_file_id'], 'uploads/') !== 0 && !empty($inv_f['drive_file_id'])) 
                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($inv_f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                        : htmlspecialchars($inv_f['drive_file_id'], ENT_QUOTES);
                    $has_invoice = true;
                ?>
                    <div style="margin-bottom:10px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#b91c1c; margin-bottom:5px;">最終ご請求書 (残金精算分)</div>
                        <a href="<?= $inv_f_url ?>" target="_blank" style="display:inline-block; padding:6px 12px; background:#dc3545; color:white; font-size:12px; font-weight:bold; border-radius:4px; text-decoration:none;">
                            📄 最終請求書を表示
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!$has_invoice): ?>
                    <div style="font-size:12px; color:#666; padding:10px; text-align:center; background:#fff; border-radius:4px;">請求書はまだ発行されていません。</div>
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

            <div class="box" style="margin-top:15px; background:#fff3cd; border-color:#ffeeba;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 ご請求・お支払い状況</h3>
                <div style="font-size:13px; line-height:1.8;">
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
                        $additional_deposits = json_decode($project_info['additional_deposits'] ?? '[]', true) ?: [];

                        // 合計追加費用
                        $total_add = 0;
                        foreach ($add_estimates as $ae) {
                            $total_add += intval($ae['amount']);
                        }

                        // 追加入金合計
                        $total_add_dep = 0;
                        foreach ($additional_deposits as $ad) {
                            $total_add_dep += intval($ad['amount']);
                        }

                        $total_req = $formal + $total_add;
                        $total_deposit = $dep_50 + $dep_rem + $total_add_dep;
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
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>初期お見積額 (<?= $initial_date ? htmlspecialchars($initial_date) : '-' ?>):</span> <strong><?= number_format($initial) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>本見積額 (<?= $formal_date ? htmlspecialchars($formal_date) : '-' ?>):</span> <strong><?= number_format($formal) ?> 円</strong>
                    </div>
                    
                    <!-- 追加見積一覧の表示 -->
                    <div style="margin-left: 10px; font-size:12px; color:#c0392b;">
                        <?php foreach ($add_estimates as $idx => $ae): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・追加見積 #<?= $idx+1 ?> (<?= htmlspecialchars($ae['date'] ?: '-') ?>):</span>
                                <strong>+ <?= number_format($ae['amount']) ?> 円</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px dashed #ccc; padding-top:5px;">
                        <span>合計ご請求額 (本見積＋追加):</span> <strong><?= number_format($total_req) ?> 円</strong>
                    </div>
                    <?php if ($formal > 0): ?>
                    <div style="display:flex; justify-content:space-between; color:#4a5568; margin-bottom: 2px;">
                        <span>└ 一次請求予定額 (50%):</span> <strong><?= number_format($primary_invoice_amount) ?> 円</strong>
                    </div>
                    <?php endif; ?>
                    
                    <div style="display:flex; justify-content:space-between; color:#28a745; margin-top: 5px;">
                        <span>入金済合計 (50% + 残金 + 追加):</span> <strong>- <?= number_format($total_deposit) ?> 円</strong>
                    </div>
                    
                    <!-- 各入金の明細表示 -->
                    <div style="margin-left: 10px; font-size:11px; color:#555; line-height: 1.4;">
                        <?php if ($dep_50 > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・着手金 (50%) 入金 (<?= htmlspecialchars($dep_date_50 ?: '-') ?>):</span>
                                <span><?= number_format($dep_50) ?> 円</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($dep_rem > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・残金入金 (<?= htmlspecialchars($dep_date_rem ?: '-') ?>):</span>
                                <span><?= number_format($dep_rem) ?> 円</span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($additional_deposits as $idx => $ad): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・追加入金 (<?= htmlspecialchars($ad['date'] ?: '-') ?><?php if(!empty($ad['note'])) echo ' - ' . htmlspecialchars($ad['note']); ?>):</span>
                                <span><?= number_format($ad['amount']) ?> 円</span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px solid #ccc; padding-top:5px; font-size:15px; font-weight:bold; color:#d32f2f;">
                        <span>最終ご請求額 (残金精算額):</span> <span><?= number_format($balance) ?> 円</span>
                    </div>
                </div>
            </div>

            <?php require __DIR__ . '/col_estimate_files.php'; ?>
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
                    
                    <div style="margin-bottom:15px; background:#fef2f2; border:1px solid #fecaca; padding:10px; border-radius:6px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:8px; color:#b91c1c;">【重要】見積時から図面の変更はありますか？</label>
                        <div style="display:flex; gap:15px; margin-bottom:10px;">
                            <label style="font-size:13px; cursor:pointer;"><input type="radio" name="drawing_changed" value="no" required onchange="document.getElementById('drawing_change_notes_area').style.display='none'; document.getElementById('drawing_change_notes').removeAttribute('required');"> 変更なし</label>
                            <label style="font-size:13px; cursor:pointer;"><input type="radio" name="drawing_changed" value="yes" required onchange="document.getElementById('drawing_change_notes_area').style.display='block'; document.getElementById('drawing_change_notes').setAttribute('required', 'required');"> 変更あり</label>
                        </div>
                        <div id="drawing_change_notes_area" style="display:none;">
                            <label style="display:block; font-size:11px; margin-bottom:5px; color:#555;">変更箇所を簡単にお知らせください</label>
                            <textarea id="drawing_change_notes" name="drawing_change_notes" rows="2" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 2階の窓の位置を変更、面積が1坪増えました 等"></textarea>
                        </div>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">その他補足事項・メッセージ</label>
                        <textarea name="client_notes_extra" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="よろしくお願いいたします。"></textarea>
                    </div>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('orderModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#0056b3; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;" onclick="this.innerHTML='送信中...';">送信して正式発注</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- カラム2：成果物一覧 ＋ スケジュール -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_center_deliverables.php'; ?>
            <?php require __DIR__ . '/col_center_post_uploads.php'; ?>

            <!-- スケジュールボックス（旧左カラムから移動） -->
            <?php
            $base_days = getScheduleBaseDays($project_info);
            $primary_due_date = $project_info['primary_due_date'] ?? null;

            $schedulesToRender = [];

            if (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '許容応力度・基礎横架材計算',
                    'steps' => getScheduleSteps($base_days),
                    'actuals_col' => 'schedule_actuals'
                ];
            }
            if (($project_info['req_wall'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '壁量計算',
                    'steps' => getScheduleStepsWall($base_days),
                    'actuals_col' => 'schedule_actuals_wall'
                ];
            }
            if (($project_info['req_skin'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '外皮計算',
                    'steps' => getScheduleStepsSkin($base_days),
                    'actuals_col' => 'schedule_actuals_skin'
                ];
            }
            if (($project_info['req_sky'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '天空率',
                    'steps' => getScheduleStepsSky($base_days),
                    'actuals_col' => 'schedule_actuals_sky'
                ];
            }

            if (empty($schedulesToRender)) {
                $schedulesToRender[] = [
                    'title' => '構造計算・基本スケジュール',
                    'steps' => getScheduleSteps($base_days),
                    'actuals_col' => 'schedule_actuals'
                ];
            }

            foreach ($schedulesToRender as $scheduleItem):
                $schedule_actuals = json_decode($project_info[$scheduleItem['actuals_col']] ?? '{}', true) ?: [];
            ?>
            <div class="box" style="background:#f0f8ff; border-color:#cce5ff;">
                <h3 style="margin-top:0; font-size:14px; color:#004085; border-bottom:1px solid #cce5ff; padding-bottom:5px;">📅 <?= htmlspecialchars($scheduleItem['title']) ?> スケジュール</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <?php
                    if (empty($primary_due_date)) {
                        echo '<div style="color:#e53e3e; font-size:12px; margin-bottom:10px; background:#fef2f2; border:1px solid #fecaca; padding:8px; border-radius:4px;">⏳ 具体的な日付は、設計依頼のご提出後に担当者が確認・設定します。</div>';
                    } else {
                        echo '<div style="color:#155724; font-size:12px; margin-bottom:10px; background:#d4edda; border:1px solid #c3e6cb; padding:8px; border-radius:4px;">✅ 一次回答期日：<strong>' . date('Y年m月d日', strtotime($primary_due_date)) . '</strong>（スケジュール確定済み）</div>';
                    }

                    echo '<table style="width:100%; border-collapse:collapse; font-size:11px; margin-bottom:10px;">';
                    echo '<thead><tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;"><th style="padding:6px; text-align:left;">工程</th><th style="padding:6px; text-align:left;">担当</th><th style="padding:6px; text-align:left;">予定日/実績日</th></tr></thead>';
                    echo '<tbody>';
                    
                    $calc_date = $primary_due_date;
                    
                    foreach ($scheduleItem['steps'] as $idx => $step) {
                        $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8fafc';
                        $badge = '';
                        if ($step['actor'] == 'designer') {
                            $badge = '<span style="background:#3b82f6; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟦 サポート</span>';
                        } elseif ($step['actor'] == 'client') {
                            $client_display_name = htmlspecialchars($project_info['client_name'], ENT_QUOTES) . '様';
                            $badge = '<span style="background:#10b981; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟩 ' . $client_display_name . '</span>';
                        } else {
                            $badge = '<span style="background:#64748b; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">⬛ 審査・待機</span>';
                        }

                        $date_str = '<span style="color:#64748b;">未確定</span>';
                        
                        if ($primary_due_date) {
                            if ($idx == 0) {
                                $date_str = '<span style="color:#64748b;">-</span>';
                            } elseif ($idx == 1) {
                                $calc_date = $primary_due_date;
                                $date_str = '<strong>' . date('m/d', strtotime($primary_due_date)) . '</strong>';
                            } else {
                                if ($step['type'] == 'biz') {
                                    $calc_date = addBusinessDays($calc_date, $step['days']);
                                } elseif ($step['type'] == 'cal') {
                                    $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                                }
                                $date_str = date('m/d', strtotime($calc_date));
                            }
                        }

                        // 実施日があればそれを起算日に上書きする
                        $actual_date = $schedule_actuals[$idx] ?? '';
                        if ($actual_date) {
                            $calc_date = $actual_date;
                            $date_str = '<span style="color:#10b981; font-weight:bold;">' . date('m/d', strtotime($actual_date)) . ' (済)</span>';
                        }

                        echo "<tr style='background:{$bg_color}; border-bottom:1px solid #e2e8f0;'>";
                        echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step['name']}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
                        echo "<td style='padding:6px;'>{$badge}</td>";
                        echo "<td style='padding:6px;'>{$date_str}</td>";
                        echo "</tr>";
                    }
                    echo '</tbody></table>';
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
            
        </div>

        <!-- 右パネル：チャット ＋ 依頼主アップロード図書 -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:350px;">
            <div class="column col-right" style="flex:1; margin:0; width:100%; box-sizing:border-box;">
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
                                <?php if (!$isMe): ?>
                                    <div class="chat-avatar <?= $avatarClass ?>"><?= $avatarIcon ?></div>
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
            <?php require __DIR__ . '/col_center_uploads.php'; ?>
            
            <?php require __DIR__ . '/col_specs.php'; ?>
        </div>
        
        <!-- ===== 基本情報編集モーダル ===== -->
        <div class="modal-overlay" id="editInfoModal">
            <div class="modal-box" style="max-width:550px;">
                <div class="modal-title">🏠 依頼主情報の登録・編集</div>
                <form method="POST" action="project_detail.php?id=<?= $project_id ?>">
                    <input type="hidden" name="action" value="update_client_info">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">案件名</label>
                        <input type="text" name="project_name" value="<?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">会社・事務所名</label>
                            <input type="text" name="company_name" value="<?= htmlspecialchars($project_info['company_name'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">会社・事務所名フリガナ</label>
                            <input type="text" name="company_kana" value="<?= htmlspecialchars($project_info['company_kana'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="全角カタカナ">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:100px 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">郵便番号</label>
                            <input type="text" name="zip_code" value="<?= htmlspecialchars($project_info['zip_code'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 123-4567">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">住所</label>
                            <input type="text" name="address" value="<?= htmlspecialchars($project_info['address'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="市区町村・番地・マンション名等">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">電話番号</label>
                            <input type="text" name="phone_number" value="<?= htmlspecialchars($project_info['client_phone'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 03-1234-5678">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">担当者名</label>
                            <input type="text" name="contact_name" value="<?= htmlspecialchars($project_info['client_name'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">担当者名フリガナ</label>
                            <input type="text" name="contact_kana" value="<?= htmlspecialchars($project_info['contact_kana'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="全角カタカナ">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">担当者携帯電話番号</label>
                            <input type="text" name="mobile_number" value="<?= htmlspecialchars($project_info['mobile_number'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 090-1234-5678">
                        </div>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">お見積書・ご請求書宛先名称</label>
                        <input type="text" name="billing_company_name" value="<?= htmlspecialchars($project_info['billing_company_name'] ?? '', ENT_QUOTES) ?>" placeholder="※変更がある場合のみ入力（空欄時は会社名＋担当者名）" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                    </div>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('editInfoModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#0056b3; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">保存</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- replaceModal removed -->
    </div>
</div>
