<div class="column col-center" style="flex: none;">
    <h2 class="section-title" style="background:#4f46e5;">📂 一次回答後のアップロード図書</h2>
    <div style="font-size:12px; color:#555; margin-bottom:15px;">
        追加資料や補正通知などがある場合は、以下からアップロード・差し替えしてください。<br>
        常に最新版が表示され、過去の履歴もプルダウンから確認可能です。
    </div>

    <?php
    $post_upload_sections = [
        '追加資料・補正通知など' => [
            'correction_notice' => '補正通知書',
            'other_extra' => 'その他追加資料'
        ]
    ];

    foreach ($post_upload_sections as $section_title => $categories):
    ?>
        <div class="box" style="margin-bottom:15px; background:#f8fafc; border:1px solid #cbd5e1;">
            <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #cbd5e1; padding-bottom:5px; color:#1e293b;"><?= $section_title ?></h3>
            
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($categories as $cat => $label): ?>
                    <?php $history = $files_by_cat[$cat] ?? []; ?>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; padding:8px;">
                        <div style="font-weight:bold; font-size:12px; color:#334155; margin-bottom:5px;"><?= $label ?></div>
                        
                        <?php if (!empty($history)): 
                            $latest = $history[0]; 
                            $url = (strpos($latest['drive_file_id'], 'uploads/') !== 0 && !empty($latest['drive_file_id'])) 
                                ? 'https://drive.google.com/file/d/' . htmlspecialchars($latest['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                                : htmlspecialchars($latest['drive_file_id'], ENT_QUOTES);
                        ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px;">
                                <?php if ($latest['file_name'] === '【他ファイルに記載】'): ?>
                                    <span style="background:#f59e0b; color:white; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:bold;">
                                        ✅ 提出済（他のCADファイルに記載）
                                    </span>
                                <?php else: ?>
                                    <a href="<?= $url ?>" target="_blank" class="file-link" style="background:#3b82f6; color:white; border-color:#2563eb;">
                                        📄 最新版 (V<?= $latest['version'] ?>)
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (count($history) > 1): ?>
                                    <select onchange="if(this.value) window.open(this.value, '_blank');" style="font-size:11px; padding:3px; max-width:140px;">
                                        <option value="">過去バージョン...</option>
                                        <?php foreach ($history as $idx => $h): 
                                            if ($idx === 0) continue; 
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
                                <?= htmlspecialchars($latest['file_name'], ENT_QUOTES) ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:11px; color:#ef4444;">未提出</div>
                        <?php endif; ?>

                        <!-- アップロードフォーム -->
                        <?php if (!$is_admin): ?>
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin-top:2px; display:flex; flex-direction:column; gap:2px; border-top:1px dashed #e2e8f0; padding-top:3px;">
                                <input type="hidden" name="file_category" value="<?= $cat ?>">
                                <input type="hidden" name="action_type" value="single_upload">
                                
                                <div style="display:flex; gap:3px; align-items:center;">
                                    <input type="file" name="upload_file" id="file_<?= $cat ?>" <?= empty($history) ? 'required' : '' ?> style="font-size:10px; flex:1; min-width:90px; padding:2px;">
                                    
                                    <?php if (empty($history)): ?>
                                    <label style="font-size:9px; display:flex; align-items:center; gap:2px; color:#d97706; white-space:nowrap; cursor:pointer;" title="他のCADファイルに記載がある場合">
                                        <input type="checkbox" name="included_in_other" value="1" onchange="document.getElementById('file_<?= $cat ?>').required = !this.checked; document.getElementById('file_<?= $cat ?>').disabled = this.checked;"> 
                                        別ﾌｧｲﾙ済
                                    </label>
                                    <?php endif; ?>

                                    <button type="submit" style="font-size:10px; background:#10b981; color:white; border:none; padding:3px 6px; border-radius:3px; cursor:pointer; white-space:nowrap;">UP/更新</button>
                                </div>
                                
                                <?php if (!empty($history)): ?>
                                    <input type="text" name="update_reason" placeholder="差し替え理由を入力して下さい" required style="font-size:10px; width:100%; padding:2px; border:1px solid #cbd5e1; border-radius:3px; box-sizing:border-box;">
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
