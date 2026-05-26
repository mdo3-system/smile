<?php
// functions.php

// ==========================================
// 1. 各種マスターデータ定義（プルダウンの選択肢等）
// ==========================================
$status_options = [
    'quote_req' => '見積依頼', 'contracted' => '受注済', 'primary_prep' => '一次回答準備中', 
    'structural_dwg' => '構造図作成中', 'submission' => '提出済・確認中', 'correction' => '補正対応中', 'completed' => '完了'
];

$wood_opts = ['ヒノキKD', 'ﾍﾞｲﾂｶﾞKD', 'スギKD', 'ﾍﾞｲﾏﾂKD', 'ｽﾌﾟﾙｰｽKD', 'WWKD', 'E65-F255', 'E95-F315', 'E105-F300', 'E95-F285', 'その他'];
$sz_main = ['□105', '□120', 'その他']; 
$sz_obiki_opts = ['□90', '□105', '□120', 'その他']; 
$sz_sub = ['□90', '□105', '□120', 'その他']; 
$sz_taruki_opts = ['45×45', '45×60', 'その他'];
$menzai_opts = ['構造用合板', '構造用MDF', '構造用パーティクルボード', 'その他']; 
$suichi_opts = ['30×90', '45×90', '90×90', '使用不可']; 
$hardware_opts = ['Z金物', 'その他'];

// 図書カテゴリの目的別細分化定義
$money_categories = [
    'est_initial' => '着手時 お見積書', 'est_post' => '着手後 御見積書', 'est_add' => '追加 お見積書', 'inv_primary' => '一次回答 請求書', 'inv_final' => '最終 御請求書'
];
$file_categories_left_pdf = [
    'pdf_plan' => '見積用PDF (平面図)', 'pdf_elevation' => '見積用PDF (立面図)', 'pdf_layout' => '見積用PDF (配置図)', 'pdf_section' => '見積用PDF (矩計図 ※必要時)'
];
$file_categories_left_cad = [
    'cad_plan' => '意匠CAD (平面図 ※一括データも可)', 'cad_elevation' => '意匠CAD (立面図)', 'cad_section' => '意匠CAD (矩計図)', 'cad_layout' => '意匠CAD (配置図)', 'cad_other' => '意匠CAD (追加分)'
];
$file_categories_left_other = [
    'app_doc' => '確認申請書（2〜5面）', 'soil_report' => '地盤調査資料', 'wood_spec' => '構造材種の指定(図書)', 'wall_spec' => '耐力壁仕様の指定(図書)', 'hardware_spec' => '金物の指定(図書)'
];
$file_categories_option = [
    'soil_impr' => '地盤改良設計書 (※該当時のみ)'
];
$file_categories_center = [
    'standard_dwg' => '構造標準図', 'safety_cert' => '安全証明書', 'calc_doc' => '構造計算書', 'structural_dwg' => '構造図一式', 'qa_doc' => '疑義照会・回答書', 'correction_doc' => '補正・指示図書', 'other' => 'その他参考資料'
];


// ==========================================
// 2. スケジュール（営業日・月）計算ロジック
// ==========================================
function addBusinessDays($dateStr, $days) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    $added = 0;
    while ($added < $days) {
        $date->modify('+1 day');
        $dayOfWeek = (int)$date->format('N'); // 1:月 ～ 7:日
        if ($dayOfWeek !== 3 && $dayOfWeek !== 7) { $added++; } // 水曜(3)と日曜(7)をスキップ
    }
    return $date->format('Y-m-d');
}

function addMonths($dateStr, $months) {
    if (!$dateStr) return '';
    $date = new DateTime($dateStr);
    $date->modify("+$months month");
    return $date->format('Y-m-d');
}


// ==========================================
// 3. UI（HTML要素）描画用パーツ関数
// ==========================================
function renderOptions($optionsArray, $currentValue) {
    $sel_empty = ($currentValue === '') ? 'selected' : ''; 
    echo "<option value=\"\" $sel_empty>--- 未選択 ---</option>";
    $is_other = !in_array($currentValue, $optionsArray) && $currentValue !== '';
    foreach ($optionsArray as $opt) { 
        $sel = ($currentValue === $opt || ($is_other && $opt === 'その他')) ? 'selected' : ''; 
        echo "<option value=\"$opt\" $sel>$opt</option>"; 
    }
}

function checkOther($optionsArray, $currentValue) { 
    return (!in_array($currentValue, $optionsArray) && $currentValue !== '') ? htmlspecialchars($currentValue, ENT_QUOTES) : ''; 
}

function renderFileSlot($c_key, $c_label, $latest_files, $project_id) {
    $latest = null; 
    foreach ($latest_files as $lf) { 
        if ($lf['file_category'] === $c_key) { $latest = $lf; break; } 
    }
    echo '<div class="file-slot"><div style="flex: 1;"><div class="file-slot-title">'.$c_label.'</div><div class="file-slot-info">';
    if ($latest) {
        $download_url = htmlspecialchars($latest['drive_file_id'], ENT_QUOTES);
        if (strpos($latest['drive_file_id'], 'uploads/') !== 0 && !empty($latest['drive_file_id'])) {
            $download_url = 'https://drive.google.com/file/d/' . htmlspecialchars($latest['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk';
        }
        echo '<a href="'.$download_url.'" target="_blank" style="text-decoration:none; color:#0056b3; font-weight:bold;">📄 '.htmlspecialchars($latest['file_name'], ENT_QUOTES).' <span class="badge" style="background:#28a745; color:white; margin-left:5px;">V'.$latest['version'].'</span></a>';
    } else { 
        echo '<span style="color:#999; font-size:11px;">未登録</span>'; 
    }
    echo '</div></div><div>';
    echo '<form action="project_detail.php?id='.$project_id.'" method="POST" enctype="multipart/form-data" style="margin:0;">';
    echo '<input type="hidden" name="file_category" value="'.$c_key.'">';
    echo '<input type="file" name="upload_file" onchange="this.form.submit()" style="display:none;" id="btn_f_'.$c_key.'">';
    echo '<button type="button" onclick="document.getElementById(\'btn_f_'.$c_key.'\').click();" class="btn-upload-sm">UP/更新</button>';
    echo '</form></div></div>';
}
?>