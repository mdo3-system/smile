<?php
// components/col_estimator.php

$saved_inputs = [];
if (!empty($all_estimates)) {
    $latest_est = $all_estimates[0];
    if (!empty($latest_est['inputs_json'])) {
        $saved_inputs = json_decode($latest_est['inputs_json'], true) ?: [];
    }
}
?>
<?php if ($is_admin): ?>
<!-- 管理者専用エリア -->
<div style="margin-top: 0; padding-top: 15px;">
    <div style="font-size:11px; font-weight:bold; color:#c0392b; margin-bottom:10px;">🔒 以下は管理者のみに表示されます</div>
    
    <div class="box" style="background:#e8f5e9; font-size:11px; display:flex; flex-direction:column; gap:10px; border: 2px solid #28a745;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #c8e6c9; padding-bottom:10px; margin-bottom:5px;">
            <h2 class="section-title" style="background:#28a745; margin:0; width:auto; display:inline-block; padding:5px 10px;">💰 自動見積シミュレーター</h2>
            <div style="display:flex; align-items:center; gap:10px; background:#fff; border:1px solid #28a745; padding:4px 10px; border-radius:5px; font-size:11px;">
                <strong>📂 Drive連携:</strong>
                <?php 
                require_once __DIR__ . '/../google_drive_client.php';
                $is_service_account = false;
                $cred_path = __DIR__ . '/../credentials.json';
                if (file_exists($cred_path)) {
                    $cred_data = json_decode(file_get_contents($cred_path), true);
                    if (is_array($cred_data) && isset($cred_data['type']) && $cred_data['type'] === 'service_account') {
                        $is_service_account = true;
                    }
                }
                
                $drive_connected = false;
                if (!$is_service_account) {
                    $drive_connected = check_google_drive_connection();
                }
                ?>
                <?php if ($is_service_account): ?>
                    <span style="color:#28a745; font-weight:bold;">🟢 サービスアカウント</span>
                <?php elseif ($drive_connected): ?>
                    <span style="color:#28a745; font-weight:bold;">🟢 完了 (OAuth)</span>
                <?php else: ?>
                    <span style="color:#dc3545; font-weight:bold;">🔴 連携エラー (再ログインが必要)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 計算タイプの選択 -->
        <div>
            <strong>計算タイプ（複数選択可）</strong><br>
            <?php
            $chk_permit = isset($saved_inputs['est_active_permit']) ? $saved_inputs['est_active_permit'] : ($project_info['req_permit'] == 1);
            $chk_wall = isset($saved_inputs['est_active_wall']) ? $saved_inputs['est_active_wall'] : ($project_info['req_wall'] == 1);
            $chk_skin = isset($saved_inputs['est_active_skin']) ? $saved_inputs['est_active_skin'] : ($project_info['req_skin'] == 1);
            $chk_sky = isset($saved_inputs['est_active_sky']) ? $saved_inputs['est_active_sky'] : ($project_info['req_sky'] == 1);
            ?>
            <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_permit" onchange="toggleEstContainers(); calcClientEstimate();" <?= $chk_permit ? 'checked' : '' ?>> 許容応力度計算</label>
            <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_wall" onchange="toggleEstContainers(); calcClientEstimate();" <?= $chk_wall ? 'checked' : '' ?>> 性能表示壁量計算（性能表示のみ）</label>
            <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_skin" onchange="toggleEstContainers(); calcClientEstimate();" <?= $chk_skin ? 'checked' : '' ?>> 外皮計算（一次エネ計算セット）</label>
            <label style="display:block; margin:2px 0;"><input type="checkbox" id="est_active_sky" onchange="toggleEstContainers(); calcClientEstimate();" <?= $chk_sky ? 'checked' : '' ?>> 天空率計算</label>
        </div>

        <!-- 1. 許容応力度計算用フォーム -->
        <div id="container_permit" class="box" style="background:#ffffff; border:1px solid #ccc; display:<?= $chk_permit ? 'block' : 'none' ?>; padding:8px; margin:0;">
            <strong style="color:#2e7d32;">【許容応力度計算オプション】</strong>
            <div style="margin-top:5px; display:grid; gap:6px;">
                <div>
                    基本料金<br>
                    <?php $val_base_permit = $saved_inputs['est_base_permit'] ?? '75000'; ?>
                    <select id="est_base_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                        <option value="75000" <?= $val_base_permit == '75000' ? 'selected' : '' ?>>平屋建・2階建 (75,000円)</option>
                        <option value="100000" <?= $val_base_permit == '100000' ? 'selected' : '' ?>>3階建 (100,000円)</option>
                    </select>
                </div>
                <div>
                    構造床面積 (㎡) <span style="color:#666;">*150㎡超は600円/㎡加算</span><br>
                    <input type="number" id="est_area_permit" value="<?= htmlspecialchars($saved_inputs['est_area_permit'] ?? '100', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                </div>
                <div>
                    目標等級加算<br>
                    <?php $val_grade_permit = $saved_inputs['est_grade_permit'] ?? '0'; ?>
                    <select id="est_grade_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                        <option value="0" <?= $val_grade_permit == '0' ? 'selected' : '' ?>>なし (0円)</option>
                        <option value="40000" <?= $val_grade_permit == '40000' ? 'selected' : '' ?>>耐震等級3+耐風等級2 (+40,000円)</option>
                        <option value="20000" <?= $val_grade_permit == '20000' ? 'selected' : '' ?>>耐震等級2 (+20,000円)</option>
                        <option value="40000" <?= $val_grade_permit == '40000' ? 'selected' : '' ?>>耐震等級3 (+40,000円)</option>
                    </select>
                </div>
                <div>
                    形状・仕様加算（基本料金+面積割増に乗算）<br>
                    <label><input type="checkbox" id="est_mult_permit_2" class="est_mult_permit" value="0.2" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_permit_2'] ?? false) ? 'checked' : '' ?>> PH階がある (+20%)</label><br>
                    <label><input type="checkbox" id="est_mult_permit_3" class="est_mult_permit" value="0.1" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_permit_3'] ?? false) ? 'checked' : '' ?>> 小屋裏収納がある (+10%)</label><br>
                    <label><input type="checkbox" id="est_mult_permit_4" class="est_mult_permit" value="0.1" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_permit_4'] ?? false) ? 'checked' : '' ?>> スキップ等レベル違い (+10%)</label><br>
                    <label><input type="checkbox" id="est_mult_permit_5" class="est_mult_permit" value="1.0" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_permit_5'] ?? false) ? 'checked' : '' ?>> 平面不整形 (+100%)</label><br>
                    <label><input type="checkbox" id="est_mult_permit_6" class="est_mult_permit" value="1.0" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_permit_6'] ?? false) ? 'checked' : '' ?>> 立面不整形 (+100%)</label>
                </div>
                <div>
                    その他加算（固定額）<br>
                    <label>金物工法階数: <input type="number" id="est_kanamono_permit" value="<?= htmlspecialchars($saved_inputs['est_kanamono_permit'] ?? '0', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 階 (+15,000円/階)</label><br>
                    <div style="margin: 3px 0;">
                        人通口補強計算:<br>
                        <?php $val_jintsu_permit = $saved_inputs['est_jintsu_permit'] ?? '0'; ?>
                        <select id="est_jintsu_permit" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                            <option value="0" <?= $val_jintsu_permit == '0' ? 'selected' : '' ?>>なし (0円)</option>
                            <option value="5000" <?= $val_jintsu_permit == '5000' ? 'selected' : '' ?>>10箇所未満 (+5,000円)</option>
                            <option value="10000" <?= $val_jintsu_permit == '10000' ? 'selected' : '' ?>>10箇所以上 (+10,000円)</option>
                        </select>
                    </div>
                    <label>母屋下がり箇所数: <input type="number" id="est_moya_permit" value="<?= htmlspecialchars($saved_inputs['est_moya_permit'] ?? '0', ENT_QUOTES) ?>" min="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label><br>
                    <label>斜め壁等（耐力壁なし）箇所数: <input type="number" id="est_slant_wall_no_bearing" value="<?= htmlspecialchars($saved_inputs['est_slant_wall_no_bearing'] ?? '0', ENT_QUOTES) ?>" min="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label><br>
                    <label>斜め壁等（耐力壁あり）箇所数: <input type="number" id="est_slant_wall_bearing" value="<?= htmlspecialchars($saved_inputs['est_slant_wall_bearing'] ?? '0', ENT_QUOTES) ?>" min="0" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+30,000円/箇所)</label>
                </div>
            </div>
        </div>

        <!-- 2. 性能表示壁量計算用フォーム -->
        <div id="container_wall" class="box" style="background:#ffffff; border:1px solid #ccc; display:<?= $chk_wall ? 'block' : 'none' ?>; padding:8px; margin:0;">
            <strong style="color:#c0392b;">【性能表示壁量計算オプション】</strong>
            <div style="margin-top:5px; display:grid; gap:6px;">
                <div>
                    基本料金<br>
                    <?php $val_base_wall = $saved_inputs['est_base_wall'] ?? '50000'; ?>
                    <select id="est_base_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                        <option value="50000" <?= $val_base_wall == '50000' ? 'selected' : '' ?>>（性能表示）壁量計算 (50,000円)</option>
                    </select>
                </div>
                <div>
                    構造床面積 (㎡) <span style="color:#666;">*150㎡超は500円/㎡加算</span><br>
                    <input type="number" id="est_area_wall" value="<?= htmlspecialchars($saved_inputs['est_area_wall'] ?? '100', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                </div>
                <div>
                    構造図（基礎伏図）作成<br>
                    <?php $val_dwg_wall = $saved_inputs['est_dwg_wall'] ?? '0'; ?>
                    <select id="est_dwg_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                        <option value="0" <?= $val_dwg_wall == '0' ? 'selected' : '' ?>>依頼なし (0円)</option>
                        <option value="15000" <?= $val_dwg_wall == '15000' ? 'selected' : '' ?>>建築面積 50㎡未満 (+15,000円)</option>
                        <option value="20000" <?= $val_dwg_wall == '20000' ? 'selected' : '' ?>>建築面積 100㎡未満 (+20,000円)</option>
                        <option value="25000" <?= $val_dwg_wall == '25000' ? 'selected' : '' ?>>建築面積 150㎡未満 (+25,000円)</option>
                        <option value="30000" <?= $val_dwg_wall == '30000' ? 'selected' : '' ?>>建築面積 150㎡以上 (+30,000円)</option>
                    </select>
                </div>
                <div>
                    人通孔箇所数割増<br>
                    <?php $val_jintsu_wall = $saved_inputs['est_jintsu_wall'] ?? '0'; ?>
                    <select id="est_jintsu_wall" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                        <option value="0" <?= $val_jintsu_wall == '0' ? 'selected' : '' ?>>割増なし (0円)</option>
                        <option value="5000" <?= $val_jintsu_wall == '5000' ? 'selected' : '' ?>>10箇所未満 (+5,000円)</option>
                        <option value="10000" <?= $val_jintsu_wall == '10000' ? 'selected' : '' ?>>10箇所以上 (+10,000円)</option>
                    </select>
                </div>
                <div>
                    基礎梁許容応力度計算<br>
                    <label><input type="checkbox" id="est_kisohari_wall" onchange="calcClientEstimate()" <?= ($saved_inputs['est_kisohari_wall'] ?? false) ? 'checked' : '' ?>> 依頼する (+20,000円、※150㎡超は500円/㎡加算)</label>
                </div>
                <div>
                    形状加算（基本料金+面積割増に乗算）<br>
                    <label><input type="checkbox" id="est_mult_wall_1" class="est_mult_wall" value="0.2" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_wall_1'] ?? false) ? 'checked' : '' ?>> PH階がある (+20%)</label><br>
                    <label><input type="checkbox" id="est_mult_wall_2" class="est_mult_wall" value="0.1" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_wall_2'] ?? false) ? 'checked' : '' ?>> 小屋裏収納がある (+10%)</label><br>
                    <label><input type="checkbox" id="est_mult_wall_3" class="est_mult_wall" value="0.1" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_wall_3'] ?? false) ? 'checked' : '' ?>> スキップレベル違いがある (+10%)</label>
                </div>
                <div>
                    形状加算（箇所数×単価）<br>
                    <label>母屋下がり箇所数: <input type="number" id="est_moya_wall" value="<?= htmlspecialchars($saved_inputs['est_moya_wall'] ?? '0', ENT_QUOTES) ?>" min="0" onchange="calcClientEstimate()" style="width:50px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label>
                </div>
            </div>
        </div>

        <!-- 3. 外皮計算用フォーム -->
        <div id="container_skin" class="box" style="background:#ffffff; border:1px solid #ccc; display:<?= $chk_skin ? 'block' : 'none' ?>; padding:8px; margin:0;">
            <strong style="color:#d35400;">【外皮計算オプション】</strong>
            <div style="margin-top:5px; display:grid; gap:6px;">
                <div>
                    基本料金<br>
                    <?php $val_base_skin = $saved_inputs['est_base_skin'] ?? '20000'; ?>
                    <select id="est_base_skin" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                        <option value="20000" <?= $val_base_skin == '20000' ? 'selected' : '' ?>>平屋建 (20,000円)</option>
                        <option value="35000" <?= $val_base_skin == '35000' ? 'selected' : '' ?>>2階建 (35,000円)</option>
                        <option value="50000" <?= $val_base_skin == '50000' ? 'selected' : '' ?>>3階建 (50,000円)</option>
                    </select>
                </div>
                <div>
                    外皮床面積 (㎡) <span style="color:#666;">*100㎡超は500円/㎡加算</span><br>
                    <input type="number" id="est_area_skin" value="<?= htmlspecialchars($saved_inputs['est_area_skin'] ?? '100', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                </div>
                <div>
                    形状加算（基本料金+面積割増に乗算）<br>
                    <label><input type="checkbox" id="est_mult_skin_1" class="est_mult_skin" value="0.2" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_skin_1'] ?? false) ? 'checked' : '' ?>> PH階がある (+20%)</label><br>
                    <label><input type="checkbox" id="est_mult_skin_2" class="est_mult_skin" value="0.1" onchange="calcClientEstimate()" <?= ($saved_inputs['est_mult_skin_2'] ?? false) ? 'checked' : '' ?>> スキップレベル違いがある (+10%)</label>
                </div>
                <div>
                    その他加算（固定額）<br>
                    <label>基礎立上り400超箇所数: <input type="number" id="est_kisotachi_skin" value="<?= htmlspecialchars($saved_inputs['est_kisotachi_skin'] ?? '0', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 箇所 (+15,000円/箇所)</label><br>
                    <label><input type="checkbox" id="est_setsumei_skin" onchange="calcClientEstimate()" <?= ($saved_inputs['est_setsumei_skin'] ?? false) ? 'checked' : '' ?>> 設計内容説明書を作成する (+15,000円)</label><br>
                    <label><input type="checkbox" id="est_energy_skin" checked disabled> 一次消費エネルギー計算書 (+15,000円 ※セット)</label>
                </div>
            </div>
        </div>

        <!-- 4. 天空率用フォーム -->
        <div id="container_sky" class="box" style="background:#ffffff; border:1px solid #ccc; display:<?= $chk_sky ? 'block' : 'none' ?>; padding:8px; margin:0;">
            <strong style="color:#2980b9;">【天空率計算オプション】</strong>
            <div style="margin-top:5px; display:grid; gap:6px;">
                <div>
                    対象斜線<br>
                    <label><input type="checkbox" id="est_road_sky" onchange="calcClientEstimate()" <?= isset($saved_inputs['est_road_sky']) ? ($saved_inputs['est_road_sky'] ? 'checked' : '') : 'checked' ?>> 道路斜線天空率 (50,000円)</label><br>
                    <label><input type="checkbox" id="est_north_sky" onchange="calcClientEstimate()" <?= ($saved_inputs['est_north_sky'] ?? false) ? 'checked' : '' ?>> 北側斜線天空率 (50,000円)</label>
                </div>
                <div>
                    追加検討斜線面数<br>
                    <label>追加面数: <input type="number" id="est_extra_sky" value="<?= htmlspecialchars($saved_inputs['est_extra_sky'] ?? '0', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:40px; font-size:11px; padding:2px;"> 面 (+25,000円/面、※1面目は基本料金に含む)</label>
                </div>
                <div>
                    敷地面積 (㎡) <span style="color:#666;">*150㎡超は200円/㎡加算</span><br>
                    <input type="number" id="est_site_area_sky" value="<?= htmlspecialchars($saved_inputs['est_site_area_sky'] ?? '100', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                </div>
                <div>
                    建物床面積 (㎡) <span style="color:#666;">*150㎡超は200円/㎡加算</span><br>
                    <input type="number" id="est_building_area_sky" value="<?= htmlspecialchars($saved_inputs['est_building_area_sky'] ?? '100', ENT_QUOTES) ?>" onchange="calcClientEstimate()" style="width:100%; font-size:11px; padding:2px;">
                </div>
                <div>
                    詳細モデル検討加算<br>
                    <label><input type="checkbox" id="est_detail_sky" onchange="calcClientEstimate()" <?= ($saved_inputs['est_detail_sky'] ?? false) ? 'checked' : '' ?>> 建物の詳細モデルによる検討を行う (+15,000円)</label>
                </div>
            </div>
        </div>

        <!-- 5. 手動見積明細追加エリア（動的追加） -->
        <div class="box" style="background:#fff3cd; border:1px solid #ffeeba; padding:8px; margin:0; display:block;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                <strong style="color:#b58900;">➕ 手動追加明細</strong>
                <button type="button" onclick="addManualEstimateRow()" style="background:#b58900; color:white; border:none; padding:2px 8px; border-radius:4px; font-size:10px; cursor:pointer; font-weight:bold;">追加</button>
            </div>
            <div id="manual_estimates_container">
                <?php
                $manual_items = $saved_inputs['manual_items'] ?? [];
                if (!empty($manual_items)) {
                    foreach ($manual_items as $item) {
                        $m_name = htmlspecialchars($item['name'], ENT_QUOTES);
                        $m_price = intval($item['price']);
                        echo '
                        <div class="manual-est-row" style="display:flex; gap:5px; margin-bottom:5px; align-items:center;">
                            <input type="text" placeholder="項目名" class="manual-est-name" value="' . $m_name . '" oninput="calcClientEstimate()" style="flex:1; padding:3px; font-size:11px; ime-mode: active;" inputmode="text" required>
                            <input type="number" placeholder="金額(税抜)" class="manual-est-price" value="' . $m_price . '" oninput="calcClientEstimate()" style="width:80px; padding:3px; font-size:11px; ime-mode: disabled;" inputmode="numeric" required>
                            <button type="button" onclick="this.parentElement.remove(); calcClientEstimate();" style="background:#ef4444; color:white; border:none; padding:2px 5px; border-radius:3px; cursor:pointer; font-weight:bold;">✕</button>
                        </div>';
                    }
                } else if (!empty($all_estimates)) {
                    $latest_est = $all_estimates[0];
                    $est_note = json_decode($latest_est['note'] ?? '[]', true) ?: [];
                    
                    // 基本となる既定の項目名
                    $default_names = [
                        "許容応力度計算 基本料金",
                        "許容応力度計算 構造床面積割増",
                        "目標等級加算",
                        "PH階がある",
                        "小屋裏収納がある",
                        "スキップ等レベル違い",
                        "平面不整形",
                        "立面不整形",
                        "金物工法割増",
                        "許容応力度計算 人通口補強計算",
                        "許容応力度計算 母屋下がり加算",
                        "許容応力度計算 斜め壁等（耐力壁なし）",
                        "許容応力度計算 斜め壁等（耐力壁あり）",
                        "性能表示壁量計算 基本料金",
                        "性能表示壁量計算 構造床面積割増",
                        "性能表示壁量計算 PH階がある",
                        "性能表示壁量計算 小屋裏収納がある",
                        "性能表示壁量計算 スキップレベル違い",
                        "性能表示壁量計算 構造図",
                        "性能表示壁量計算 人通孔箇所数割増",
                        "性能表示壁量計算 母屋下がり加算",
                        "性能表示壁量計算 基礎梁許容応力度",
                        "外皮計算 基本料金",
                        "外皮計算 外皮床面積割増",
                        "外皮計算 PH階がある",
                        "外皮計算 スキップレベル違い",
                        "外皮計算 基礎立上り400超割増",
                        "外皮計算 設計内容説明書",
                        "一次消費エネルギー量計算",
                        "天空率 道路斜線",
                        "天空率 北側斜線",
                        "天空率 追加斜線面検討",
                        "天空率 敷地面積割増",
                        "天空率 建物床面積割増",
                        "天空率 詳細モデル検討"
                    ];
                    
                    foreach ($est_note as $item) {
                        $is_default = false;
                        foreach ($default_names as $def) {
                            if (mb_strpos($item['name'], $def) !== false) {
                                $is_default = true;
                                break;
                            }
                        }
                        // デフォルト以外の有効な手動明細
                        if (!$is_default && !empty($item['is_active']) && !empty($item['amount'])) {
                            $m_name = htmlspecialchars($item['name'], ENT_QUOTES);
                            $m_price = intval($item['price']);
                            echo '
                            <div class="manual-est-row" style="display:flex; gap:5px; margin-bottom:5px; align-items:center;">
                                <input type="text" placeholder="項目名" class="manual-est-name" value="' . $m_name . '" oninput="calcClientEstimate()" style="flex:1; padding:3px; font-size:11px; ime-mode: active;" inputmode="text" required>
                                <input type="number" placeholder="金額(税抜)" class="manual-est-price" value="' . $m_price . '" oninput="calcClientEstimate()" style="width:80px; padding:3px; font-size:11px; ime-mode: disabled;" inputmode="numeric" required>
                                <button type="button" onclick="this.parentElement.remove(); calcClientEstimate();" style="background:#ef4444; color:white; border:none; padding:2px 5px; border-radius:3px; cursor:pointer; font-weight:bold;">✕</button>
                            </div>';
                        }
                    }
                }
                ?>
            </div>
        </div>

        <!-- 計算結果表示 -->
        <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
            見積合計 (税抜): <span id="est_total_disp" style="color:#d32f2f; font-size:12px;">0</span> 円<br>
            消費税 (10%): <span id="est_tax_disp" style="color:#555; font-size:11px;">0</span> 円<br>
            税込合計: <span id="est_grand_total_disp" style="color:#28a745; font-size:12px;">0</span> 円
        </div>

        <div style="margin-top:10px; display:flex; gap:10px; flex-direction:column;">
            <div style="display:flex; gap:5px; flex-wrap:wrap;">
                <button type="button" onclick="calcClientEstimate()" style="flex:1; min-width:80px; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">再計算</button>
                <button type="button" id="pdf_issue_btn" onclick="saveAndPrintEstimate(false)" style="flex:1; min-width:110px; background:#ff9800; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">初回見積PDFを発行</button>
                <button type="button" id="formal_pdf_issue_btn" onclick="saveAndPrintEstimate(true)" style="flex:1; min-width:150px; background:#dc3545; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">本見積として確定・PDF発行</button>
                <button type="button" id="additional_pdf_issue_btn" onclick="saveAndPrintEstimate(false, true)" style="flex:1; min-width:150px; background:#ea580c; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">追加見積として確定・PDF発行</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
