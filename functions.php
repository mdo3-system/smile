<?php
// functions.php
define('SYSTEM_VERSION', 'v1.4.3');


// ==========================================
// 1. 各種マスターデータ定義（プルダウンの選択肢等）
// ==========================================
$status_options = [
    'quote_req' => '見積依頼', 
    'quote_sent' => '見積送付済 / 依頼検討中', 
    'doc_submitted' => '図書提出済 / 確認中', 
    'primary_prep' => '一次回答準備中', 
    'contracted' => '受注済', 
    'structural_dwg' => '構造図作成中', 
    'submission' => '提出済・確認中', 
    'correction' => '補正対応中', 
    'completed' => '完了'
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
    'est_initial' => '初期 御見積書', 'est_post' => '本見積 御見積書', 'est_add' => '追加 御見積書', 'inv_primary' => '一次回答 請求書', 'inv_final' => '最終 御請求書'
];
$file_categories_left_pdf = [
    'pdf_plan' => '見積用PDF (平面図)', 'pdf_elevation' => '見積用PDF (立面図)', 'pdf_layout' => '見積用PDF (配置図)', 'pdf_section' => '見積用PDF (矩計図 ※必要時)'
];
$file_categories_left_cad = [
    'cad_layout' => '配置図',
    'cad_plan_1f' => '1F平面図',
    'cad_plan_2f' => '2F平面図',
    'cad_plan_3f' => '3F平面図',
    'cad_plan_ph' => 'PH平面図',
    'cad_plan_rf' => 'RF平面図',
    'cad_elevation' => '立面図',
    'cad_section' => '矩計図',
    'app_doc' => '確認申請書（2〜5面）',
    'soil_report' => '地盤調査報告書',
    'soil_impr' => '地盤改良設計書',
    'road_data' => '道路の資料（天空率用）',
    'true_north' => '真北の資料（天空率用）',
    'spec_doc' => '仕様書（外皮用）',
    'insulation_data' => '断熱材資料',
    'sash_data' => 'サッシ・玄関ドア仕様',
    'ventilation_data' => '24時間換気計算図書',
    'equip_data' => '設備機器カタログ'
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
// 3. スケジュール共通定義（管理者・依頼主で共用）
// ==========================================

/**
 * 依頼種別に応じた一次回答までの営業日数を返す
 */
function getScheduleBaseDays(array $project_info): int {
    $req_permit      = (int)($project_info['req_permit']      ?? 0);
    $req_wall        = (int)($project_info['req_wall']        ?? 0);
    $req_skin        = (int)($project_info['req_skin']        ?? 0);
    $req_sky         = (int)($project_info['req_sky']         ?? 0);
    $req_opt_kisohari = (int)($project_info['req_opt_kisohari'] ?? 0);

    if ($req_permit || $req_opt_kisohari) return 12;
    if ($req_wall)                         return 7;
    if ($req_skin || $req_sky)             return 10;
    return 12; // デフォルト
}

/**
 * スケジュールステップ定義を返す（FIXED_LOGIC.md §5 準拠）
 * @param int $base_days 一次回答までの営業日数
 */
function getScheduleSteps(int $base_days): array {
    return [
        ['name' => '設計図書の受領',                 'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
        ['name' => '着手基準日 (一次回答)',           'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
        ['name' => '一次回答（構造計算・図面初回提示）', 'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
        ['name' => '一次回答CB',                     'actor' => 'client',   'desc' => '初回提示から4営業日',        'days' => 4,         'type' => 'biz'],
        ['name' => '構造図UP',                       'actor' => 'designer', 'desc' => '一次回答CBから4営業日',      'days' => 4,         'type' => 'biz'],
        ['name' => '構造図CB',                       'actor' => 'client',   'desc' => '構造図UPから4営業日',        'days' => 4,         'type' => 'biz'],
        ['name' => '修正図面UP',                      'actor' => 'designer', 'desc' => 'CB確認から3営業日',          'days' => 3,         'type' => 'biz'],
        ['name' => '申請図書一式UP',                  'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
        ['name' => '質疑・審査待機',                  'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
        ['name' => '補正対応',                        'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
        ['name' => '残金のご精算',                    'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
    ];
}

function getScheduleStepsWall(int $base_days): array {
    return [
        ['name' => '設計図書の受領',         'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
        ['name' => '着手基準日 (一次回答)',   'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
        ['name' => '壁量計算・図面 初回提示', 'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
        ['name' => '壁量計算図CB (内容確認)', 'actor' => 'client',   'desc' => '初回提示から4営業日',        'days' => 4,         'type' => 'biz'],
        ['name' => '申請図書一式UP',          'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
        ['name' => '質疑・審査待機',          'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
        ['name' => '補正対応',                'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
        ['name' => '残金のご精算',            'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
    ];
}

function getScheduleStepsSkin(int $base_days): array {
    return [
        ['name' => '設計図書の受領',         'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
        ['name' => '着手基準日 (一次回答)',   'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
        ['name' => '外皮計算初回提示',        'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
        ['name' => '外皮計算図CB (内容確認)', 'actor' => 'client',   'desc' => '初回提示から4営業日',        'days' => 4,         'type' => 'biz'],
        ['name' => '申請図書一式UP',          'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
        ['name' => '質疑・審査待機',          'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
        ['name' => '補正対応',                'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
        ['name' => '残金のご精算',            'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
    ];
}

function getScheduleStepsSky(int $base_days): array {
    return [
        ['name' => '設計図書の受領',         'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
        ['name' => '着手基準日 (一次回答)',   'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
        ['name' => '天空率初回提示',          'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
        ['name' => '申請図書一式UP',          'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
        ['name' => '質疑・審査待機',          'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
        ['name' => '補正対応',                'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
        ['name' => '残金のご精算',            'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
    ];
}

/**
 * 見積時受領図面のカテゴリ定義
 */
function getEstimatePdfCategories(): array {
    return [
        'pdf_plan'      => '平面図',
        'pdf_elevation' => '立面図',
        'pdf_layout'    => '配置図',
        'pdf_section'   => '矩計図',
        'pdf_area_calc' => '求積図',
    ];
}


// ==========================================
// 3. Email送信ロジック
// ==========================================
function sendSystemEmail($to, $subject, $body) {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    mb_language("Japanese");
    mb_internal_encoding("UTF-8");
    $headers = "From: system@antigravity-jp.net\r\n";
    $headers .= "Reply-To: no-reply@antigravity-jp.net\r\n";
    return mb_send_mail($to, $subject, $body, $headers);
}


// ==========================================
// 4. UI（HTML要素）描画用パーツ関数
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