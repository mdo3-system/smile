<div class="column col-center" style="flex: 1;">
            <div class="box" style="border-top:2px solid #3b82f6;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ccc; padding-bottom:5px; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:14px;">依頼主アップロード図書</h3>
                    <?php if (!$is_admin && $project_info['status'] !== 'quote_req'): ?>
                        <button onclick="document.getElementById('replaceModal').classList.add('active')" style="background:#dc3545; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; font-weight:bold;">ファイルの追加・差し替え</button>
                    <?php endif; ?>
                </div>
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

                    $has_files = false;
                    foreach ($files_by_cat as $cat => $files) {
                        $label = $categories[$cat] ?? 'その他 (' . htmlspecialchars($cat) . ')';
                        if (is_array($files) && count($files) > 0) {
                            $has_files = true;
                            echo "<div style='margin-bottom:8px;'><strong style='color:#1e40af;'>{$label}:</strong><br>";
                            foreach ($files as $f) {
                                $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id'])) 
                                    ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                    : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                                echo "<div style='margin-bottom:3px;'><a href='{$url}' target='_blank' class='file-link' style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:90%;'>📄 {$f['file_name']}</a></div>";
                            }
                            echo "</div>";
                        }
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
                <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; margin-bottom:15px;">
                    <?php
                    // 依頼内容に基づく必要図書の判定
                    $req_docs = [];
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1 || $project_info['req_skin'] == 1 || $project_info['req_sky'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $req_docs['cad_layout'] = '配置図';
                        $req_docs['cad_plan_1f'] = '1F平面図';
                        $req_docs['cad_plan_2f'] = '2F平面図';
                        $req_docs['cad_elevation'] = '立面図';
                        $req_docs['cad_section'] = '矩計図';
                    }
                    if ($project_info['req_permit'] == 1 || $project_info['req_wall'] == 1) {
                        $req_docs['app_doc'] = '確認申請書（2〜5面）';
                        $req_docs['soil_report'] = '地盤調査資料';
                    }
                    if ($project_info['req_skin'] == 1) {
                        $req_docs['spec_doc'] = '仕様書';
                        $req_docs['insulation_data'] = '断熱材資料';
                        $req_docs['sash_data'] = 'サッシ・玄関ドア仕様';
                        $req_docs['ventilation_data'] = '24時間換気計算図書';
                        $req_docs['equip_data'] = '設備機器カタログ';
                    }
                    if ($project_info['req_sky'] == 1) {
                        $req_road = true;
                        $req_north = true;
                        if (isset($all_estimates) && !empty($all_estimates)) {
                            $latest_note = json_decode($all_estimates[0]['note'] ?? '[]', true) ?: [];
                            $has_road = false;
                            $has_north = false;
                            foreach ($latest_note as $item) {
                                if (isset($item['name'])) {
                                    if (strpos($item['name'], '天空率 道路斜線') !== false) $has_road = true;
                                    if (strpos($item['name'], '天空率 北側斜線') !== false) $has_north = true;
                                }
                            }
                            if ($has_road || $has_north) {
                                $req_road = $has_road;
                                $req_north = $has_north;
                            }
                        }
                        if ($req_road) $req_docs['road_data'] = '道路の資料';
                        if ($req_north) $req_docs['true_north'] = '真北の資料';
                    }
                    // 地盤改良がある場合は追加
                    if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり') {
                        $req_docs['soil_impr'] = '地盤改良関連図書';
                    }

                    foreach ($req_docs as $key => $label) {
                        $is_submitted = false;
                        if (isset($files_by_cat[$key])) {
                            $is_submitted = true;
                        } else if (strpos($key, 'cad_') === 0 && (isset($files_by_cat['cad_design_all']) || isset($files_by_cat['all_in_one_zip']))) {
                            // 一括ZIPまたは一式CADデータがアップされていれば、個別のCAD項目は提出済とみなす
                            $is_submitted = true;
                        }
                        
                        if ($is_submitted) {
                            echo "<div>✅ {$label} <span style='color:#10b981;'>(UP済)</span></div>";
                        } else {
                            echo "<div>❌ <span style='color:#ef4444; font-weight:bold;'>{$label}</span> <span style='color:#999;'>(未提出)</span></div>";
                        }
                    }
                    ?>
                </div>

            </div>
            <?php endif; ?>
</div>
