<?php
// components/upload_slots.php
// 【正式依頼モーダル】依頼種別に応じた図書アップロードスロット

if (!function_exists('renderUploadSlot')) {
    function renderUploadSlot($label, $name, $isRequired = true, $note = '') {
        $reqSpan = $isRequired ? '<span style="color:red;">*</span>' : '<span style="color:#d97706; font-size:10px;">(後出し可)</span>';
        $requiredAttr = $isRequired ? 'required' : '';
        $noteHtml = $note ? "<div style='font-size:10px; color:#64748b; margin-top:3px;'>{$note}</div>" : '';
        $jsRequired = $isRequired ? 'true' : 'false';
        return <<<HTML
        <div style="margin-bottom:10px; padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff;">
            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:5px;">{$label} {$reqSpan}</label>
            {$noteHtml}
            <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                <input type="file" name="upload_files[{$name}][]" accept=".pdf,.zip,.jww,.dxf,.jw_" id="file_{$name}" {$requiredAttr} style="font-size:11px; flex:1;" onchange="document.getElementById('chk_{$name}').checked && (this.required=false);">
                <label style="font-size:11px; color:#475569; display:flex; align-items:center; gap:3px; white-space:nowrap;">
                    <input type="checkbox" name="included_in_other[{$name}]" id="chk_{$name}" value="1" onchange="if ({$jsRequired}) { document.getElementById('file_{$name}').required = !this.checked; }"> 他ﾌｧｲﾙに記載
                </label>
            </div>
        </div>
HTML;
    }
}

// ============================================
// 依頼種別フラグ
// ============================================
$is_permit    = (int)($project_info['req_permit'] ?? 0);
$is_wall      = (int)($project_info['req_wall'] ?? 0);
$is_skin      = (int)($project_info['req_skin'] ?? 0);
$is_sky       = (int)($project_info['req_sky'] ?? 0);
$is_kisohari  = (int)($project_info['req_opt_kisohari'] ?? 0);

// 構造材種入力が必要か（許容応力度・基礎横架材計算のみ）
$needs_specs  = ($is_permit || $is_kisohari);
// 地盤調査報告書が必要か（同上）
$needs_soil   = ($is_permit || $is_kisohari);

// 初回ユーザー判定（過去に completed/primary_prep 以降まで進んだ案件が0件）
$has_past_projects = false;
$past_projects_data = [];
try {
    $stmtPast = $pdo->prepare("
        SELECT p.id, p.project_name, s.wood_details, s.wall_details, s.hardware_details, s.soil_status
        FROM projects p
        JOIN project_specs s ON p.id = s.project_id
        WHERE p.client_id = :uid AND p.id != :current AND p.status NOT IN ('quote_req')
    ");
    $stmtPast->execute(['uid' => $_SESSION['user_id'], 'current' => $project_id]);
    $past_projects_data = $stmtPast->fetchAll(PDO::FETCH_ASSOC);
    $has_past_projects = count($past_projects_data) > 0;
} catch(Exception $e) { $has_past_projects = false; }

// 天空率の道路・北側の必要有無を見積明細から判定
$req_road = false;
$req_north = false;
if ($is_sky && isset($all_estimates) && !empty($all_estimates)) {
    $latest_note = json_decode($all_estimates[0]['note'] ?? '[]', true) ?: [];
    foreach ($latest_note as $item) {
        if (isset($item['name']) && isset($item['amount']) && (float)$item['amount'] > 0) {
            if (strpos($item['name'], '天空率 道路斜線') !== false) $req_road = true;
            if (strpos($item['name'], '天空率 北側斜線') !== false) $req_north = true;
        }
    }
    if (!$req_road && !$req_north) { $req_road = true; $req_north = true; }
} elseif ($is_sky) {
    $req_road = true; $req_north = true;
}
?>

<div id="upload_slots_container">

    <!-- ============================================
         【共通】意匠CADデータ（全依頼で必須）
    ============================================ -->
    <div style="margin-bottom:15px; border:2px solid #ef4444; padding:12px; border-radius:6px; background:#fef2f2;">
        <strong style="display:block; margin-bottom:8px; color:#b91c1c;">🔴 【必須】意匠CADデータ（正式依頼時に必ず必要）</strong>
        <div style="font-size:11px; color:#6b7280; margin-bottom:10px;">JWW/DXF等のCADデータをアップロードしてください。個別図面でも一括ZIPでも構いません。</div>
        <?= renderUploadSlot('配置図 (CAD)', 'cad_layout', true, 'JWW/DXF形式') ?>
        <?= renderUploadSlot('1F平面図 (CAD)', 'cad_plan_1f', true, 'JWW/DXF形式') ?>
        <?= renderUploadSlot('2F平面図 (CAD)', 'cad_plan_2f', false, '平屋の場合は不要（送信時に確認します）') ?>
        <?= renderUploadSlot('3F・PH・RF 平面図 (CAD)', 'cad_plan_3f', false, '該当する場合のみ') ?>
        <?= renderUploadSlot('立面図 (CAD)', 'cad_elevation', true, '各方向の立面図') ?>
        <?= renderUploadSlot('矩計図 (CAD)', 'cad_section', false, '必要に応じて提出') ?>
        
        <!-- 動的スロット追加先 -->
        <div id="dynamic_cad_slots_container"></div>
        <div style="text-align: right; margin-top: 10px; margin-bottom: 10px;">
            <button type="button" onclick="addDynamicCadSlot()" style="background:#3b82f6; color:white; border:none; padding:5px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">➕ 別のCAD図面スロットを追加</button>
        </div>

        <div style="background:#fff8e1; border:1px solid #f59e0b; border-radius:4px; padding:6px; font-size:11px; color:#92400e; margin-top:8px;">
            💡 全図面を一括ZIPにまとめてアップロードすることもできます → 「配置図」スロットにZIPを添付してください。
        </div>
    </div>

    <!-- ============================================
         【全依頼共通・後出し可】確認申請書
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #f59e0b; padding:12px; border-radius:6px; background:#fff8e1;">
        <strong style="display:block; margin-bottom:8px; color:#d97706;">🟡 【後出し可・全依頼共通】確認申請書（2〜5面）</strong>
        <div style="font-size:11px; color:#6b7280; margin-bottom:10px;">
            正式依頼時に必須ですが、後から提出しても構いません。<br>
            <span style="color:#d97706; font-weight:bold;">提出が完了した時点が「一次回答」の起算日となります。</span>
        </div>
        <?= renderUploadSlot('確認申請書（2面〜5面）', 'app_doc', false, '後出し可（揃った日が一次回答起算日）') ?>
    </div>

    <?php if ($needs_soil): ?>
    <!-- ============================================
         【許容応力度・基礎梁のみ・後出し可】地盤調査報告書
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #f59e0b; padding:12px; border-radius:6px; background:#fff8e1;">
        <strong style="display:block; margin-bottom:8px; color:#d97706;">🟡 【後出し可】地盤調査報告書（許容応力度・基礎梁計算のみ）</strong>
        
        <div style="font-size:12px; margin-bottom:10px; background:#fff; padding:8px; border:1px solid #e2e8f0; border-radius:4px;">
            <span style="font-weight:bold; display:block; margin-bottom:5px; color:#475569;">地盤調査状況:</span>
            <label style="cursor:pointer; margin-right:15px;"><input type="radio" name="soil_status" value="調査済み" <?= ($project_info['soil_status'] ?? '') === '調査済み' ? 'checked' : '' ?>> 調査済みで報告書をUP</label>
            <label style="cursor:pointer; margin-right:15px;"><input type="radio" name="soil_status" value="未調査" <?= ($project_info['soil_status'] ?? '') === '未調査' ? 'checked' : '' ?>> 未調査</label>
            <label style="cursor:pointer;"><input type="radio" name="soil_status" value="調査予定" <?= ($project_info['soil_status'] ?? '') === '調査予定' ? 'checked' : '' ?>> 調査予定</label>
        </div>

        <?= renderUploadSlot('地盤調査報告書', 'soil_report', false, '後出し可（揃った日が一次回答起算日）') ?>
        <?php if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり'): ?>
        <?= renderUploadSlot('地盤改良設計書', 'soil_impr', false, '地盤改良がある場合') ?>
        <?php endif; ?>
        
        <!-- 動的地盤スロット追加先 -->
        <div id="dynamic_soil_slots_container"></div>
        <div style="text-align: right; margin-top: 10px; margin-bottom: 10px;">
            <button type="button" onclick="addDynamicSoilSlot()" style="background:#3b82f6; color:white; border:none; padding:5px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">➕ 別の地盤関連図書スロットを追加</button>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($is_permit || $is_wall || $is_kisohari): ?>
    <!-- ============================================
         【後出し可】プレカット図等
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #10b981; padding:12px; border-radius:6px; background:#e6f4ea;">
        <strong style="display:block; margin-bottom:8px; color:#137333;">🟢 【後出し可】プレカット図等</strong>
        <div style="font-size:11px; color:#6b7280; margin-bottom:10px;">
            プレカット図面等の資料をアップロードしてください。
        </div>
        <?= renderUploadSlot('プレカット図等', 'pdf_precut', false) ?>
        
        <!-- 動的プレカットスロット追加先 -->
        <div id="dynamic_precut_slots_container"></div>
        <div style="text-align: right; margin-top: 10px;">
            <button type="button" onclick="addDynamicPrecutSlot()" style="background:#10b981; color:white; border:none; padding:5px 12px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;">➕ 別のプレカット関連図書スロットを追加</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_sky): ?>
    <!-- ============================================
         【天空率】計算用資料
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #2563eb; padding:12px; border-radius:6px; background:#eff6ff;">
        <strong style="display:block; margin-bottom:8px; color:#1d4ed8;">🔵 【天空率計算】必要図書</strong>
        <?php if ($req_road) echo renderUploadSlot('道路の資料（座標・測量図・道路台帳等）', 'road_data', true, '天空率 道路斜線計算に必要'); ?>
        <?php if ($req_north) echo renderUploadSlot('真北の資料（配置図に記載済みの場合はチェックで提出不要）', 'true_north', false, '天空率 北側斜線計算に必要'); ?>
    </div>
    <?php endif; ?>

    <?php if ($is_skin): ?>
    <!-- ============================================
         【外皮計算】必要図書
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #059669; padding:12px; border-radius:6px; background:#ecfdf5;">
        <strong style="display:block; margin-bottom:8px; color:#065f46;">🟢 【外皮計算】必要図書（後出し可）</strong>
        <?= renderUploadSlot('仕様書', 'spec_doc', false) ?>
        <?= renderUploadSlot('断熱材資料（屋根・天井・外壁・基礎）', 'insulation_data', false) ?>
        <?= renderUploadSlot('サッシ・玄関ドア仕様', 'sash_data', false) ?>
        <?= renderUploadSlot('24時間換気計算図書', 'ventilation_data', false) ?>
        <?= renderUploadSlot('設備機器カタログ（エコキュート・照明等）', 'equip_data', false) ?>
    </div>
    <?php endif; ?>

    <?php if ($needs_specs): ?>
    <?php
    // JSONデコード
    $wood_json = json_decode($project_info['wood_details'] ?? '{}', true) ?: [];
    $wall_json = json_decode($project_info['wall_details'] ?? '{}', true) ?: [];
    $hw_json = json_decode($project_info['hardware_details'] ?? '{}', true) ?: [];

    // パース関数
    if (!function_exists('parseSpecValue')) {
        function parseSpecValue($val, $options) {
            $matched_opt = '';
            $rest = $val;
            foreach ($options as $opt) {
                if ($opt !== 'その他' && strpos($val, $opt) === 0) {
                    $matched_opt = $opt;
                    $rest = trim(substr($val, strlen($opt)));
                    break;
                }
            }
            if (!$matched_opt && $val !== '') {
                $matched_opt = 'その他';
                $rest = $val;
            }
            return ['type' => $matched_opt, 'size' => $rest];
        }
    }

    $dodai_parsed    = parseSpecValue($wood_json['dodai'] ?? '', ['ﾋﾉｷKD', 'ﾍﾞｲﾏﾂ', 'ﾍﾞｲﾂｶﾞKD']);
    $obiki_parsed    = parseSpecValue($wood_json['obiki'] ?? '', ['ﾋﾉｷKD', 'ﾍﾞｲﾂｶﾞKD', 'ｽｷﾞKD']);
    $hashira_parsed  = parseSpecValue($wood_json['hashira'] ?? '', ['ﾋﾉｷKD', 'ｽｷﾞKD', 'ｽｷﾞ集成', 'WW集成']);
    $hari_parsed     = parseSpecValue($wood_json['hari'] ?? '', ['ﾍﾞｲﾏﾂKD', 'ｽｷﾞKD', 'ｽｷﾞ集成', 'RE集成', 'ﾊｲﾌﾞﾘｯﾄﾞ集成']);
    $koyatsuka_parsed= parseSpecValue($wood_json['koya'] ?? '', ['ｽｷﾞKD', 'ﾍﾞｲﾏﾂKD']);
    $moya_parsed     = parseSpecValue($wood_json['moya'] ?? '', ['ｽｷﾞKD', 'ﾍﾞｲﾏﾂKD']);
    $munagi_parsed   = parseSpecValue($wood_json['munagi'] ?? '', ['ｽｷﾞKD', 'ﾍﾞｲﾏﾂKD']);
    
    // 垂木のパース
    $taruki_val = $wood_json['taruki'] ?? '';
    $taruki_parsed = parseSpecValue($taruki_val, ['ﾍﾞｲﾏﾂKD', 'ｽｷﾞKD']);
    $taruki_w = '';
    $taruki_h = '';
    $taruki_pitch = '';
    $taruki_size_other = $taruki_parsed['size'];
    if ($taruki_parsed['type'] !== 'その他' && preg_match('/(\d+)\s*×\s*(\d+)\s*@\s*(\d+)/', $taruki_parsed['size'], $m)) {
        $taruki_w = $m[1];
        $taruki_h = $m[2];
        $taruki_pitch = $m[3];
        $taruki_size_other = '';
    }
    ?>
    <!-- ============================================
         【許容応力度・基礎梁のみ】構造材種・金物・耐力壁の指定
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #7c3aed; padding:12px; border-radius:6px; background:#f5f3ff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <strong style="color:#5b21b6;">🟣 【許容応力度・基礎梁計算】構造材種・金物・耐力壁の指定（ここで指定するか、依頼主アップロード図書内のプレカット図等にUPしてください）</strong>
            <?php if ($has_past_projects): ?>
            <div style="display:flex; align-items:center; gap:5px;">
                <label style="font-size:11px; background:#fff; border:1px solid #ccc; padding:2px 8px; border-radius:4px; cursor:pointer; color:#374151;">
                    <input type="checkbox" id="copy_past_specs" onchange="loadPastSpecs(this.checked)"> 過去の案件と同じ
                </label>
                <select id="past_projects_selector" onchange="loadPastProjectSpecs(this.value)" style="font-size:11px; padding:2px 5px; border-radius:4px; border:1px solid #ccc; display:none; max-width:200px;">
                    <option value="">- 過去の案件を選択 -</option>
                    <?php foreach ($past_projects_data as $pp): ?>
                        <option value="<?= $pp['id'] ?>"><?= htmlspecialchars($pp['project_name'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <span style="font-size:11px; color:#9ca3af; background:#f3f4f6; padding:2px 8px; border-radius:4px;">過去の案件と同じ（初回は利用不可）</span>
            <?php endif; ?>
        </div>
        <div style="font-size:11px; color:#6b7280; margin-bottom:12px;">
            ※ 壁量計算のみの依頼では材種の指定は不要です（このセクションは許容応力度・基礎梁計算の場合のみ表示されます）。
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px;">
            <div>
                <label style="font-weight:bold; color:#374151;">土台</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_dodai_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ﾋﾉｷKD" <?= $dodai_parsed['type'] === 'ﾋﾉｷKD' ? 'selected' : '' ?>>ﾋﾉｷKD</option>
                        <option value="ﾍﾞｲﾏﾂ" <?= $dodai_parsed['type'] === 'ﾍﾞｲﾏﾂ' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂ</option>
                        <option value="ﾍﾞｲﾂｶﾞKD" <?= $dodai_parsed['type'] === 'ﾍﾞｲﾂｶﾞKD' ? 'selected' : '' ?>>ﾍﾞｲﾂｶﾞKD</option>
                        <option value="その他" <?= $dodai_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_dodai_size" value="<?= htmlspecialchars($dodai_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □105" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">大引</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_obiki_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ﾋﾉｷKD" <?= $obiki_parsed['type'] === 'ﾋﾉｷKD' ? 'selected' : '' ?>>ﾋﾉｷKD</option>
                        <option value="ﾍﾞｲﾂｶﾞKD" <?= $obiki_parsed['type'] === 'ﾍﾞｲﾂｶﾞKD' ? 'selected' : '' ?>>ﾍﾞｲﾂｶﾞKD</option>
                        <option value="ｽｷﾞKD" <?= $obiki_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="その他" <?= $obiki_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_obiki_size" value="<?= htmlspecialchars($obiki_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □90" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">柱</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_hashira_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ﾋﾉｷKD" <?= $hashira_parsed['type'] === 'ﾋﾉｷKD' ? 'selected' : '' ?>>ﾋﾉｷKD</option>
                        <option value="ｽｷﾞKD" <?= $hashira_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="ｽｷﾞ集成" <?= $hashira_parsed['type'] === 'ｽｷﾞ集成' ? 'selected' : '' ?>>ｽｷﾞ集成</option>
                        <option value="WW集成" <?= $hashira_parsed['type'] === 'WW集成' ? 'selected' : '' ?>>WW集成</option>
                        <option value="その他" <?= $hashira_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_hashira_size" value="<?= htmlspecialchars($hashira_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □105" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">梁</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_hari_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ﾍﾞｲﾏﾂKD" <?= $hari_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                        <option value="ｽｷﾞKD" <?= $hari_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="ｽｷﾞ集成" <?= $hari_parsed['type'] === 'ｽｷﾞ集成' ? 'selected' : '' ?>>ｽｷﾞ集成</option>
                        <option value="RE集成" <?= $hari_parsed['type'] === 'RE集成' ? 'selected' : '' ?>>RE集成</option>
                        <option value="ﾊｲﾌﾞﾘｯﾄﾞ集成" <?= $hari_parsed['type'] === 'ﾊｲﾌﾞﾘｯﾄﾞ集成' ? 'selected' : '' ?>>ﾊｲﾌﾞﾘｯﾄﾞ集成</option>
                        <option value="その他" <?= $hari_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_hari_size" value="<?= htmlspecialchars($hari_parsed['size'], ENT_QUOTES) ?>" placeholder="例: 105×150" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">小屋束</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_koyatsuka_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ｽｷﾞKD" <?= $koyatsuka_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="ﾍﾞｲﾏﾂKD" <?= $koyatsuka_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                        <option value="その他" <?= $koyatsuka_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_koyatsuka_size" value="<?= htmlspecialchars($koyatsuka_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □90" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">母屋</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_moya_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ｽｷﾞKD" <?= $moya_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="ﾍﾞｲﾏﾂKD" <?= $moya_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                        <option value="その他" <?= $moya_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_moya_size" value="<?= htmlspecialchars($moya_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □90" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">棟木</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_munagi_type" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ｽｷﾞKD" <?= $munagi_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="ﾍﾞｲﾏﾂKD" <?= $munagi_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                        <option value="その他" <?= $munagi_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    <input type="text" name="spec_munagi_size" value="<?= htmlspecialchars($munagi_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □105" style="flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">垂木</label>
                <div style="display:flex; gap:5px;">
                    <select name="spec_taruki_type" id="spec_taruki_type" onchange="toggleTarukiInput(this.value)" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                        <option value="">- 選択 -</option>
                        <option value="ﾍﾞｲﾏﾂKD" <?= $taruki_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                        <option value="ｽｷﾞKD" <?= $taruki_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                        <option value="その他" <?= $taruki_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                    </select>
                    
                    <div id="taruki_dim_fields" style="display: <?= $taruki_parsed['type'] === 'その他' ? 'none' : 'flex' ?>; gap:2px; align-items:center; flex:1; font-size:10px;">
                        <input type="text" name="spec_taruki_w" value="<?= htmlspecialchars($taruki_w, ENT_QUOTES) ?>" placeholder="幅" style="width:28px; padding:6px 2px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;">
                        <span>×</span>
                        <input type="text" name="spec_taruki_h" value="<?= htmlspecialchars($taruki_h, ENT_QUOTES) ?>" placeholder="高" style="width:28px; padding:6px 2px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;">
                        <span>@</span>
                        <input type="text" name="spec_taruki_pitch" value="<?= htmlspecialchars($taruki_pitch, ENT_QUOTES) ?>" placeholder="ピッチ" style="width:32px; padding:6px 2px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;">
                    </div>
                    
                    <input type="text" name="spec_taruki_size" id="taruki_other_field" value="<?= htmlspecialchars($taruki_size_other, ENT_QUOTES) ?>" placeholder="例: 45×60@364" style="display: <?= $taruki_parsed['type'] === 'その他' ? 'block' : 'none' ?>; flex:1; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
                </div>
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">金物指定</label>
                <input type="text" name="spec_kanamono" value="<?= htmlspecialchars($hw_json['type'] ?? '', ENT_QUOTES) ?>" placeholder="例: Z金物、Tec-One等" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
            </div>
            <div style="grid-column: 1 / -1;">
                <label style="font-weight:bold; color:#374151;">耐力壁仕様</label>
                <input type="text" name="spec_wall" value="<?= htmlspecialchars($wall_json['type'] ?? '', ENT_QUOTES) ?>" placeholder="例: 構造用合板 9mm+EXハイパー等" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:11px;">
            </div>
            <div style="grid-column: 1 / -1; margin-top: 10px;">
                <?= renderUploadSlot('大臣認定耐力壁の資料', 'spec_wall_doc', false, '大臣認定耐力壁を使用する場合の認定書など') ?>
            </div>
            <div style="grid-column: 1 / -1; margin-top: 10px;">
                <?= renderUploadSlot('金物資料', 'spec_hw_doc', false, '専用金物や認定金物を使用する場合の資料') ?>
            </div>
        </div>
    </div>
    
    <script>
    function toggleTarukiInput(val) {
        const dims = document.getElementById('taruki_dim_fields');
        const other = document.getElementById('taruki_other_field');
        if (dims && other) {
            if (val === 'その他') {
                dims.style.display = 'none';
                other.style.display = 'block';
            } else {
                dims.style.display = 'flex';
                other.style.display = 'none';
            }
        }
    }
    </script>

    <?php endif; ?>

    <script>
    const pastProjectsSpecs = <?php echo json_encode($past_projects_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    function loadPastSpecs(checked) {
        const selector = document.getElementById('past_projects_selector');
        if (selector) {
            selector.style.display = checked ? 'inline-block' : 'none';
            if (!checked) selector.value = '';
        }
    }

    function loadPastProjectSpecs(projId) {
        if (!projId) return;
        const proj = pastProjectsSpecs.find(p => p.id == projId);
        if (!proj) return;
        
        try {
            const wood = JSON.parse(proj.wood_details || '{}');
            const wall = JSON.parse(proj.wall_details || '{}');
            const hw = JSON.parse(proj.hardware_details || '{}');
            
            // 各項目の自動入力
            autoFillSpec('spec_dodai', wood.dodai, ['ﾋﾉｷKD', 'ﾍﾞｲﾏﾂ', 'ﾍﾞｲﾂｶﾞKD']);
            autoFillSpec('spec_obiki', wood.obiki, ['ﾋﾉｷKD', 'ﾍﾞｲﾂｶﾞKD', 'ｽｷﾞKD']);
            autoFillSpec('spec_hashira', wood.hashira, ['ﾋﾉｷKD', 'ｽｷﾞKD', 'ｽｷﾞ集成', 'WW集成']);
            autoFillSpec('spec_hari', wood.hari, ['ﾍﾞｲﾏﾂKD', 'ｽｷﾞKD', 'ｽｷﾞ集成', 'RE集成', 'ﾊｲﾌﾞﾘｯﾄﾞ集成']);
            autoFillSpec('spec_koyatsuka', wood.koya, ['ｽｷﾞKD', 'ﾍﾞｲﾏﾂKD']);
            autoFillSpec('spec_moya', wood.moya, ['ｽｷﾞKD', 'ﾍﾞｲﾏﾂKD']);
            autoFillSpec('spec_munagi', wood.munagi, ['ｽｷﾞKD', 'ﾍﾞｲﾏﾂKD']);
            
            // 垂木の処理
            fillTarukiSpec(wood.taruki);
            
            // 金物
            const kanamonoInput = document.querySelector('input[name="spec_kanamono"]');
            if (kanamonoInput) kanamonoInput.value = hw.type || '';
            
            // 耐力壁
            const wallInput = document.querySelector('input[name="spec_wall"]');
            if (wallInput) wallInput.value = wall.type || '';
            
            // 地盤
            if (proj.soil_status) {
                const soilRadio = document.querySelector(`input[name="soil_status"][value="${proj.soil_status}"]`);
                if (soilRadio) soilRadio.checked = true;
            }
        } catch (e) {
            console.error("過去データの読み込みに失敗しました:", e);
            alert("データのロードに失敗しました。");
        }
    }
    
    function autoFillSpec(prefix, fullVal, options) {
        const typeSelect = document.querySelector(`select[name="${prefix}_type"]`);
        const sizeInput = document.querySelector(`input[name="${prefix}_size"]`);
        if (!typeSelect || !sizeInput) return;

        if (!fullVal) {
            typeSelect.value = '';
            sizeInput.value = '';
            return;
        }
        let matchedType = '';
        let rest = fullVal;
        for (const opt of options) {
            if (fullVal.indexOf(opt) === 0) {
                matchedType = opt;
                rest = fullVal.substring(opt.length).trim();
                break;
            }
        }
        if (!matchedType && fullVal !== '') {
            matchedType = 'その他';
            rest = fullVal;
        }
        typeSelect.value = matchedType;
        sizeInput.value = rest;
    }

    function fillTarukiSpec(tarukiVal) {
        const typeSelect = document.getElementById('spec_taruki_type');
        const wInput = document.getElementsByName('spec_taruki_w')[0];
        const hInput = document.getElementsByName('spec_taruki_h')[0];
        const pitchInput = document.getElementsByName('spec_taruki_pitch')[0];
        const otherInput = document.getElementById('taruki_other_field');
        
        if (!typeSelect) return;

        if (!tarukiVal) {
            typeSelect.value = '';
            if (wInput) wInput.value = '';
            if (hInput) hInput.value = '';
            if (pitchInput) pitchInput.value = '';
            if (otherInput) otherInput.value = '';
            toggleTarukiInput('');
            return;
        }
        let matchedType = '';
        let rest = tarukiVal;
        const options = ['ﾍﾞｲﾏﾂKD', 'ｽｷﾞKD'];
        for (const opt of options) {
            if (tarukiVal.indexOf(opt) === 0) {
                matchedType = opt;
                rest = tarukiVal.substring(opt.length).trim();
                break;
            }
        }
        if (!matchedType && tarukiVal !== '') {
            matchedType = 'その他';
            rest = tarukiVal;
        }
        
        typeSelect.value = matchedType;
        toggleTarukiInput(matchedType);
        
        if (matchedType === 'その他') {
            if (otherInput) otherInput.value = rest;
        } else {
            const m = rest.match(/(\d+)\s*×\s*(\d+)\s*@\s*(\d+)/);
            if (m) {
                if (wInput) wInput.value = m[1];
                if (hInput) hInput.value = m[2];
                if (pitchInput) pitchInput.value = m[3];
            } else {
                typeSelect.value = 'その他';
                toggleTarukiInput('その他');
                if (otherInput) otherInput.value = tarukiVal;
            }
        }
    }
    
    // 2F平面図が未提出の場合の送信前確認
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.querySelector('#orderModal form') || document.querySelector('form[action]');
        if (form) {
            form.addEventListener('submit', function(e) {
                var f2 = document.getElementById('file_cad_plan_2f');
                var chk2 = document.getElementById('chk_cad_plan_2f');
                if (f2 && !f2.files.length && chk2 && !chk2.checked) {
                    if (!confirm('2F平面図が未提出ですが、送信してよいですか？\n（平屋の場合はOKをクリックしてください）')) {
                        e.preventDefault();
                    }
                }
            });
        }
    });

    function addDynamicCadSlot() {
        const label = prompt("追加する図面の名称を入力してください (例: 3F平面図, パースなど)");
        if (!label) return;
        const trimmedLabel = label.trim();
        if (trimmedLabel === "") return;

        const catName = "custom_" + trimmedLabel;

        if (document.getElementById("file_" + catName)) {
            alert("その名称のスロットは既に存在します。");
            return;
        }

        const container = document.getElementById("dynamic_cad_slots_container");
        if (!container) return;

        const div = document.createElement("div");
        div.style.cssText = "margin-bottom:10px; padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff;";
        div.innerHTML = `
            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:5px;">${trimmedLabel} (CAD) <span style="color:#d97706; font-size:10px;">(後出し可)</span></label>
            <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                <input type="file" name="upload_files[${catName}][]" accept=".pdf,.zip,.jww,.dxf,.jw_" id="file_${catName}" style="font-size:11px; flex:1;">
                <label style="font-size:11px; color:#475569; display:flex; align-items:center; gap:3px; white-space:nowrap;">
                    <input type="checkbox" name="included_in_other[${catName}]" id="chk_${catName}" value="1" onchange="document.getElementById('file_${catName}').required = !this.checked;"> 他ﾌｧｲﾙに記載
                </label>
            </div>
        `;
        container.appendChild(div);
    }

    function addDynamicSoilSlot() {
        const label = prompt("追加する地盤関連図書の名称を入力してください (例: 地盤補強工事写真, 報告書その2など)");
        if (!label) return;
        const trimmedLabel = label.trim();
        if (trimmedLabel === "") return;

        const catName = "custom_soil_" + trimmedLabel;

        if (document.getElementById("file_" + catName)) {
            alert("その名称のスロットは既に存在します。");
            return;
        }

        const container = document.getElementById("dynamic_soil_slots_container");
        if (!container) return;

        const div = document.createElement("div");
        div.style.cssText = "margin-bottom:10px; padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff;";
        div.innerHTML = `
            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:5px;">${trimmedLabel} <span style="color:#d97706; font-size:10px;">(後出し可)</span></label>
            <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                <input type="file" name="upload_files[${catName}][]" accept=".pdf,.zip,.jww,.dxf,.jw_" id="file_${catName}" style="font-size:11px; flex:1;">
                <label style="font-size:11px; color:#475569; display:flex; align-items:center; gap:3px; white-space:nowrap;">
                    <input type="checkbox" name="included_in_other[${catName}]" id="chk_${catName}" value="1" onchange="document.getElementById('file_${catName}').required = !this.checked;"> 他ﾌｧｲﾙに記載
                </label>
            </div>
        `;
        container.appendChild(div);
    }

    function addDynamicPrecutSlot() {
        const label = prompt("追加するプレカット関連図書の名称を入力してください (例: プレカット計算書, 金物配置図など)");
        if (!label) return;
        const trimmedLabel = label.trim();
        if (trimmedLabel === "") return;

        const catName = "custom_precut_" + trimmedLabel;

        if (document.getElementById("file_" + catName)) {
            alert("その名称のスロットは既に存在します。");
            return;
        }

        const container = document.getElementById("dynamic_precut_slots_container");
        if (!container) return;

        const div = document.createElement("div");
        div.style.cssText = "margin-bottom:10px; padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff;";
        div.innerHTML = `
            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:5px;">${trimmedLabel} <span style="color:#d97706; font-size:10px;">(後出し可)</span></label>
            <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                <input type="file" name="upload_files[${catName}][]" accept=".pdf,.zip,.jww,.dxf,.jw_" id="file_${catName}" style="font-size:11px; flex:1;">
                <label style="font-size:11px; color:#475569; display:flex; align-items:center; gap:3px; white-space:nowrap;">
                    <input type="checkbox" name="included_in_other[${catName}]" id="chk_${catName}" value="1" onchange="document.getElementById('file_${catName}').required = !this.checked;"> 他ﾌｧｲﾙに記載
                </label>
            </div>
        `;
        container.appendChild(div);
    }
    </script>

</div>
