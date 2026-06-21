<div class="column col-center" style="flex: none;">
    <h2 class="section-title" style="background:#3b82f6;">📂 依頼主アップロード図書</h2>
    <div style="font-size:12px; color:#555; margin-bottom:15px;">
        必要な図書を以下からアップロード・差し替えしてください。<br>
        常に最新版が表示され、過去の履歴もプルダウンから確認可能です。
    </div>

    <?php
    // 依頼内容に基づく必要図書の判定 (グループ分け)
    $upload_sections = [];

    // 1. 共通図書
    $default_common_docs = [
        'cad_layout' => '配置図 ※正式依頼時必須',
        'cad_plan_1f' => '1F平面図 ※正式依頼時必須',
        'cad_plan_2f' => '2F平面図 ※正式依頼時必須',
        'cad_elevation' => '立面図 ※正式依頼時必須',
        'cad_section' => '矩計図 ※正式依頼時必須',
    ];
    $common_docs = [];
    foreach ($default_common_docs as $k => $v) {
        $common_docs[$k] = $v;
    }
    // カスタム図書（'custom_'で始まるカテゴリ）を抽出して 矩計図 の下に追加
    if (isset($files_by_cat) && is_array($files_by_cat)) {
        foreach ($files_by_cat as $cat => $files) {
            if (strpos($cat, 'custom_') === 0) {
                // 管理者（$is_admin）の場合はすべてのカスタムスロットを共通図書枠に表示（常時公開トグルを操作できるようにするため）。
                // 依頼主の場合は、専門図書以外のカスタムスロットのみを表示。
                if ($is_admin || (
                    strpos($cat, 'custom_skin_') !== 0 && 
                    strpos($cat, 'custom_sky_') !== 0 && 
                    strpos($cat, 'custom_wall_') !== 0 &&
                    strpos($cat, 'custom_permit_') !== 0 &&
                    strpos($cat, 'custom_soil_') !== 0 &&
                    strpos($cat, 'custom_precut_') !== 0
                )) {
                    $parts = explode('_', $cat);
                    $label = end($parts);
                    $common_docs[$cat] = $label;
                }
            }
        }
    }
    // 最後に確認申請書を追加
    $common_docs['app_doc'] = '確認申請書（2〜5面）🟡後出し可';
    $upload_sections['共通図書'] = $common_docs;

    // 2. 専門図書
    $specialized_docs = [];

    if ($active_tab === 'permit') {
        if (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1) {
            $specialized_docs['soil_report'] = '地盤調査報告書 🟡後出し可';
        }
        $specialized_docs['pdf_precut'] = 'プレカット図等';
        if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり') {
            $specialized_docs['soil_impr'] = '地盤改良関連図書 🟡後出し可';
        }
        // 許容応力度・地盤・プレカット用のカスタム図書を抽出
        if (isset($files_by_cat) && is_array($files_by_cat)) {
            foreach ($files_by_cat as $cat => $files) {
                if (strpos($cat, 'custom_permit_') === 0) {
                    $parts = explode('_', $cat);
                    $label = end($parts);
                    $specialized_docs[$cat] = $label;
                }
                if (strpos($cat, 'custom_soil_') === 0) {
                    $parts = explode('_', $cat);
                    $label = end($parts);
                    $specialized_docs[$cat] = "地盤関連: " . $label;
                }
                if (strpos($cat, 'custom_precut_') === 0) {
                    $parts = explode('_', $cat);
                    $label = end($parts);
                    $specialized_docs[$cat] = "ﾌﾟﾚｶｯﾄ関連: " . $label;
                }
            }
        }
    } elseif ($active_tab === 'wall') {
        $specialized_docs['pdf_precut'] = 'プレカット図等';
        // 壁量計算用カスタム図書を抽出
        if (isset($files_by_cat) && is_array($files_by_cat)) {
            foreach ($files_by_cat as $cat => $files) {
                if (strpos($cat, 'custom_wall_') === 0) {
                    $label = substr($cat, 12);
                    $specialized_docs[$cat] = $label;
                }
            }
        }
    } elseif ($active_tab === 'skin') {
        $specialized_docs = [
            'spec_doc' => '仕様書',
            'insulation_data' => '断熱材資料',
            'sash_data' => 'サッシ・玄関ドア仕様',
            'ventilation_data' => '24時間換気計算図書',
            'equip_data' => '設備機器カタログ'
        ];
        // 外皮計算用カスタム図書を抽出
        if (isset($files_by_cat) && is_array($files_by_cat)) {
            foreach ($files_by_cat as $cat => $files) {
                if (strpos($cat, 'custom_skin_') === 0) {
                    $label = substr($cat, 12);
                    $specialized_docs[$cat] = $label;
                }
            }
        }
    } elseif ($active_tab === 'sky') {
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
        if ($req_road) $specialized_docs['road_data'] = '道路の資料';
        if ($req_north) $specialized_docs['true_north'] = '真北の資料';
        // 天空率用カスタム図書を抽出
        if (isset($files_by_cat) && is_array($files_by_cat)) {
            foreach ($files_by_cat as $cat => $files) {
                if (strpos($cat, 'custom_sky_') === 0) {
                    $label = substr($cat, 11);
                    $specialized_docs[$cat] = $label;
                }
            }
        }
    }

    if (!empty($specialized_docs)) {
        $upload_sections['専門図書'] = $specialized_docs;
    }

    $section_idx = 0;
    foreach ($upload_sections as $section_title => $categories):
        $section_id = 'bulk_modal_' . $section_idx;
    ?>
        <div class="box" style="margin-bottom:15px; background:#f8fafc; border:1px solid #cbd5e1;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #cbd5e1; padding-bottom:5px; margin-bottom:8px;">
                <h3 style="margin:0; font-size:14px; color:#1e293b;"><?= $section_title ?></h3>
                <?php if (!$is_admin): ?>
                <button type="button"
                    onclick="document.getElementById('<?= $section_id ?>').style.display='flex'"
                    title="各ファイルを選択して「一括アップロード」ボタンを押すと、選択したファイルのみ登録されます。&#10;差し替えの場合は差し替え理由を入力してください（差し替え理由は全ファイル共通で適用されます）。"
                    style="background:#f59e0b; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer; white-space:nowrap;">
                    📤 一括UP/更新
                </button>
                <?php endif; ?>
            </div>
            
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($categories as $cat => $label): ?>
                    <?php 
                    $history = $files_by_cat[$cat] ?? []; 
                    $clean_label = $label;
                    if (strpos($cat, 'custom_') === 0) {
                        $parts = explode('_', $cat);
                        $clean_label = end($parts);
                    }
                    ?>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; padding:8px;">
                        <div style="font-weight:bold; font-size:12px; color:#334155; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                            <span><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                            <?php if ($is_admin && strpos($cat, 'custom_') === 0): ?>
                                <button type="button" onclick="renameCustomSlot('<?= htmlspecialchars($cat, ENT_QUOTES) ?>', '<?= htmlspecialchars($clean_label, ENT_QUOTES) ?>', '<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>')" style="background:none; border:none; padding:0; cursor:pointer; font-size:11px;" title="名称変更">✏️</button>
                            <?php endif; ?>
                        </div>
                        
                        <?php 
                        $actual_history = [];
                        if (!empty($history)) {
                            foreach ($history as $h) {
                                if (!empty($h['drive_file_id']) || $h['file_name'] === '【他ファイルに記載】') {
                                    $actual_history[] = $h;
                                }
                            }
                        }
                        $has_actual_file = !empty($actual_history);
                        
                        if ($has_actual_file): 
                            $latest = $actual_history[0]; 
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
                                
                                <?php if ($is_admin && (strpos($cat, 'cad_') === 0 || strpos($cat, 'custom_') === 0)): ?>
                                    <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="toggle_cad_publish">
                                        <input type="hidden" name="file_id" value="<?= $latest['id'] ?>">
                                        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                                        <?php if ($latest['is_published_to_sub']): ?>
                                            <button type="submit" style="background:#dc3545; color:white; border:none; padding:3px 8px; border-radius:3px; font-size:10px; cursor:pointer;" onclick="return confirm('業者への公開を取り消しますか？')">業者公開を解除</button>
                                        <?php else: ?>
                                            <button type="submit" style="background:#28a745; color:white; border:none; padding:3px 8px; border-radius:3px; font-size:10px; cursor:pointer;">業者へ公開する</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>

                                <?php if (count($actual_history) > 1): ?>
                                    <select onchange="if(this.value) window.open(this.value, '_blank');" style="font-size:11px; padding:3px; max-width:140px;">
                                        <option value="">過去バージョン...</option>
                                        <?php foreach ($actual_history as $idx => $h): 
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

                        <!-- 個別アップロードフォーム -->
                        <?php if (!$is_admin): ?>
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin-top:2px; display:flex; flex-direction:column; gap:2px; border-top:1px dashed #e2e8f0; padding-top:3px;">
                                <input type="hidden" name="file_category" value="<?= $cat ?>">
                                <input type="hidden" name="action_type" value="single_upload">
                                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                                
                                <div style="display:flex; gap:3px; align-items:center;">
                                    <input type="file" name="upload_file" id="file_<?= $cat ?>" <?= !$has_actual_file ? 'required' : '' ?> style="font-size:10px; flex:1; min-width:90px; padding:2px;">
                                    
                                    <?php if (!$has_actual_file): ?>
                                    <label style="font-size:9px; display:flex; align-items:center; gap:2px; color:#d97706; white-space:nowrap; cursor:pointer;" title="他のCADファイルに記載がある場合">
                                        <input type="checkbox" name="included_in_other" value="1" onchange="document.getElementById('file_<?= $cat ?>').required = !this.checked; document.getElementById('file_<?= $cat ?>').disabled = this.checked;"> 
                                        別ﾌｧｲﾙ済
                                    </label>
                                    <?php endif; ?>

                                    <button type="submit" style="font-size:10px; background:#10b981; color:white; border:none; padding:3px 6px; border-radius:3px; cursor:pointer; white-space:nowrap;">UP/更新</button>
                                </div>
                                
                                <?php if ($has_actual_file): ?>
                                    <input type="text" name="update_reason" placeholder="差し替え理由を入力して下さい" required style="font-size:10px; width:100%; padding:2px; border:1px solid #cbd5e1; border-radius:3px; box-sizing:border-box;">
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php 
                $show_add_slot = false;
                if (!$is_admin) {
                    if ($section_title === '共通図書') {
                        $show_add_slot = true;
                    } elseif ($section_title === '専門図書' && in_array($active_tab, ['permit', 'wall', 'skin', 'sky'])) {
                        $show_add_slot = true;
                    }
                }
                if ($show_add_slot): 
                    $btn_id = 'btn_show_custom_slot_' . $section_idx;
                    $form_id = 'add_custom_slot_form_' . $section_idx;
                ?>
                    <!-- 別の図書を追加するフォーム -->
                    <div style="background:#f1f5f9; border:1px dashed #cbd5e1; border-radius:6px; padding:10px; margin-top:10px; text-align:center;">
                        <button type="button" id="<?= $btn_id ?>" onclick="document.getElementById('<?= $form_id ?>').style.display='block'; this.style.display='none';" style="background:#3b82f6; color:white; border:none; padding:5px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">
                            ➕ 別の図書スロットを追加
                        </button>
                        <div id="<?= $form_id ?>" style="display:none; text-align:left;">
                            <form action="project_detail.php?id=<?= $project_id ?>" method="POST" style="margin:0; display:flex; flex-direction:column; gap:5px;">
                                <input type="hidden" name="action" value="add_custom_slot">
                                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                                <input type="hidden" name="section_type" value="<?= htmlspecialchars($section_title, ENT_QUOTES) ?>">
                                <label style="font-size:11px; font-weight:bold; color:#475569;">追加する図書の名称（例：3F平面図）</label>
                                <div style="display:flex; gap:5px;">
                                    <input type="text" name="custom_slot_label" placeholder="図書の名称を入力してください" required style="font-size:11px; flex:1; padding:4px; border:1px solid #cbd5e1; border-radius:4px;">
                                    <button type="submit" style="background:#10b981; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">追加</button>
                                    <button type="button" onclick="document.getElementById('<?= $form_id ?>').style.display='none'; document.getElementById('<?= $btn_id ?>').style.display='inline-block';" style="background:#6c757d; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer;">キャンセル</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$is_admin): ?>
        <!-- 一括UPモーダル -->
        <div id="<?= $section_id ?>" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9000; justify-content:center; align-items:center; padding:20px; box-sizing:border-box;" onclick="if(event.target===this) this.style.display='none'">
            <div style="background:#fff; border-radius:10px; padding:20px; max-width:600px; width:100%; max-height:85vh; overflow-y:auto; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:2px solid #3b82f6; padding-bottom:10px;">
                    <h3 style="margin:0; color:#1e293b; font-size:16px;">📤 一括UP/更新：<?= htmlspecialchars($section_title) ?></h3>
                    <button onclick="document.getElementById('<?= $section_id ?>').style.display='none'" style="background:#6c757d; color:white; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:13px;">✕ 閉じる</button>
                </div>
                <div style="font-size:12px; color:#555; margin-bottom:15px; background:#f0f9ff; border:1px solid #bae6fd; padding:8px; border-radius:4px;">
                    ⚠️ 各ファイルを選択して「一括アップロード」ボタンを押すと、選択したファイルのみ登録されます。<br>
                    差し替えの場合は差し替え理由を入力してください（差し替え理由は全ファイル共通で適用されます）。
                </div>
                <form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action_type" value="bulk_upload">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES) ?>">
                    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:15px;">
                        <?php foreach ($categories as $cat => $label): 
                            $hist = $files_by_cat[$cat] ?? [];
                        ?>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-weight:bold; font-size:12px; color:#334155;"><?= htmlspecialchars($label) ?></label>
                                <?php if (!empty($hist)): ?>
                                    <span style="font-size:10px; color:#059669; font-weight:bold;">✓ 提出済 (V<?= $hist[0]['version'] ?>)</span>
                                <?php else: ?>
                                    <span style="font-size:10px; color:#ef4444; font-weight:bold;">未提出</span>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <input type="file" name="bulk_files[<?= htmlspecialchars($cat) ?>]" style="font-size:11px; flex:1;">
                                <?php if (empty($hist)): ?>
                                <label style="font-size:10px; display:flex; align-items:center; gap:3px; color:#d97706; white-space:nowrap; cursor:pointer;" title="他のCADファイルに記載がある場合">
                                    <input type="checkbox" name="bulk_included_in_other[<?= htmlspecialchars($cat) ?>]" value="1"> 別ﾌｧｲﾙ済
                                </label>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px; color:#334155;">差し替え理由（差し替えのファイルがある場合は入力必須）</label>
                        <input type="text" name="bulk_update_reason" placeholder="例：設計変更（窓の位置変更）" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; box-sizing:border-box;">
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="document.getElementById('<?= $section_id ?>').style.display='none'" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#3b82f6; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">📤 一括アップロード</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    <?php 
        $section_idx++;
        endforeach; 
    ?>
</div>

<script>
function renameCustomSlot(oldCategory, currentLabel, activeTab) {
    const newLabel = prompt("図書スロット名を変更しますか？\n現在の名称: " + currentLabel, currentLabel);
    if (newLabel === null) return; // キャンセル
    const trimmed = newLabel.trim();
    if (trimmed === "") {
        alert("名称を入力してください。");
        return;
    }
    if (trimmed === currentLabel) return; // 変更なし
    
    // フォームを動的生成して送信
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'project_detail.php?id=<?= $project_id ?>';
    
    const fields = {
        'action': 'rename_custom_slot',
        'old_category': oldCategory,
        'new_label': trimmed,
        'tab': activeTab
    };
    
    for (const [key, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        form.appendChild(input);
    }
    
    document.body.appendChild(form);
    form.submit();
}
</script>

