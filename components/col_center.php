<div class="column col-center">
            <h2 class="section-title" style="background:#8b5cf6;">📂 成果物（納品物）</h2>
            <div style="font-size:12px; color:#555; margin-bottom:15px;">常に最新版が表示されます。過去の履歴もここからダウンロード可能です。</div>

            <?php
            // 各種目別の成果物定義
            $artifact_sections = [];
            
            if ($req_permit == 1 || $req_opt_kisohari == 1) {
                $artifact_sections['許容応力度計算'] = [
                    'structural_dwg' => '構造図',
                    'standard_dwg' => '構造標準図',
                    'calc_doc' => '構造計算書',
                    'safety_cert' => '安全証明書',
                    'inv_primary' => '一次回答',
                    'inv_primary_rev' => '修正一次回答'
                ];
            }
            if ($req_wall == 1) {
                $artifact_sections['性能表示壁量計算'] = [
                    'wall_calc_doc' => '壁量計算書',
                    'wall_kiso_dwg' => '基礎伏図',
                    'wall_perf_doc' => '性能評価用図書'
                ];
            }
            if ($req_skin == 1) {
                $artifact_sections['外皮計算'] = [
                    'skin_calc_doc' => '外皮計算書',
                    'skin_energy_doc' => '一次エネ計算書',
                    'skin_desc_doc' => '設計内容説明書'
                ];
            }
            if ($req_sky == 1) {
                $artifact_sections['天空率計算'] = [
                    'sky_calc_doc' => '天空率計算書',
                    'sky_dwg' => '天空率図面'
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
                </div>
            <?php endforeach; ?>
        </div>