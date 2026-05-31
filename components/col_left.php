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

            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">依頼主アップロード図書</h3>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $categories = [];
                    // 共通
                    $categories['cad_design_all'] = '意匠CADデータ一式';
                    $categories['pdf_plan'] = '平面図';
                    $categories['pdf_elevation'] = '立面図';
                    global $file_categories_left_pdf, $file_categories_left_cad;
                    $categories = array_merge($file_categories_left_pdf, $file_categories_left_cad);
                    $categories['all_in_one_zip'] = '一括ZIPファイル';

                    foreach ($categories as $cat => $label) {
                        if (isset($files_by_cat[$cat]) && is_array($files_by_cat[$cat])) {
                            echo "<div><strong style='color:#1e40af;'>{$label}:</strong><br>";
                            foreach ($files_by_cat[$cat] as $f) {
                                $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                    : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                                echo "<div style='margin-bottom:3px;'><a href='{$url}' target='_blank' class='file-link' style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:90%;'>📄 {$f['file_name']}</a></div>";
                            }
                            echo "</div>";
                        }
                    }
                    
                    // まだ何もアップロードされていない場合
                    $has_files = false;
                    foreach ($categories as $cat => $label) {
                        if (isset($files_by_cat[$cat])) $has_files = true;
                    }
                    if (!$has_files) {
                        echo "<div style='color:#999; font-size:12px;'>まだ図書はアップロードされていません。</div>";
                    }
                    ?>
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

            <?php if ($project_info['status'] === 'quote_req' || $project_info['status'] === 'primary_prep'): ?>
            <div class="box" style="background:#f8fafc; border-color:#e2e8f0; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">📋 提出が必要な図書</h3>
                <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; margin-bottom:15px;">
                    <?php
                    // 依頼内容に基づく必要図書の判定
                    $req_docs = [];
                    // 許容応力度の場合は意匠CAD必須（平面、立面、矩計、配置など。ここでは一括ZIPがあればOKとする判定も可能）
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1 || $project_info['req_skin'] == 1 || $project_info['req_sky'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $req_docs['cad_design_all'] = '意匠CAD一式 (または個別図面)';
                    }
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1) {
                        $req_docs['app_doc'] = '確認申請書（2〜5面）';
                        $req_docs['soil_report'] = '地盤調査資料';
                    }
                    // 地盤改良がある場合は追加
                    if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり') {
                        $req_docs['soil_impr'] = '地盤改良関連図書';
                    }

                    foreach ($req_docs as $key => $label) {
                        $is_submitted = false;
                        if (isset($files_by_cat[$key])) {
                            $is_submitted = true;
                        } else if ($key === 'cad_design_all') {
                            // 個別のCAD図面でもOKとする
                            if (isset($files_by_cat['cad_plan']) || isset($files_by_cat['cad_elevation']) || isset($files_by_cat['all_in_one_zip'])) {
                                $is_submitted = true;
                            }
                        }
                        
                        if ($is_submitted) {
                            echo "<div>✅ {$label} <span style='color:#10b981;'>(UP済)</span></div>";
                        } else {
                            echo "<div>❌ <span style='color:#ef4444; font-weight:bold;'>{$label}</span> <span style='color:#999;'>(未提出)</span></div>";
                        }
                    }
                    ?>
                </div>
                <button type="button" onclick="document.getElementById('designModal').classList.add('active')" style="width:100%; background:#3b82f6; color:white; border:none; padding:12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:14px; display:flex; justify-content:center; align-items:center; gap:8px; box-shadow:0 4px 6px rgba(59,130,246,0.3);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    設計依頼・図書アップロード
                </button>
            </div>
            <?php endif; ?>

            <!-- ▼▼▼ 進捗スケジュール可視化 ▼▼▼ -->
            <div class="box" style="background:#fff; border-color:#cbd5e1; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; color:#1e293b; border-bottom:1px solid #cbd5e1; padding-bottom:5px; display:flex; align-items:center; gap:5px;">
                    📅 申請図書UPまでのスケジュール
                </h3>
                <div style="font-size:12px; color:#dc2626; font-weight:bold; margin-bottom:10px; background:#fef2f2; border:1px solid #fecaca; padding:8px; border-radius:4px;">
                    ⚠️ 一次回答の期限は、設計に必要な図書が全て揃った（アップロード完了）時点で再設定（確定）されます。
                </div>
                
                <?php
                // 計算タイプ別の納期判定
                $req_permit = $project_info['req_permit'] ?? 0;
                $req_wall = $project_info['req_wall'] ?? 0;
                $req_skin = $project_info['req_skin'] ?? 0;
                $req_sky = $project_info['req_sky'] ?? 0;
                $req_opt_kisohari = $project_info['req_opt_kisohari'] ?? 0;

                $base_days = 12;
                if ($req_permit == 1 || $req_opt_kisohari == 1) {
                    $base_days = 12;
                } elseif ($req_wall == 1) {
                    $base_days = 7;
                } elseif ($req_skin == 1 || $req_sky == 1) {
                    $base_days = 10;
                }

                $primary_due_date = $project_info['primary_due_date'] ?? null;
                
                // スケジュール定義 (FIXED_LOGIC.md 準拠)
                $schedule_steps = [
                    ['name' => '設計図書の受領', 'actor' => 'client', 'desc' => '開始時', 'days' => 0, 'type' => 'base'],
                    ['name' => '着手基準日 (一次回答)', 'actor' => 'designer', 'desc' => "{$base_days}営業日程度", 'days' => $base_days, 'type' => 'biz'],
                    ['name' => '構造計算・図面 初回提示', 'actor' => 'designer', 'desc' => '着手から7〜10営業日', 'days' => 10, 'type' => 'biz'],
                    ['name' => '構造図CB (内容確認)', 'actor' => 'client', 'desc' => '初回提示から4営業日', 'days' => 4, 'type' => 'biz'],
                    ['name' => '修正図面UP', 'actor' => 'designer', 'desc' => 'CB確認から3営業日', 'days' => 3, 'type' => 'biz'],
                    ['name' => '申請図書一式UP', 'actor' => 'designer', 'desc' => '修正UPから3営業日', 'days' => 3, 'type' => 'biz'],
                    ['name' => '質疑・審査待機', 'actor' => 'wait', 'desc' => '確認機関の審査', 'days' => 30, 'type' => 'cal'],
                    ['name' => '補正対応', 'actor' => 'designer', 'desc' => '質疑受領から7営業日', 'days' => 7, 'type' => 'biz'],
                    ['name' => '残金のご精算', 'actor' => 'client', 'desc' => '完了後7日以内', 'days' => 7, 'type' => 'cal'],
                ];

                // 日付計算
                $current_date = $primary_due_date ? date('Y-m-d', strtotime("-{$base_days} weekdays", strtotime($primary_due_date))) : null; 
                // ※厳密な逆算は複雑なので、設定されている場合は primary_due_date を一次回答日に固定して以降を順次計算する
                if ($primary_due_date) {
                    $current_calc_date = $primary_due_date;
                }

                echo '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
                echo '<thead><tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;"><th style="padding:6px; text-align:left;">工程</th><th style="padding:6px; text-align:left;">担当</th><th style="padding:6px; text-align:left;">予定</th><th style="padding:6px; text-align:left;">実施日</th></tr></thead>';
                echo '<tbody>';
                
                $calc_date = $primary_due_date; // primary_due_date がある場合はこれを基準日として以降を計算
                $schedule_actuals = json_decode($project_info['schedule_actuals'] ?? '{}', true) ?: [];
                
                foreach ($schedule_steps as $idx => $step) {
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

                    // 実施日入力フォーム (管理者のみ、一次回答日設定後)
                    $actual_form = '';
                    if ($is_admin && $primary_due_date) {
                        $actual_form = '
                        <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:5px; align-items:center;">
                            <input type="hidden" name="action" value="update_schedule_actual">
                            <input type="hidden" name="step_idx" value="'.$idx.'">
                            <input type="date" name="actual_date" value="'.htmlspecialchars($actual_date, ENT_QUOTES).'" style="font-size:10px; padding:2px;">
                            <button type="submit" style="font-size:10px; padding:2px 5px; background:#e2e8f0; border:1px solid #cbd5e1; border-radius:3px; cursor:pointer;">保存</button>
                        </form>';
                    }

                    echo "<tr style='background:{$bg_color}; border-bottom:1px solid #e2e8f0;'>";
                    echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step['name']}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
                    echo "<td style='padding:6px;'>{$badge}</td>";
                    echo "<td style='padding:6px;'>{$date_str}</td>";
                    echo "<td style='padding:6px;'>{$actual_form}</td>";
                    echo "</tr>";
                }
                echo '</tbody></table>';
                ?>
            </div>
            <!-- ▲▲▲ 進捗スケジュール可視化 ▲▲▲ -->

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