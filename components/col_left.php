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
                    $categories['pdf_layout'] = '配置図';
                    $categories['pdf_section'] = '矩計図';
                    $categories['pdf_area_calc'] = '求積図';
                    $categories['app_doc'] = '確認申請書';
                    
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1) {
                        $categories['soil_report'] = '地盤調査報告書';
                        $categories['soil_improvement_spec'] = '地盤改良関連図書';
                        $categories['wall_spec'] = '耐力壁・筋交い仕様';
                        $categories['hardware_spec'] = '金物仕様';
                        $categories['wood_species_spec'] = 'プレカット図（構造材種）';
                    }
                    if ($project_info['req_skin'] == 1) {
                        $categories['insulation_spec'] = '断熱・サッシ仕様';
                        $categories['section_dwg_ins'] = '矩計図(断熱部位)';
                        $categories['equipment_spec'] = '設備仕様書';
                    }
                    if ($project_info['req_sky'] == 1) {
                        $categories['road_data'] = '道路資料';
                        $categories['true_north'] = '真北資料';
                    }
                    $categories['other_extra'] = 'その他添付資料';
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
                <?php 
                // messagesテーブルから過去の見積もりPDF履歴を取得
                $stmtEstHist = $pdo->prepare("SELECT file_path, created_at FROM messages WHERE project_id = :pid AND file_type = 'pdf' AND sender_id = 1 ORDER BY created_at DESC");
                $stmtEstHist->execute(['pid' => $project_id]);
                $estimate_history = $stmtEstHist->fetchAll();
                
                if (count($estimate_history) > 0): 
                    $latest = $estimate_history[0];
                ?>
                    <a href="https://drive.google.com/file/d/<?= htmlspecialchars($latest['file_path'], ENT_QUOTES) ?>/view?usp=drivesdk" target="_blank" style="display:block; text-align:center; background:#28a745; color:white; border:none; padding:8px; border-radius:4px; font-weight:bold; text-decoration:none; font-size:12px; cursor:pointer; line-height:2.2; margin-bottom:10px;">
                        📄 最新の見積書を開く（<?= date('m/d H:i', strtotime($latest['created_at'])) ?> 発行）
                    </a>
                    
                    <?php if (count($estimate_history) > 1): ?>
                        <div style="font-size:11px; margin-top:10px; border-top:1px dashed #c8e6c9; padding-top:5px;">
                            <strong>🕒 過去の履歴（再発行分）</strong>
                            <ul style="margin:5px 0 0 0; padding-left:20px; color:#555;">
                            <?php for ($i = 1; $i < count($estimate_history); $i++): $hist = $estimate_history[$i]; ?>
                                <li style="margin-bottom:3px;">
                                    <a href="https://drive.google.com/file/d/<?= htmlspecialchars($hist['file_path'], ENT_QUOTES) ?>/view?usp=drivesdk" target="_blank" style="color:#2e7d32; text-decoration:none;">
                                        📄 <?= date('Y/m/d H:i', strtotime($hist['created_at'])) ?> 発行分
                                    </a>
                                </li>
                            <?php endfor; ?>
                            </ul>
                        </div>
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
                
                // スケジュール定義
                $schedule_steps = [
                    ['name' => '設計図書の提出', 'actor' => 'client', 'desc' => '開始時', 'days' => 0, 'type' => 'base'],
                    ['name' => '標準一次回答', 'actor' => 'designer', 'desc' => "{$base_days}営業日", 'days' => $base_days, 'type' => 'biz'],
                    ['name' => 'CB & 50%ご入金', 'actor' => 'client', 'desc' => '一次回答から4日後', 'days' => 4, 'type' => 'cal'],
                    ['name' => 'CB対応 (設計側)', 'actor' => 'designer', 'desc' => 'CB受領から3営業日', 'days' => 3, 'type' => 'biz'],
                    ['name' => 'CB確認・返答', 'actor' => 'client', 'desc' => 'CB送付から4日後', 'days' => 4, 'type' => 'cal'],
                    ['name' => '構造図作図', 'actor' => 'designer', 'desc' => '決定から4営業日', 'days' => 4, 'type' => 'biz'],
                    ['name' => '構造図CB', 'actor' => 'client', 'desc' => '作図UPから2日後', 'days' => 2, 'type' => 'cal'],
                    ['name' => '構造図修正', 'actor' => 'designer', 'desc' => 'CB受領から4営業日', 'days' => 4, 'type' => 'biz'],
                    ['name' => '構造図CB(最終確認)', 'actor' => 'client', 'desc' => '修正UPから2日後', 'days' => 2, 'type' => 'cal'],
                    ['name' => '申請図書一式UP', 'actor' => 'designer', 'desc' => '確認から3営業日', 'days' => 3, 'type' => 'biz'],
                    ['name' => '補正通知', 'actor' => 'wait', 'desc' => '申請から1ヶ月程度', 'days' => 30, 'type' => 'cal'],
                    ['name' => '補正回答', 'actor' => 'designer', 'desc' => '通知受領から7営業日', 'days' => 7, 'type' => 'biz'],
                    ['name' => '構造審査完了', 'actor' => 'wait', 'desc' => '回答から7日程度', 'days' => 7, 'type' => 'cal'],
                    ['name' => '残金のご精算', 'actor' => 'client', 'desc' => '完了から7日以内', 'days' => 7, 'type' => 'cal'],
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

            <?php if ($project_info['status'] === 'quote_req' || $project_info['status'] === 'primary_prep'): ?>
            <!-- ▼▼▼ 依頼主 詳細仕様指定・図書アップロード（モーダル） ▼▼▼ -->
            <div id="designModal" class="modal-overlay">
                <div class="modal-box" style="max-width:800px; position:relative; background:#f8fafc;">
                    <button type="button" onclick="closeDesignModal()" style="position:absolute; right:15px; top:15px; background:none; border:none; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
                    <h3 class="modal-title" style="margin-top:0; font-size:16px; color:#0f172a; border-bottom:1px solid #cbd5e1; padding-bottom:5px;">
                        📤 設計開始依頼（必要図書の提出と詳細仕様の指定）
                    </h3>
                
                <?php
                $upload_mode = $project_info['upload_mode'] ?? 'individual';
                $wood_json = json_decode($project_info['wood_details'] ?? '{}', true) ?: [];
                $wall_json = json_decode($project_info['wall_details'] ?? '{}', true) ?: [];
                $hw_json = json_decode($project_info['hardware_details'] ?? '{}', true) ?: [];
                ?>
                
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data">
                    
                    <div style="margin-bottom:15px; background:#fff; padding:15px; border:2px solid #ef4444; border-radius:6px;">
                        <div style="font-size:13px; font-weight:bold; color:#b91c1c; margin-bottom:8px;">⚠️ 見積時からの図面変更の有無</div>
                        <div style="display:flex; gap:15px; font-size:12px; margin-bottom:10px;">
                            <label><input type="radio" name="drawing_changed" value="no"> 変更なし</label>
                            <label><input type="radio" name="drawing_changed" value="yes"> 変更あり</label>
                        </div>
                        <textarea name="drawing_change_notes" placeholder="変更ありの場合は、変更箇所を簡単にご記入ください。" style="width:100%; padding:8px; font-size:12px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;"></textarea>
                    </div>
                    
                    <div style="margin-bottom:15px;">
                        <label style="font-size:12px; font-weight:bold; color:#334155; display:block; margin-bottom:5px;">📂 ファイルの提出方法</label>
                        <div style="display:flex; gap:15px; font-size:12px;">
                            <label><input type="radio" name="upload_mode" value="combined" onchange="toggleUploadMode()" <?= $upload_mode === 'combined' ? 'checked' : '' ?>> 1つのファイル（ZIP等）にまとめてアップロードする</label>
                            <label><input type="radio" name="upload_mode" value="individual" onchange="toggleUploadMode()" <?= $upload_mode === 'individual' ? 'checked' : '' ?>> 個別のファイルに分けてアップロード・指定する</label>
                        </div>
                    </div>

                    <!-- 一括アップロードエリア -->
                    <div id="mode_combined" style="display: <?= $upload_mode === 'combined' ? 'block' : 'none' ?>; background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:15px;">
                        <div style="font-size:12px; font-weight:bold; margin-bottom:10px;">必要図書一括 (ZIP/PDF) <span style="color:#ef4444;">※CADデータ必須</span></div>
                        <input type="file" name="upload_files[all_in_one_zip]" style="font-size:12px; width:100%;">
                        <?php if(isset($files_by_cat['all_in_one_zip'])): ?>
                            <div style="font-size:11px; margin-top:5px;">✅ 提出済: <a href="https://drive.google.com/file/d/<?= htmlspecialchars($files_by_cat['all_in_one_zip']['drive_file_id'], ENT_QUOTES) ?>/view?usp=drivesdk" target="_blank"><?= htmlspecialchars($files_by_cat['all_in_one_zip']['file_name'], ENT_QUOTES) ?></a></div>
                        <?php endif; ?>
                    </div>

                    <!-- 個別アップロードエリア -->
                    <div id="mode_individual" style="display: <?= $upload_mode === 'individual' ? 'block' : 'none' ?>;">
                        
                        <!-- A. 共通図書 -->
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">A. 共通図書</div>
                            <div style="display:grid; gap:10px;">
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">意匠CADデータ (平面・立面・配置・矩計を含む) <span style="color:#ef4444;">※必須（複数選択可）</span></div>
                                    <input type="file" name="upload_files[cad_design_all][]" multiple style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['cad_design_all'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['cad_design_all']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">確認申請書 (2面〜5面) <span style="color:#666;">（複数選択可）</span></div>
                                    <input type="file" name="upload_files[app_doc][]" multiple style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['app_doc'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['app_doc']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">求積図 <span style="color:#666;">（複数選択可）</span></div>
                                    <input type="file" name="upload_files[pdf_area_calc][]" multiple style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['pdf_area_calc'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['pdf_area_calc']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- B. 構造計算 -->
                        <?php if ($req_permit == 1 || $req_wall == 1 || $req_opt_kisohari == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">B. 構造仕様・図書</div>
                            
                            <div style="display:grid; gap:10px;">
                                <div style="background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">地盤調査の状況</div>
                                    <div style="display:flex; gap:15px; font-size:11px; margin-bottom:10px;">
                                        <label><input type="radio" name="soil_status" value="調査済" <?= ($project_info['soil_status']??'')==='調査済' ? 'checked' : '' ?>> 調査済</label>
                                        <label><input type="radio" name="soil_status" value="未調査+令96条但し書" <?= ($project_info['soil_status']??'')==='未調査+令96条但し書' ? 'checked' : '' ?>> 未調査+令96条但し書</label>
                                        <label><input type="radio" name="soil_status" value="調査予定" <?= ($project_info['soil_status']??'')==='調査予定' ? 'checked' : '' ?>> 調査予定</label>
                                    </div>
                                    
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">地盤調査報告書 / 改良関連図書</div>
                                    <div style="font-size:10px; color:#ef4444; margin-bottom:5px;">※新しくアップロードすると、過去にアップロードした同種の図書は上書き(非表示)されます。</div>
                                    <div style="display:grid; gap:5px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span style="font-size:11px; width:70px;">調査報告書:</span>
                                            <input type="file" name="upload_files[soil_report]" style="font-size:11px; flex:1;">
                                        </div>
                                        <div id="soil_imp_container" style="display:flex; flex-direction:column; gap:5px;">
                                            <div style="display:flex; align-items:center; gap:5px;">
                                                <span style="font-size:11px; width:70px;">改良関連図書:</span>
                                                <input type="file" name="upload_files[soil_improvement_spec][]" style="font-size:11px; flex:1;" title="改良設計書/計算書/認定書など">
                                                <button type="button" onclick="addSoilRow()" style="font-size:11px; padding:2px 5px; cursor:pointer;">＋追加</button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top:10px; font-size:10px;">
                                    <?php 
                                        if(isset($files_by_cat['soil_report'])) echo '<div style="color:#16a34a;">✅ 調査報告書: '.htmlspecialchars($files_by_cat['soil_report']['file_name']).'</div>'; 
                                        // 複数あるかもしれない改良設計書（現在は最新のみ表示する設計だが、履歴も含め複数あれば表示）
                                        // TODO: 厳密には $files_by_cat はカテゴリごと1つしか持っていない場合がある。複数対応は別途考慮。
                                        if(isset($files_by_cat['soil_improvement_spec'])) echo '<div style="color:#16a34a;">✅ 改良関連図書: '.htmlspecialchars($files_by_cat['soil_improvement_spec']['file_name']).'</div>';
                                    ?>
                                    </div>
                                </div>
                                
                                <div style="background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">耐力壁・筋交い仕様指定</div>
                                    <div style="display:grid; gap:5px; font-size:11px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>面材:</span>
                                            <select name="wall_menzai_type" style="padding:2px;">
                                                <?php renderOptions(['構造用合板', 'OSB', 'MDF', 'パーティクルボード', 'その他'], $wall_json['menzai']['type'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="wall_menzai_other" placeholder="その他の場合" value="<?= htmlspecialchars($wall_json['menzai']['other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>筋交い:</span>
                                            <select name="wall_sujikai_type" style="padding:2px;">
                                                <?php renderOptions(['30×45', '45×90', '90×90', 'その他'], $wall_json['sujikai']['type'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="wall_sujikai_other" placeholder="その他の場合" value="<?= htmlspecialchars($wall_json['sujikai']['other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                    </div>
                                    <div style="margin-top:5px; font-size:11px;">ファイル添付: <input type="file" name="upload_files[wall_spec]" style="font-size:10px;"></div>
                                    <?php if(isset($files_by_cat['wall_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['wall_spec']['file_name']).'</div>'; ?>
                                </div>
                                
                                <div style="background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
                                    <div style="font-size:11px; font-weight:bold; margin-bottom:5px;">金物仕様指定</div>
                                    <div style="display:grid; gap:5px; font-size:11px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>金物仕様:</span>
                                            <select name="hw_type" style="padding:2px;">
                                                <?php renderOptions(['Z金物', 'その他'], $hw_json['type'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="hw_type_other" placeholder="その他の場合" value="<?= htmlspecialchars($hw_json['type_other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span>金物工法:</span>
                                            <select name="hw_method" style="padding:2px;">
                                                <?php renderOptions(['Tec-One', 'プレセッター', 'Stroog', 'その他'], $hw_json['method'] ?? ''); ?>
                                            </select>
                                            <input type="text" name="hw_method_other" placeholder="その他の場合" value="<?= htmlspecialchars($hw_json['method_other'] ?? '', ENT_QUOTES) ?>" style="padding:2px; flex:1;">
                                        </div>
                                    </div>
                                    <div style="margin-top:5px; font-size:11px;">ファイル添付: <input type="file" name="upload_files[hardware_spec]" style="font-size:10px;"></div>
                                    <?php if(isset($files_by_cat['hardware_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['hardware_spec']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- C. 構造材種 -->
                        <?php if ($req_permit == 1 || $req_opt_kisohari == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">C. 構造材種</div>
                            
                            <div style="font-size:11px; margin-bottom:10px;">プレカット図等による指定: <input type="file" name="upload_files[wood_species_spec]" style="font-size:10px;"></div>
                            <?php if(isset($files_by_cat['wood_species_spec'])) echo '<div style="font-size:10px; color:#16a34a; margin-bottom:10px;">✅ '.htmlspecialchars($files_by_cat['wood_species_spec']['file_name']).'</div>'; ?>
                            
                            <table style="width:100%; font-size:11px; border-collapse:collapse; border:1px solid #e2e8f0;">
                                <tr style="background:#f1f5f9;"><th style="border:1px solid #e2e8f0; padding:4px;">部位</th><th style="border:1px solid #e2e8f0; padding:4px;">材種</th><th style="border:1px solid #e2e8f0; padding:4px;">サイズ/その他</th></tr>
                                
                                <?php 
                                    $wood_opts_std = ['スギKD', 'ヒノキKD', 'ベイマツKD', 'ベイツガKD', 'WWKD', 'E65-F255', 'E95-F315', 'E105-F300', 'E135-F375', 'その他'];
                                    $size_opts_105_120 = ['□105', '□120', 'その他'];
                                    $size_opts_90_105 = ['□90', '□105', 'その他'];
                                    
                                    function renderWoodRow($name, $key, $wood_json, $wood_opts, $size_opts) {
                                        echo '<tr>';
                                        echo '<td style="border:1px solid #e2e8f0; padding:4px; font-weight:bold;">'.$name.'</td>';
                                        echo '<td style="border:1px solid #e2e8f0; padding:4px;">';
                                        echo '<select name="wood_'.$key.'_type" style="width:100%; padding:2px; font-size:10px;">';
                                        renderOptions($wood_opts, $wood_json[$key]['type'] ?? '');
                                        echo '</select></td>';
                                        
                                        echo '<td style="border:1px solid #e2e8f0; padding:4px; display:flex; gap:2px;">';
                                        if ($key === 'taruki') {
                                            echo 'W <input type="number" name="wood_'.$key.'_w" value="'.htmlspecialchars($wood_json[$key]['w'] ?? '', ENT_QUOTES).'" style="width:30px; font-size:10px;"> × ';
                                            echo 'H <input type="number" name="wood_'.$key.'_h" value="'.htmlspecialchars($wood_json[$key]['h'] ?? '', ENT_QUOTES).'" style="width:30px; font-size:10px;">';
                                        } else {
                                            echo '<select name="wood_'.$key.'_size" style="width:60px; padding:2px; font-size:10px;">';
                                            renderOptions($size_opts, $wood_json[$key]['size'] ?? '');
                                            echo '</select>';
                                        }
                                        echo '<input type="text" name="wood_'.$key.'_other" placeholder="その他" value="'.htmlspecialchars($wood_json[$key]['other'] ?? '', ENT_QUOTES).'" style="flex:1; padding:2px; font-size:10px;">';
                                        echo '</td></tr>';
                                    }
                                    
                                    renderWoodRow('土台', 'foundation', $wood_json, $wood_opts_std, $size_opts_105_120);
                                    renderWoodRow('柱', 'column', $wood_json, $wood_opts_std, $size_opts_105_120);
                                    renderWoodRow('梁', 'beam', $wood_json, $wood_opts_std, $size_opts_105_120);
                                    renderWoodRow('大引', 'obiki', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('小屋束', 'koyatsuka', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('母屋', 'moya', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('棟木', 'munagi', $wood_json, $wood_opts_std, $size_opts_90_105);
                                    renderWoodRow('垂木', 'taruki', $wood_json, $wood_opts_std, []);
                                    renderWoodRow('火打', 'hiuchi', $wood_json, ['スギKD', 'ベイマツKD', 'Z金物', 'その他'], ['その他']);
                                ?>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- D. 天空率 -->
                        <?php if ($req_sky == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">D. 天空率図書</div>
                            <div style="display:grid; gap:10px;">
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">道路の資料 (座標、測量図、道路台帳、高さ等)</div>
                                    <input type="file" name="upload_files[road_data]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['road_data'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['road_data']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">真北の資料</div>
                                    <input type="file" name="upload_files[true_north]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['true_north'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['true_north']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- E. 外皮計算 -->
                        <?php if ($req_skin == 1): ?>
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">E. 外皮計算図書</div>
                            <div style="display:grid; gap:10px;">
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">断熱材・サッシ・ガラス仕様指定</div>
                                    <input type="file" name="upload_files[insulation_spec]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['insulation_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['insulation_spec']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">矩計図（使用断熱材の部位記載あり）</div>
                                    <input type="file" name="upload_files[section_dwg_ins]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['section_dwg_ins'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['section_dwg_ins']['file_name']).'</div>'; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; font-weight:bold;">設備仕様書（換気・エアコン・給湯器・照明等）</div>
                                    <input type="file" name="upload_files[equipment_spec]" style="font-size:11px; width:100%;">
                                    <?php if(isset($files_by_cat['equipment_spec'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['equipment_spec']['file_name']).'</div>'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- F. その他欄 -->
                        <div style="background:#fff; padding:15px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px;">
                            <div style="font-size:13px; font-weight:bold; color:#1e40af; border-bottom:1px solid #bfdbfe; margin-bottom:10px; padding-bottom:3px;">F. その他欄</div>
                            <textarea name="client_notes_extra" rows="3" style="width:100%; font-size:11px; padding:5px; border:1px solid #ccc; border-radius:4px; margin-bottom:5px;"><?= htmlspecialchars($project_info['client_notes_extra'] ?? '', ENT_QUOTES) ?></textarea>
                            <input type="file" name="upload_files[other_extra]" style="font-size:11px; width:100%;">
                            <?php if(isset($files_by_cat['other_extra'])) echo '<div style="font-size:10px; color:#16a34a;">✅ '.htmlspecialchars($files_by_cat['other_extra']['file_name']).'</div>'; ?>
                        </div>

                    </div>

                    <input type="hidden" name="action" id="form_action" value="">
                    
                    <div style="display:flex; gap:10px; margin-top:20px;">
                        <button type="submit" onclick="document.getElementById('form_action').value='save_client_specs_draft';" style="width:100%; background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:white; border:none; padding:14px; border-radius:8px; font-size:14px; font-weight:bold; cursor:pointer; box-shadow:0 4px 15px rgba(59,130,246,0.3);">
                            💾 図書・仕様を保存 / アップロードする
                        </button>
                    </div>
                </form>

                <script>
                    function toggleUploadMode() {
                        const isCombined = document.querySelector('input[name="upload_mode"][value="combined"]').checked;
                        document.getElementById('mode_combined').style.display = isCombined ? 'block' : 'none';
                        document.getElementById('mode_individual').style.display = isCombined ? 'none' : 'block';
                    }
                    function openDesignModal() {
                        document.getElementById('designModal').classList.add('active');
                    }
                    function closeDesignModal() {
                        document.getElementById('designModal').classList.remove('active');
                    }
                    function addSoilRow() {
                        const container = document.getElementById('soil_imp_container');
                        const div = document.createElement('div');
                        div.style.display = 'flex';
                        div.style.alignItems = 'center';
                        div.style.gap = '5px';
                        div.innerHTML = '<span style="font-size:11px; width:70px;">(追加分):</span><input type="file" name="upload_files[soil_improvement_spec][]" style="font-size:11px; flex:1;" title="改良設計書/計算書/認定書など"><button type="button" onclick="this.parentElement.remove()" style="font-size:11px; padding:2px 5px; cursor:pointer;">削除</button>';
                        container.appendChild(div);
                    }
                </script>
                </div>
            </div>
            <!-- ▲▲▲ 依頼主 詳細仕様指定・図書アップロード（モーダル） ▲▲▲ -->
            <?php endif; ?>

            <?php if ($is_admin): ?>
            <!-- 管理者専用：協力業者への発注 -->
            <h2 class="section-title" style="background:#e67e22;">🤝 協力業者への発注・タスク管理</h2>
            <div class="box" style="background:#fff9f0;">
                <div style="font-size:11px; margin-bottom:5px;"><strong>自動発注額算出</strong></div>
                <div style="display:flex; gap:5px;">
                    <input type="number" id="sub_area" placeholder="面積(㎡)" style="width:60px; font-size:12px;">
                    <button type="button" onclick="calcSubcontractorEstimate()" style="font-size:11px; padding:2px 5px;">算出</button>
                </div>
                <div id="sub_calc_result" style="margin-bottom:10px;"></div>
                <script>
                function calcSubcontractorEstimate() {
                    const area = parseFloat(document.getElementById('sub_area').value) || 0;
                    if (area <= 0) return;
                    const total = 30000 + Math.round(area * 500);
                    document.getElementById('sub_calc_result').innerHTML = 
                        '<span style="color:#28a745;font-size:12px;font-weight:bold;">推奨発注額: ' + total.toLocaleString() + '円</span>';
                    document.querySelector('input[name="order_amount"]').value = total;
                }
                </script>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin-top:10px;">
                    <input type="hidden" name="action" value="order_subcontractor">
                    <select name="subcontractor_id" style="width:100%; margin-bottom:5px; font-size:12px;">
                        <?php foreach($subcontractors as $sub): ?>
                            <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['contact_name'], ENT_QUOTES) ?> 様</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="task_title" placeholder="依頼内容（例：構造図作図）" style="width:100%; margin-bottom:5px; font-size:12px;">
                    <input type="number" name="order_amount" placeholder="金額(税込)" style="width:100%; margin-bottom:5px; font-size:12px;">
                    <button type="submit" style="width:100%; background:#e67e22; color:white; border:none; padding:5px; font-size:12px; cursor:pointer; border-radius:3px;">発注を確定・送信</button>
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

            <!-- 協力業者ダッシュボードへの切り替えリンク -->
            <div style="margin-top:15px; padding:10px; background:#e8f0fe; border:1px solid #93c5fd; border-radius:6px; text-align:center;">
                <div style="font-size:11px; color:#555; margin-bottom:8px;">この案件を協力業者視点で確認する</div>
                <a href="project_subcontractor.php?id=<?= $project_id ?>" target="_blank" style="display:inline-block; background:#3b82f6; color:white; padding:7px 15px; border-radius:4px; text-decoration:none; font-size:12px; font-weight:bold;">👷 協力業者ダッシュボードで見る</a>
            </div>
            <?php endif; ?>
        </div>