<?php
// components/col_specs.php
// 構造仕様指定の表示および編集フォーム

$needs_specs = (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1);
if ($needs_specs):
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
<div class="box" style="margin-top:10px; border-color:#7c3aed; background:#f5f3ff;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ddd; padding-bottom:5px; margin-bottom:8px;">
        <h3 style="margin:0; font-size:14px; color:#5b21b6;">🟣 構造仕様指定（ここで指定するか、依頼主アップロード図書内のプレカット図等にUPしてください）</h3>
        <button id="toggle_specs_edit_btn" onclick="toggleSpecsEdit()" style="background:#7c3aed; color:white; border:none; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer; transition:background 0.2s;">
            ✏️ 編集する
        </button>
    </div>

    <!-- 通常表示モード -->
    <div id="specs_display_mode">
        <table style="width:100%; border-collapse:collapse; font-size:12px; line-height:1.6;">
            <tr><td style="width:70px; font-weight:bold; color:#4b5563; padding:2px 0;">土台:</td><td><?= htmlspecialchars($wood_json['dodai'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">大引:</td><td><?= htmlspecialchars($wood_json['obiki'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">柱:</td><td><?= htmlspecialchars($wood_json['hashira'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">梁:</td><td><?= htmlspecialchars($wood_json['hari'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">小屋束:</td><td><?= htmlspecialchars($wood_json['koya'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">母屋:</td><td><?= htmlspecialchars($wood_json['moya'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">棟木:</td><td><?= htmlspecialchars($wood_json['munagi'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">垂木:</td><td><?= htmlspecialchars($wood_json['taruki'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0; border-top:1px dashed #ddd;">耐力壁:</td><td style="border-top:1px dashed #ddd;"><?= htmlspecialchars($wall_json['type'] ?? '-', ENT_QUOTES) ?></td></tr>
            <tr><td style="font-weight:bold; color:#4b5563; padding:2px 0;">金物:</td><td><?= htmlspecialchars($hw_json['type'] ?? '-', ENT_QUOTES) ?></td></tr>
            <?php if (!empty($project_info['client_notes_extra'])): ?>
            <tr><td colspan="2" style="border-top:1px dashed #ddd; padding-top:5px; font-weight:bold; color:#4b5563;">特記事項:</td></tr>
            <tr><td colspan="2" style="font-size:11px; background:#fff; padding:6px; border:1px solid #ddd; border-radius:4px; margin-top:2px; white-space:pre-wrap;"><?= htmlspecialchars($project_info['client_notes_extra'], ENT_QUOTES) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- 編集フォームモード（初期非表示） -->
    <div id="specs_edit_mode" style="display:none; margin-top:10px; border-top:1px dashed #7c3aed; padding-top:10px;">
        <form method="POST" action="project_detail.php?id=<?= $project_id ?>">
            <input type="hidden" name="action" value="update_specs_detail">
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:11px;">
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">土台</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_dodai_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ﾋﾉｷKD" <?= $dodai_parsed['type'] === 'ﾋﾉｷKD' ? 'selected' : '' ?>>ﾋﾉｷKD</option>
                            <option value="ﾍﾞｲﾏﾂ" <?= $dodai_parsed['type'] === 'ﾍﾞｲﾏﾂ' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂ</option>
                            <option value="ﾍﾞｲﾂｶﾞKD" <?= $dodai_parsed['type'] === 'ﾍﾞｲﾂｶﾞKD' ? 'selected' : '' ?>>ﾍﾞｲﾂｶﾞKD</option>
                            <option value="その他" <?= $dodai_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_dodai_size" value="<?= htmlspecialchars($dodai_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □105" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">大引</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_obiki_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ﾋﾉｷKD" <?= $obiki_parsed['type'] === 'ﾋﾉｷKD' ? 'selected' : '' ?>>ﾋﾉｷKD</option>
                            <option value="ﾍﾞｲﾂｶﾞKD" <?= $obiki_parsed['type'] === 'ﾍﾞｲﾂｶﾞKD' ? 'selected' : '' ?>>ﾍﾞｲﾂｶﾞKD</option>
                            <option value="ｽｷﾞKD" <?= $obiki_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="その他" <?= $obiki_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_obiki_size" value="<?= htmlspecialchars($obiki_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □90" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">柱</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_hashira_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ﾋﾉｷKD" <?= $hashira_parsed['type'] === 'ﾋﾉｷKD' ? 'selected' : '' ?>>ﾋﾉｷKD</option>
                            <option value="ｽｷﾞKD" <?= $hashira_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="ｽｷﾞ集成" <?= $hashira_parsed['type'] === 'ｽｷﾞ集成' ? 'selected' : '' ?>>ｽｷﾞ集成</option>
                            <option value="WW集成" <?= $hashira_parsed['type'] === 'WW集成' ? 'selected' : '' ?>>WW集成</option>
                            <option value="その他" <?= $hashira_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_hashira_size" value="<?= htmlspecialchars($hashira_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □105" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">梁</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_hari_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ﾍﾞｲﾏﾂKD" <?= $hari_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                            <option value="ｽｷﾞKD" <?= $hari_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="ｽｷﾞ集成" <?= $hari_parsed['type'] === 'ｽｷﾞ集成' ? 'selected' : '' ?>>ｽｷﾞ集成</option>
                            <option value="RE集成" <?= $hari_parsed['type'] === 'RE集成' ? 'selected' : '' ?>>RE集成</option>
                            <option value="ﾊｲﾌﾞﾘｯﾄﾞ集成" <?= $hari_parsed['type'] === 'ﾊｲﾌﾞﾘｯﾄﾞ集成' ? 'selected' : '' ?>>ﾊｲﾌﾞﾘｯﾄﾞ集成</option>
                            <option value="その他" <?= $hari_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_hari_size" value="<?= htmlspecialchars($hari_parsed['size'], ENT_QUOTES) ?>" placeholder="例: 105×150" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">小屋束</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_koyatsuka_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ｽｷﾞKD" <?= $koyatsuka_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="ﾍﾞｲﾏﾂKD" <?= $koyatsuka_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                            <option value="その他" <?= $koyatsuka_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_koyatsuka_size" value="<?= htmlspecialchars($koyatsuka_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □90" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">母屋</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_moya_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ｽｷﾞKD" <?= $moya_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="ﾍﾞｲﾏﾂKD" <?= $moya_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                            <option value="その他" <?= $moya_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_moya_size" value="<?= htmlspecialchars($moya_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □90" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">棟木</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_munagi_type" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ｽｷﾞKD" <?= $munagi_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="ﾍﾞｲﾏﾂKD" <?= $munagi_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                            <option value="その他" <?= $munagi_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        <input type="text" name="spec_munagi_size" value="<?= htmlspecialchars($munagi_parsed['size'], ENT_QUOTES) ?>" placeholder="例: □105" style="flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">垂木</label>
                    <div style="display:flex; gap:3px;">
                        <select name="spec_taruki_type" id="left_spec_taruki_type" onchange="toggleLeftTarukiInput(this.value)" style="width:75px; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                            <option value="">- 選択 -</option>
                            <option value="ﾍﾞｲﾏﾂKD" <?= $taruki_parsed['type'] === 'ﾍﾞｲﾏﾂKD' ? 'selected' : '' ?>>ﾍﾞｲﾏﾂKD</option>
                            <option value="ｽｷﾞKD" <?= $taruki_parsed['type'] === 'ｽｷﾞKD' ? 'selected' : '' ?>>ｽｷﾞKD</option>
                            <option value="その他" <?= $taruki_parsed['type'] === 'その他' ? 'selected' : '' ?>>その他</option>
                        </select>
                        
                        <div id="left_taruki_dim_fields" style="display: <?= $taruki_parsed['type'] === 'その他' ? 'none' : 'flex' ?>; gap:1px; align-items:center; flex:1;">
                            <input type="text" name="spec_taruki_w" value="<?= htmlspecialchars($taruki_w, ENT_QUOTES) ?>" placeholder="幅" style="width:20px; padding:3px 1px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:9px;">
                            <span>×</span>
                            <input type="text" name="spec_taruki_h" value="<?= htmlspecialchars($taruki_h, ENT_QUOTES) ?>" placeholder="高" style="width:20px; padding:3px 1px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:9px;">
                            <span>@</span>
                            <input type="text" name="spec_taruki_pitch" value="<?= htmlspecialchars($taruki_pitch, ENT_QUOTES) ?>" placeholder="P" style="width:22px; padding:3px 1px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:9px;">
                        </div>
                        
                        <input type="text" name="spec_taruki_size" id="left_taruki_other_field" value="<?= htmlspecialchars($taruki_size_other, ENT_QUOTES) ?>" placeholder="例: 45×60@364" style="display: <?= $taruki_parsed['type'] === 'その他' ? 'block' : 'none' ?>; flex:1; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px; min-width:0;">
                    </div>
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">金物指定</label>
                    <input type="text" name="spec_kanamono" value="<?= htmlspecialchars($hw_json['type'] ?? '', ENT_QUOTES) ?>" placeholder="例: Z金物等" style="width:100%; padding:3px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:10px;">
                </div>
                <div>
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">地盤調査状況</label>
                    <select name="soil_status" style="width:100%; padding:3px; border:1px solid #ddd; border-radius:4px; font-size:10px;">
                        <option value="未定" <?= ($project_info['soil_status'] ?? '') === '未定' ? 'selected' : '' ?>>未定</option>
                        <option value="調査済み" <?= ($project_info['soil_status'] ?? '') === '調査済み' ? 'selected' : '' ?>>調査済みで報告書をUP</option>
                        <option value="未調査" <?= ($project_info['soil_status'] ?? '') === '未調査' ? 'selected' : '' ?>>未調査</option>
                        <option value="調査予定" <?= ($project_info['soil_status'] ?? '') === '調査予定' ? 'selected' : '' ?>>調査予定</option>
                        <option value="改良あり" <?= ($project_info['soil_status'] ?? '') === '改良あり' ? 'selected' : '' ?>>改良あり</option>
                    </select>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">耐力壁仕様</label>
                    <input type="text" name="spec_wall" value="<?= htmlspecialchars($wall_json['type'] ?? '', ENT_QUOTES) ?>" placeholder="例: 構造用合板 9mm等" style="width:100%; padding:3px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:10px;">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="font-weight:bold; color:#4b5563; display:block; margin-bottom:2px;">特記事項</label>
                    <textarea name="client_notes_extra" rows="2" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:10px; resize:vertical; font-family:inherit;"><?= htmlspecialchars($project_info['client_notes_extra'] ?? '', ENT_QUOTES) ?></textarea>
                </div>
            </div>
            
            <div style="display:flex; gap:6px; margin-top:10px; justify-content:flex-end;">
                <button type="button" onclick="toggleSpecsEdit()" style="background:#e2e8f0; color:#475569; border:none; padding:4px 10px; border-radius:4px; font-size:10px; font-weight:bold; cursor:pointer;">キャンセル</button>
                <button type="submit" style="background:#10b981; color:white; border:none; padding:4px 10px; border-radius:4px; font-size:10px; font-weight:bold; cursor:pointer; box-shadow: 0 1px 3px rgba(16,185,129,0.2);">変更を保存</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSpecsEdit() {
    var disp = document.getElementById('specs_display_mode');
    var edit = document.getElementById('specs_edit_mode');
    var btn = document.getElementById('toggle_specs_edit_btn');
    if (disp && edit && btn) {
        if (disp.style.display === 'none') {
            disp.style.display = 'block';
            edit.style.display = 'none';
            btn.innerText = '✏️ 編集する';
            btn.style.background = '#7c3aed';
        } else {
            disp.style.display = 'none';
            edit.style.display = 'block';
            btn.innerText = '✕ 閉じる';
            btn.style.background = '#6b7280';
        }
    }
}

function toggleLeftTarukiInput(val) {
    var dims = document.getElementById('left_taruki_dim_fields');
    var other = document.getElementById('left_taruki_other_field');
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
