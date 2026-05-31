<?php
// components/upload_slots.php

if (!function_exists('renderUploadSlot')) {
    function renderUploadSlot($label, $name, $isRequired = true) {
        $reqSpan = $isRequired ? '<span style="color:red;">*</span>' : '';
        $requiredAttr = $isRequired ? 'required' : '';
        // To allow "included in another file" to bypass HTML5 'required', we will remove 'required' attr if checkbox is checked.
        $html = <<<HTML
        <div style="margin-bottom:12px; padding:10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff;">
            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:5px;">{$label} {$reqSpan}</label>
            <div style="display:flex; align-items:center; gap:10px;">
                <input type="file" name="upload_files[{$name}][]" accept=".pdf,.zip,.jww,.dxf" id="file_{$name}" {$requiredAttr} style="font-size:11px; flex:1;" onchange="document.getElementById('chk_{$name}').required = false;">
                <label style="font-size:11px; color:#475569; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" name="included_in_other[{$name}]" id="chk_{$name}" value="1" onchange="document.getElementById('file_{$name}').required = !this.checked;"> 他ファイルに記載
                </label>
            </div>
            <!-- 差し替え理由 -->
            <input type="text" name="update_reason[{$name}]" placeholder="※差し替え時のみ、変更内容を入力してください" style="width:100%; font-size:11px; padding:4px; margin-top:5px; display:none;" class="update-reason-input">
        </div>
HTML;
        return $html;
    }
}

$is_common = ($project_info['req_permit'] || $project_info['req_wall'] || (!($project_info['req_permit']||$project_info['req_wall']||$project_info['req_skin']||$project_info['req_sky'])));
$is_sky = $project_info['req_sky'];
$is_skin = $project_info['req_skin'];
?>

<div id="upload_slots_container">

    <?php if ($is_common): ?>
    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f8fafc;">
        <strong style="display:block; margin-bottom:10px; color:#1e40af;">【共通図書（意匠・構造計算等）】</strong>
        <?= renderUploadSlot('配置図', 'cad_layout') ?>
        <?= renderUploadSlot('1F平面図', 'cad_plan_1f') ?>
        <?= renderUploadSlot('2F平面図', 'cad_plan_2f') ?>
        <?= renderUploadSlot('3F平面図', 'cad_plan_3f', false) ?>
        <?= renderUploadSlot('PH平面図', 'cad_plan_ph', false) ?>
        <?= renderUploadSlot('RF平面図', 'cad_plan_rf', false) ?>
        <?= renderUploadSlot('立面図 (一式または各面)', 'cad_elevation') ?>
        <?= renderUploadSlot('矩計図', 'cad_section') ?>
        <?= renderUploadSlot('確認申請書（2面〜5面）', 'app_doc') ?>
        <?= renderUploadSlot('地盤調査報告書', 'soil_report') ?>
        <?= renderUploadSlot('地盤改良設計書', 'soil_impr', false) ?>
    </div>

    <!-- 仕様入力フォーム (PDFではなくフォーム入力) -->
    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f0fdf4;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <strong style="color:#166534;">【構造材種・金物・耐力壁の指定】</strong>
            <label style="font-size:11px; background:#fff; border:1px solid #ccc; padding:2px 6px; border-radius:4px; cursor:pointer;">
                <input type="checkbox" id="copy_past_specs" onclick="alert('過去の案件からデータをロードする機能は準備中です')"> 過去の案件と同じ
            </label>
        </div>
        <div style="font-size:11px; margin-bottom:10px; color:#666;">※PDFの代わりにこちらのフォームにご入力ください。</div>
        
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px;">
            <div><label>土台</label><input type="text" name="spec_dodai" style="width:100%; padding:3px;"></div>
            <div><label>大引</label><input type="text" name="spec_obiki" style="width:100%; padding:3px;"></div>
            <div><label>柱</label><input type="text" name="spec_hashira" style="width:100%; padding:3px;"></div>
            <div><label>梁</label><input type="text" name="spec_hari" style="width:100%; padding:3px;"></div>
            <div><label>小屋束・母屋・棟木・垂木</label><input type="text" name="spec_koya" style="width:100%; padding:3px;"></div>
            <div><label>金物指定</label><input type="text" name="spec_kanamono" placeholder="Z金物、Tec-One等" style="width:100%; padding:3px;"></div>
            <div style="grid-column: 1 / -1;"><label>耐力壁仕様</label><input type="text" name="spec_wall" placeholder="告示、EXハイパー等" style="width:100%; padding:3px;"></div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($is_sky): ?>
    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f8fafc;">
        <strong style="display:block; margin-bottom:10px; color:#1e40af;">【天空率計算 図書】</strong>
        <?= renderUploadSlot('道路の資料（座標、測量図、レベル等）', 'road_data') ?>
        <?= renderUploadSlot('真北の資料（真北測量図等）', 'true_north') ?>
    </div>
    <?php endif; ?>
    
    <?php if ($is_skin): ?>
    <div style="margin-bottom:15px; border:1px solid #ccc; padding:10px; border-radius:6px; background:#f8fafc;">
        <strong style="display:block; margin-bottom:10px; color:#1e40af;">【外皮計算 図書】</strong>
        <?= renderUploadSlot('仕様書', 'spec_doc') ?>
        <?= renderUploadSlot('断熱材資料（屋根、天井、外壁、基礎）', 'insulation_data') ?>
        <?= renderUploadSlot('サッシ・玄関ドア仕様', 'sash_data') ?>
        <?= renderUploadSlot('24時間換気計算図書', 'ventilation_data') ?>
        <?= renderUploadSlot('設備機器カタログ（エコキュート、証明等）', 'equip_data') ?>
    </div>
    <?php endif; ?>

</div>
