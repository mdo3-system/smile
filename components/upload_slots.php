<?php
// components/upload_slots.php
// 【正式依頼モーダル】依頼種別に応じた図書アップロードスロット

if (!function_exists('renderUploadSlot')) {
    function renderUploadSlot($label, $name, $isRequired = true, $note = '') {
        $reqSpan = $isRequired ? '<span style="color:red;">*</span>' : '<span style="color:#d97706; font-size:10px;">(後出し可)</span>';
        $requiredAttr = $isRequired ? 'required' : '';
        $noteHtml = $note ? "<div style='font-size:10px; color:#64748b; margin-top:3px;'>{$note}</div>" : '';
        return <<<HTML
        <div style="margin-bottom:10px; padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff;">
            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:5px;">{$label} {$reqSpan}</label>
            {$noteHtml}
            <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                <input type="file" name="upload_files[{$name}][]" accept=".pdf,.zip,.jww,.dxf,.jw_" id="file_{$name}" {$requiredAttr} style="font-size:11px; flex:1;" onchange="document.getElementById('chk_{$name}').checked && (this.required=false);">
                <label style="font-size:11px; color:#475569; display:flex; align-items:center; gap:3px; white-space:nowrap;">
                    <input type="checkbox" name="included_in_other[{$name}]" id="chk_{$name}" value="1" onchange="document.getElementById('file_{$name}').required = !this.checked;"> 他ﾌｧｲﾙに記載
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
try {
    $stmtPast = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = :uid AND status NOT IN ('quote_req') AND id != :current");
    $stmtPast->execute(['uid' => $_SESSION['user_id'], 'current' => $project_id]);
    $has_past_projects = ((int)$stmtPast->fetchColumn()) > 0;
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
        <?= renderUploadSlot('地盤調査報告書', 'soil_report', false, '後出し可（揃った日が一次回答起算日）') ?>
        <?php if (isset($project_info['soil_status']) && $project_info['soil_status'] === '改良あり'): ?>
        <?= renderUploadSlot('地盤改良設計書', 'soil_impr', false, '地盤改良がある場合') ?>
        <?php endif; ?>
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
        <strong style="display:block; margin-bottom:8px; color:#065f46;">🟢 【外皮計算】必要図書</strong>
        <?= renderUploadSlot('仕様書', 'spec_doc', true) ?>
        <?= renderUploadSlot('断熱材資料（屋根・天井・外壁・基礎）', 'insulation_data', true) ?>
        <?= renderUploadSlot('サッシ・玄関ドア仕様', 'sash_data', true) ?>
        <?= renderUploadSlot('24時間換気計算図書', 'ventilation_data', true) ?>
        <?= renderUploadSlot('設備機器カタログ（エコキュート・照明等）', 'equip_data', true) ?>
    </div>
    <?php endif; ?>

    <?php if ($needs_specs): ?>
    <!-- ============================================
         【許容応力度・基礎梁のみ】構造材種・金物・耐力壁の指定
    ============================================ -->
    <div style="margin-bottom:15px; border:1px solid #7c3aed; padding:12px; border-radius:6px; background:#f5f3ff;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <strong style="color:#5b21b6;">🟣 【許容応力度・基礎梁計算】構造材種・金物・耐力壁の指定</strong>
            <?php if ($has_past_projects): ?>
            <label style="font-size:11px; background:#fff; border:1px solid #ccc; padding:2px 8px; border-radius:4px; cursor:pointer; color:#374151;">
                <input type="checkbox" id="copy_past_specs" onchange="loadPastSpecs(this.checked)"> 過去の案件と同じ
            </label>
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
                <input type="text" name="spec_dodai" placeholder="例: ヒノキ □105" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">大引</label>
                <input type="text" name="spec_obiki" placeholder="例: ヒノキ □90" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">柱</label>
                <input type="text" name="spec_hashira" placeholder="例: ヒノキ □105" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">梁</label>
                <input type="text" name="spec_hari" placeholder="例: ベイマツ KD" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">小屋束</label>
                <input type="text" name="spec_koyatsuka" placeholder="例: ヒノキ □90" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">母屋</label>
                <input type="text" name="spec_moya" placeholder="例: ヒノキ □105" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">棟木</label>
                <input type="text" name="spec_munagi" placeholder="例: ヒノキ □105" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">垂木 <span style="font-weight:normal; font-size:10px; color:#6b7280;">（W×H@間隔mm）</span></label>
                <input type="text" name="spec_taruki" placeholder="例: 45×60@364" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div>
                <label style="font-weight:bold; color:#374151;">金物指定</label>
                <input type="text" name="spec_kanamono" placeholder="例: Z金物、Tec-One等" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
            <div style="grid-column: 1 / -1;">
                <label style="font-weight:bold; color:#374151;">耐力壁仕様</label>
                <input type="text" name="spec_wall" placeholder="例: 構造用合板 9mm+EXハイパー等" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box;">
            </div>
        </div>
    </div>

    <script>
    function loadPastSpecs(checked) {
        if (checked) { alert('過去の案件からデータをロードする機能は準備中です。'); document.getElementById('copy_past_specs').checked = false; }
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
    </script>
    <?php endif; ?>

</div>
