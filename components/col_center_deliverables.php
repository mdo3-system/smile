<div class="column col-center" style="flex: none;">
            <h2 class="section-title" style="background:#8b5cf6;">📂 成果物（納品物）</h2>
            <div style="font-size:12px; color:#555; margin-bottom:15px;">
                常に最新版が表示されます。過去の履歴もここからダウンロード可能です。<br>
                <span style="color:#d97706; font-weight:bold;">※質疑事項などは右下のチャット欄から画像とともに送信できます。</span>
            </div>

            <?php
            // 案件ごとの依頼内容を取得
            $req_permit = $project_info['req_permit'] ?? 0;
            $req_wall = $project_info['req_wall'] ?? 0;
            $req_skin = $project_info['req_skin'] ?? 0;
            $req_sky = $project_info['req_sky'] ?? 0;
            $req_opt_kisohari = $project_info['req_opt_kisohari'] ?? 0;

            // 各種目別の成果物定義
            $artifact_sections = [];
            
            if ($active_tab === 'permit') {
                if ($req_permit == 1) {
                    $artifact_sections['許容応力度計算'] = [
                        'safety_cert' => '安全証明書',
                        'standard_dwg' => '構造標準図',
                        'structural_dwg' => '構造図',
                        'calc_doc' => '構造計算書'
                    ];
                }
                if ($req_opt_kisohari == 1) {
                    $artifact_sections['＋OP（基礎・横架材計算書）'] = [
                        'standard_dwg' => '構造標準図',
                        'structural_dwg' => '構造図',
                        'kiso_hari_calc_doc' => '基礎横架材計算書'
                    ];
                }
            } elseif ($active_tab === 'wall') {
                if ($req_wall == 1) {
                    $artifact_sections['壁量計算'] = [
                        'wall_spreadsheet' => '表計算ツール',
                        'wall_calc_doc' => '壁量計算書'
                    ];
                }
            } elseif ($active_tab === 'skin') {
                if ($req_skin == 1) {
                    $artifact_sections['外皮計算'] = [
                        'skin_calc_doc' => '外皮計算書',
                        'skin_web_prog' => 'WEBプログラム計算書',
                        'skin_doc' => '外皮計算資料'
                    ];
                }
            } elseif ($active_tab === 'sky') {
                if ($req_sky == 1) {
                    $artifact_sections['天空率'] = [
                        'sky_dwg' => '天空率図書'
                    ];
                }
            }
            
            // カスタム成果物の抽出
            $custom_cats = [];
            foreach ($artifacts_by_cat as $cat => $history) {
                if (strpos($cat, 'custom_deliverable_') === 0) {
                    $lbl = substr($cat, strlen('custom_deliverable_'));
                    $custom_cats[$cat] = $lbl;
                }
            }
            if (!empty($custom_cats)) {
                $artifact_sections['追加成果物'] = $custom_cats;
            }

            // その他の納品物 (すべてのタブで表示しておくか、代表タブにまとめるか)
            // いったんすべてのタブで表示
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
                                <div style="font-weight:bold; font-size:12px; color:#334155; margin-bottom:5px; display:flex; align-items:center; justify-content:space-between;">
                                    <span><?= $label ?></span>
                                    <?php if ($is_admin && strpos($cat, 'custom_deliverable_') === 0): ?>
                                        <button type="button" onclick="renameCustomDeliverable('<?= htmlspecialchars($cat, ENT_QUOTES) ?>', '<?= htmlspecialchars($label, ENT_QUOTES) ?>')" style="background:none; border:none; color:#3b82f6; cursor:pointer; font-size:10px; padding:0; display:inline-flex; align-items:center; gap:2px; font-weight:normal;" title="名称変更">🖊 編集</button>
                                    <?php endif; ?>
                                </div>
                                
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
                                        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                                        <input type="file" name="artifact_file" required style="font-size:10px; width:150px;">
                                        <button type="submit" style="font-size:10px; background:#3b82f6; color:white; border:none; padding:3px 8px; border-radius:3px; cursor:pointer;">UP</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($is_admin): ?>
                <div style="margin-top: 15px; text-align: center;">
                    <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="display:inline-block;" onsubmit="return promptCustomDeliverable(this);">
                        <input type="hidden" name="action" value="add_custom_deliverable">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                        <input type="hidden" name="custom_label" id="custom_deliverable_label" value="">
                        <button type="submit" class="btn" style="background:#8b5cf6; color:white; border:none; padding:8px 15px; border-radius:4px; font-weight:bold; cursor:pointer;">➕ 別の成果物スロットを追加</button>
                    </form>
                </div>
                <form id="renameCustomDeliverableForm" action="project_detail.php?id=<?= $project_id ?>" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="rename_custom_deliverable">
                    <input type="hidden" name="old_category" id="rename_old_category" value="">
                    <input type="hidden" name="new_label" id="rename_new_label" value="">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                </form>
                <script>
                function promptCustomDeliverable(form) {
                    const name = prompt("追加する成果物の名前を入力してください（例: 特記仕様書）:");
                    if (!name || name.trim() === "") return false;
                    document.getElementById('custom_deliverable_label').value = name.trim();
                    return true;
                }
                function renameCustomDeliverable(cat, currentLabel) {
                    const newName = prompt("成果物スロットの新しい名称を入力してください:", currentLabel);
                    if (newName === null) return;
                    const trimmed = newName.trim();
                    if (trimmed === "") {
                        alert("名前を入力してください。");
                        return;
                    }
                    if (trimmed === currentLabel) return;
                    document.getElementById('rename_old_category').value = cat;
                    document.getElementById('rename_new_label').value = trimmed;
                    document.getElementById('renameCustomDeliverableForm').submit();
                }
                </script>
            <?php endif; ?>
</div>
