<div class="column col-center">
            <h2 class="section-title" style="background:#8b5cf6;">📂 成果物（納品物）</h2>
            <div style="font-size:12px; color:#555; margin-bottom:15px;">
                常に最新版が表示されます。過去の履歴もここからダウンロード可能です。<br>
                <span style="color:#d97706; font-weight:bold;">※質疑事項などは右下のチャット欄から画像とともに送信できます。</span>
            </div>

            <?php
            // 各種目別の成果物定義
            $artifact_sections = [];
            
            if ($req_permit == 1) {
                $artifact_sections['許容応力度計算'] = [
                    'safety_cert' => '安全証明書',
                    'standard_dwg' => '構造標準図',
                    'structural_dwg' => '構造図',
                    'calc_doc' => '構造計算書'
                ];
            }
            if ($req_wall == 1) {
                $artifact_sections['壁量計算'] = [
                    'wall_spreadsheet' => '表計算ツール',
                    'wall_calc_doc' => '壁量計算書'
                ];
            }
            if ($req_opt_kisohari == 1) {
                $artifact_sections['＋OP（基礎・横架材計算書）'] = [
                    'standard_dwg' => '構造標準図',
                    'structural_dwg' => '構造図',
                    'kiso_hari_calc_doc' => '基礎横架材計算書'
                ];
            }
            if ($req_skin == 1) {
                $artifact_sections['外皮計算'] = [
                    'skin_calc_doc' => '外皮計算書',
                    'skin_web_prog' => 'WEBプログラム計算書',
                    'skin_doc' => '外皮計算資料'
                ];
            }
            if ($req_sky == 1) {
                $artifact_sections['天空率'] = [
                    'sky_dwg' => '天空率図書'
                ];
            }
            // その他の納品物
            $artifact_sections['その他納品物'] = [
                'other_artifact' => 'その他ファイル'
            ];

            foreach ($artifact_sections as $section_title => $categories):
                // このセクション内のファイルが一つでもUPされているか、または管理者の場合は表示
                $show_section = $is_admin;
                if (!$is_admin) {
                    foreach ($categories as $cat => $label) {
                        if (!empty($artifacts_by_cat[$cat])) { $show_section = true; break; }
                    }
                }
                if (!$show_section) continue;
            ?>
                <div class="box" style="margin-bottom:15px; background:#f8fafc; border:1px solid #cbd5e1;">
                    <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #cbd5e1; padding-bottom:5px; color:#1e293b;"><?= $section_title ?></h3>
                    
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach ($categories as $cat => $label): ?>
                            <?php $history = $artifacts_by_cat[$cat] ?? []; ?>
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; padding:8px;">
                                <div style="font-weight:bold; font-size:12px; color:#334155; margin-bottom:5px;"><?= $label ?></div>
                                
                                <?php if (!empty($history)): 
                                    $latest = $history[0]; 
                                    $url = (strpos($latest['drive_file_id'], 'uploads/') !== 0 && !empty($latest['drive_file_id'])) 
                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($latest['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                        : htmlspecialchars($latest['drive_file_id'], ENT_QUOTES);
                                ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px;">
                                        <a href="<?= $url ?>" target="_blank" class="file-link" style="background:#10b981; color:white; border-color:#059669;">
                                            📄 最新版ダウンロード (V<?= $latest['version'] ?>)
                                        </a>
                                        
                                        <?php if (count($history) > 1): ?>
                                            <select onchange="if(this.value) window.open(this.value, '_blank');" style="font-size:11px; padding:3px; max-width:140px;">
                                                <option value="">過去バージョン...</option>
                                                <?php foreach ($history as $idx => $h): 
                                                    if ($idx === 0) continue; // 最新は除外
                                                    $h_url = (strpos($h['drive_file_id'], 'uploads/') !== 0 && !empty($h['drive_file_id'])) 
                                                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($h['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                                        : htmlspecialchars($h['drive_file_id'], ENT_QUOTES);
                                                    $dateStr = date('m/d H:i', strtotime($h['created_at']));
                                                ?>
                                                    <option value="<?= $h_url ?>">V<?= $h['version'] ?> (<?= $dateStr ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:10px; color:#64748b; margin-top:3px; word-break:break-all;">
                                        ファイル名: <?= htmlspecialchars($latest['file_name'], ENT_QUOTES) ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:11px; color:#94a3b8;">未提出</div>
                                <?php endif; ?>

                                <!-- 管理者用 アップロードフォーム -->
                                <?php if ($is_admin): ?>
                                    <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin-top:8px; display:flex; gap:5px; align-items:center; border-top:1px dashed #e2e8f0; padding-top:5px;">
                                        <input type="hidden" name="action" value="upload_artifact">
                                        <input type="hidden" name="file_category" value="<?= $cat ?>">
                                        <input type="file" name="artifact_file" required style="font-size:10px; width:150px;">
                                        <button type="submit" style="font-size:10px; background:#3b82f6; color:white; border:none; padding:3px 8px; border-radius:3px; cursor:pointer;">UP</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
            <?php endforeach; ?>

            <div class="box" style="margin-top:20px; border-top:2px solid #3b82f6;">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">依頼主アップロード図書</h3>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php
                    $base_categories = [
                        'cad_design_all' => '意匠CADデータ一式',
                        'pdf_plan' => '平面図',
                        'pdf_elevation' => '立面図'
                    ];
                    global $file_categories_left_pdf, $file_categories_left_cad;
                    $categories = array_merge($base_categories, $file_categories_left_pdf ?? [], $file_categories_left_cad ?? []);
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

            <?php if ($project_info['status'] === 'quote_req' || $project_info['status'] === 'primary_prep'): ?>
            <div class="box" style="background:#f8fafc; border-color:#e2e8f0; margin-top:15px;">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">📋 提出が必要な図書</h3>
                <div style="font-size:11px; color:#6b7280; margin-bottom:8px; line-height:1.6;">
                    <span style="color:#ef4444; font-weight:bold;">🔴 依頼時必須</span>：正式依頼前に提出が必要&nbsp;&nbsp;
                    <span style="color:#d97706; font-weight:bold;">🟡 後出し可</span>：依頼後の提出OK（揃った時点が一次回答の起算日）
                </div>
                <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; margin-bottom:15px;">
                    <?php
                    // 依頼内容に基づく必要図書の判定
                    $req_docs_required  = []; // 正式依頼時に必須
                    $req_docs_deferred  = []; // 後出し可（必須だが後でOK）

                    // CADデータは全依頼で正式依頼時に必須
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1 || $project_info['req_skin'] == 1 || $project_info['req_sky'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $req_docs_required['cad_design_all'] = '意匠CAD一式（JWW/DXF等）';
                    }

                    // 確認申請書は全依頼で必須（後出し可）
                    $req_docs_deferred['app_doc'] = '確認申請書（2〜5面）';

                    // 地盤調査報告書は許容応力度・基礎梁許容応力度のみ必須（後出し可）
                    if ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $req_docs_deferred['soil_report'] = '地盤調査報告書';
                    }

                    // 地盤改良がある場合は追加（後出し可）
                    if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり') {
                        $req_docs_deferred['soil_impr'] = '地盤改良関連図書';
                    }

                    // 「必須」グループの表示
                    if (!empty($req_docs_required)) {
                        echo '<div style="font-weight:bold; font-size:11px; color:#ef4444; margin-bottom:4px;">🔴 依頼時必須（正式依頼前に提出）</div>';
                        foreach ($req_docs_required as $key => $label) {
                            $is_submitted = false;
                            if (isset($files_by_cat[$key])) {
                                $is_submitted = true;
                            } elseif ($key === 'cad_design_all') {
                                if (isset($files_by_cat['cad_layout']) || isset($files_by_cat['cad_plan_1f']) || isset($files_by_cat['cad_elevation']) || isset($files_by_cat['all_in_one_zip'])) {
                                    $is_submitted = true;
                                }
                            }
                            if ($is_submitted) {
                                echo "<div style='margin-left:10px;'>✅ <span style='color:#10b981;'>{$label} (UP済)</span></div>";
                            } else {
                                echo "<div style='margin-left:10px;'>❌ <span style='color:#ef4444; font-weight:bold;'>{$label}</span> <span style='color:#999;'>(未提出)</span></div>";
                            }
                        }
                    }

                    // 「後出し可」グループの表示
                    if (!empty($req_docs_deferred)) {
                        echo '<div style="font-weight:bold; font-size:11px; color:#d97706; margin-top:8px; margin-bottom:4px;">🟡 後出し可（揃った時点が起算日）</div>';
                        foreach ($req_docs_deferred as $key => $label) {
                            $is_submitted = isset($files_by_cat[$key]);
                            if ($is_submitted) {
                                echo "<div style='margin-left:10px;'>✅ <span style='color:#10b981;'>{$label} (UP済)</span></div>";
                            } else {
                                echo "<div style='margin-left:10px;'>⏳ <span style='color:#d97706;'>{$label}</span> <span style='color:#999;'>(未提出)</span></div>";
                            }
                        }
                    }
                    ?>
                </div>

            </div>
            <?php endif; ?>
        </div>