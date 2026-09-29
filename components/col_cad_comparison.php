<?php
// components/col_cad_comparison.php
// 建築図書（CAD/JWW/PDF）整合性確認・自動照合コンポーネント
// 管理者ダッシュボード 第2カラム（一次回答後アップロード図書の下）に配置

$saved_cad_comparison = $project_info['cad_comparison_json'] ?? null;

// スロットから最新図書ファイルのメタデータを検出
$slot_files = [
    'app' => null,          // 確認申請書
    'kanabakari' => null,   // 矩計図
    'area' => null,         // 面積表 / 求積図
    'elevation' => null     // 立面図
];

// 有効なファイル（他ファイルに記載でない、実ファイルIDが存在するもの）を探索するヘルパー
function getFirstValidFile($files_by_cat, $cats) {
    foreach ($cats as $cat) {
        if (!empty($files_by_cat[$cat])) {
            foreach ($files_by_cat[$cat] as $f) {
                if (!empty($f['drive_file_id']) && $f['file_name'] !== '【他ファイルに記載】') {
                    return $f;
                }
            }
        }
    }
    return null;
}

// 1. 確認申請書
$slot_files['app'] = getFirstValidFile($files_by_cat, ['app_doc']);

// 2. 矩計図
$slot_files['kanabakari'] = getFirstValidFile($files_by_cat, ['cad_section', 'pdf_section', 'cad_design_all', 'all_in_one_zip']);

// 3. 面積表 / 求積図
$slot_files['area'] = getFirstValidFile($files_by_cat, ['pdf_area_calc', 'cad_plan_1f', 'pdf_plan', 'cad_design_all', 'all_in_one_zip']);

// 4. 立面図
$slot_files['elevation'] = getFirstValidFile($files_by_cat, ['cad_elevation', 'pdf_elevation', 'cad_design_all', 'all_in_one_zip']);
?>

<div class="box" id="cad_comparison_box" style="margin-top:15px; background:#f0fdf4; border:2px solid #22c55e; border-radius:6px; padding:12px;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #86efac; padding-bottom:8px; margin-bottom:10px;">
        <div style="display:flex; align-items:center; gap:6px;">
            <span style="font-size:16px;">📐</span>
            <h3 style="margin:0; font-size:14px; font-weight:bold; color:#14532d;">建築図書 整合性確認・自動照合 (CAD/PDF)</h3>
        </div>
        <div id="cad_service_status" style="font-size:11px; padding:3px 8px; border-radius:12px; background:#e2e8f0; color:#475569; font-weight:bold;">
            🔄 サービス確認中...
        </div>
    </div>

    <div style="font-size:11px; color:#4b5563; margin-bottom:10px; line-height:1.5;">
        スロットにアップロードされた最新図書（確認申請書・矩計図・面積表・立面図）から高さ・面積・図枠情報を抽出し、自動照合します。
    </div>

    <!-- サービス未接続時のアラート (初期非表示) -->
    <div id="cad_service_alert" style="display:none; background:#fffbeb; border:1px solid #fde68a; border-radius:4px; padding:8px; margin-bottom:10px; font-size:11px; color:#92400e;">
        ⚠️ <strong>ローカル解析サービスが停止しています</strong><br>
        JWW/DXF/PDFの自動解析を行うには、お手元のPCで <code>start_cad_parser.bat</code> を起動してください。<br>
        <button type="button" onclick="checkCadServiceStatus()" style="margin-top:4px; background:#f59e0b; color:white; border:none; border-radius:3px; padding:2px 8px; font-size:10px; cursor:pointer;">再接続を確認</button>
    </div>

    <!-- スロット検出状態 & ワンクリック照合ボタン -->
    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:10px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-weight:bold; font-size:12px; color:#1e293b;">📥 スロット内の最新提出図書</span>
            <span style="font-size:10px; color:#64748b;">※手動ダウンロード不要で自動取得します</span>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; font-size:11px; margin-bottom:10px;">
            <!-- ① 確認申請書 -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:6px;">
                <div style="font-weight:600; color:#334155; margin-bottom:2px;">① 確認申請書</div>
                <?php if ($slot_files['app']): ?>
                    <div style="color:#059669; font-weight:bold; word-break:break-all;">
                        📄 <?= htmlspecialchars($slot_files['app']['file_name']) ?> <span style="font-size:9px; background:#dcfce7; color:#15803d; padding:1px 4px; border-radius:2px;">V<?= $slot_files['app']['version'] ?></span>
                    </div>
                <?php else: ?>
                    <div style="color:#94a3b8;">未提出</div>
                <?php endif; ?>
            </div>

            <!-- ② 矩計図 -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:6px;">
                <div style="font-weight:600; color:#334155; margin-bottom:2px;">② 矩計図 (かなばかり)</div>
                <?php if ($slot_files['kanabakari']): ?>
                    <div style="color:#059669; font-weight:bold; word-break:break-all;">
                        📐 <?= htmlspecialchars($slot_files['kanabakari']['file_name']) ?> <span style="font-size:9px; background:#dcfce7; color:#15803d; padding:1px 4px; border-radius:2px;">V<?= $slot_files['kanabakari']['version'] ?></span>
                    </div>
                <?php else: ?>
                    <div style="color:#94a3b8;">未提出</div>
                <?php endif; ?>
            </div>

            <!-- ③ 面積表 -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:6px;">
                <div style="font-weight:600; color:#334155; margin-bottom:2px;">③ 面積表 / 求積図</div>
                <?php if ($slot_files['area']): ?>
                    <div style="color:#059669; font-weight:bold; word-break:break-all;">
                        📊 <?= htmlspecialchars($slot_files['area']['file_name']) ?> <span style="font-size:9px; background:#dcfce7; color:#15803d; padding:1px 4px; border-radius:2px;">V<?= $slot_files['area']['version'] ?></span>
                    </div>
                <?php else: ?>
                    <div style="color:#94a3b8;">未提出</div>
                <?php endif; ?>
            </div>

            <!-- ④ 立面図 -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:6px;">
                <div style="font-weight:600; color:#334155; margin-bottom:2px;">④ 立面図</div>
                <?php if ($slot_files['elevation']): ?>
                    <div style="color:#059669; font-weight:bold; word-break:break-all;">
                        🏢 <?= htmlspecialchars($slot_files['elevation']['file_name']) ?> <span style="font-size:9px; background:#dcfce7; color:#15803d; padding:1px 4px; border-radius:2px;">V<?= $slot_files['elevation']['version'] ?></span>
                    </div>
                <?php else: ?>
                    <div style="color:#94a3b8;">未提出</div>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; border-top:1px solid #f1f5f9; padding-top:8px;">
            <button type="button" id="btn_run_slot_compare" onclick="runSlotAutoComparison()" style="background:#16a34a; hover:background:#15803d; color:white; border:none; border-radius:4px; padding:7px 16px; font-size:12px; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:5px; box-shadow:0 1px 3px rgba(0,0,0,0.12);">
                <span>✨ 提出済み最新図書を一括自動取得して照合</span>
            </button>
            <button type="button" id="btn_export_cad_pdf" onclick="exportCadComparisonPdf()" style="background:#2563eb; color:white; border:none; border-radius:4px; padding:7px 12px; font-size:11px; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:4px;">
                <span>📄 A4照合票を出力</span>
            </button>
        </div>

        <div id="cad_compare_progress" style="display:none; font-size:11px; color:#2563eb; font-weight:bold; margin-top:8px;">
            ⏳ スロット図書を取得し、ローカル解析を実行しています (JWW変換・テキスト抽出中)...
        </div>

        <!-- 手動アップロード照合用トグル (アコーディオン) -->
        <div style="margin-top:10px; border-top:1px dashed #cbd5e1; padding-top:6px;">
            <details style="font-size:11px;">
                <summary style="cursor:pointer; color:#475569; font-weight:600;">📁 手動でファイルを選択してテスト照合する場合（クリックで展開）</summary>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px; margin-top:6px; background:#f8fafc; padding:8px; border-radius:4px;">
                    <div>
                        <label style="color:#475569; display:block;">① 申請書 (PDF):</label>
                        <input type="file" id="cad_file_app" accept=".pdf" style="font-size:10px; width:100%;">
                    </div>
                    <div>
                        <label style="color:#475569; display:block;">② 矩計図 (PDF/DXF/JWW):</label>
                        <input type="file" id="cad_file_kana" accept=".pdf,.dxf,.jww" style="font-size:10px; width:100%;">
                    </div>
                    <div>
                        <label style="color:#475569; display:block;">③ 面積表 (PDF/DXF/JWW):</label>
                        <input type="file" id="cad_file_area" accept=".pdf,.dxf,.jww" style="font-size:10px; width:100%;">
                    </div>
                    <div>
                        <label style="color:#475569; display:block;">④ 立面図 (PDF/DXF/JWW):</label>
                        <input type="file" id="cad_file_elev" accept=".pdf,.dxf,.jww" style="font-size:10px; width:100%;">
                    </div>
                    <div style="grid-column: span 2; text-align:right; margin-top:4px;">
                        <button type="button" onclick="runManualCadComparison()" style="background:#475569; color:white; border:none; border-radius:3px; padding:4px 10px; font-size:10px; cursor:pointer;">選択したファイルで照合</button>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- 照合結果 印刷用 A4 帳票コンテナ -->
    <div id="cad_comparison_report" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:4px; padding:12px; font-size:11px;">
        <div style="border-bottom:2px solid #334155; padding-bottom:4px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:flex-end;">
            <div>
                <h4 style="margin:0; font-size:13px; font-weight:bold; color:#0f172a;">建築図書 整合性確認照合票</h4>
                <div style="font-size:10px; color:#64748b;">案件: <?= htmlspecialchars($project_info['project_name'] ?? '案件詳細') ?> (ID: <?= $project_id ?>)</div>
            </div>
            <div id="cad_report_date" style="font-size:10px; color:#64748b;">
                照合日: <?= date('Y/m/d H:i') ?>
            </div>
        </div>

        <!-- 1. 高さ情報 照合表 -->
        <div style="margin-bottom:12px;">
            <div style="font-weight:bold; font-size:11px; background:#e2e8f0; color:#1e293b; padding:3px 6px; border-radius:2px; margin-bottom:4px;">
                1. 高さ情報 照合表
            </div>
            <table style="width:100%; border-collapse:collapse; text-align:center; font-size:11px; border:1px solid #cbd5e1;">
                <thead>
                    <tr style="background:#f8fafc; color:#475569; border-bottom:1px solid #cbd5e1;">
                        <th style="padding:4px; text-align:left; border:1px solid #cbd5e1;">照合項目</th>
                        <th style="padding:4px; width:22%; border:1px solid #cbd5e1;">確認申請書</th>
                        <th style="padding:4px; width:22%; border:1px solid #cbd5e1;">矩計図 (かなばかり)</th>
                        <th style="padding:4px; width:22%; border:1px solid #cbd5e1;">立面図</th>
                        <th style="padding:4px; width:16%; border:1px solid #cbd5e1;">判定</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; text-align:left; font-weight:600; border:1px solid #cbd5e1;">最高の高さ</td>
                        <td id="res_h_app_max" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_h_kana_max" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_h_elev_max" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_h_max" style="padding:5px; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr>
                        <td style="padding:5px; text-align:left; font-weight:600; border:1px solid #cbd5e1;">最高の軒の高さ</td>
                        <td id="res_h_app_eaves" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_h_kana_eaves" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_h_elev_eaves" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_h_eaves" style="padding:5px; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 2. 面積情報 照合表 -->
        <div style="margin-bottom:12px;">
            <div style="font-weight:bold; font-size:11px; background:#e2e8f0; color:#1e293b; padding:3px 6px; border-radius:2px; margin-bottom:4px;">
                2. 面積情報 照合表
            </div>
            <table style="width:100%; border-collapse:collapse; text-align:center; font-size:11px; border:1px solid #cbd5e1;">
                <thead>
                    <tr style="background:#f8fafc; color:#475569; border-bottom:1px solid #cbd5e1;">
                        <th style="padding:4px; text-align:left; border:1px solid #cbd5e1;">照合項目</th>
                        <th style="padding:4px; width:33%; border:1px solid #cbd5e1;">確認申請書</th>
                        <th style="padding:4px; width:33%; border:1px solid #cbd5e1;">面積表 / 求積図</th>
                        <th style="padding:4px; width:16%; border:1px solid #cbd5e1;">判定</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; text-align:left; font-weight:600; border:1px solid #cbd5e1;">建築面積</td>
                        <td id="res_a_app_build" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_a_area_build" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_a_build" style="padding:5px; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; text-align:left; font-weight:600; border:1px solid #cbd5e1;">1階床面積</td>
                        <td id="res_a_app_f1" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_a_area_f1" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_a_f1" style="padding:5px; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; text-align:left; font-weight:600; border:1px solid #cbd5e1;">2階床面積</td>
                        <td id="res_a_app_f2" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_a_area_f2" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_a_f2" style="padding:5px; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr>
                        <td style="padding:5px; text-align:left; font-weight:600; border:1px solid #cbd5e1;">延べ面積</td>
                        <td id="res_a_app_total" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_a_area_total" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_a_total" style="padding:5px; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 3. 物件情報・図枠 照合表 -->
        <div>
            <div style="font-weight:bold; font-size:11px; background:#e2e8f0; color:#1e293b; padding:3px 6px; border-radius:2px; margin-bottom:4px;">
                3. 物件・図枠情報 照合表
            </div>
            <table style="width:100%; border-collapse:collapse; text-align:left; font-size:11px; border:1px solid #cbd5e1;">
                <thead>
                    <tr style="background:#f8fafc; color:#475569; border-bottom:1px solid #cbd5e1;">
                        <th style="padding:4px; width:25%; border:1px solid #cbd5e1;">項目</th>
                        <th style="padding:4px; width:35%; border:1px solid #cbd5e1;">確認申請書</th>
                        <th style="padding:4px; width:25%; border:1px solid #cbd5e1;">図面図枠 (立面等)</th>
                        <th style="padding:4px; width:15%; text-align:center; border:1px solid #cbd5e1;">判定</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; font-weight:600; border:1px solid #cbd5e1;">工事名称</td>
                        <td id="res_info_app_proj" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_info_cad_proj" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_info_proj" style="padding:5px; text-align:center; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; font-weight:600; border:1px solid #cbd5e1;">建築主</td>
                        <td id="res_info_app_client" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_info_cad_client" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_info_client" style="padding:5px; text-align:center; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr style="border-bottom:1px solid #cbd5e1;">
                        <td style="padding:5px; font-weight:600; border:1px solid #cbd5e1;">設計者</td>
                        <td id="res_info_app_arch" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_info_cad_arch" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_info_arch" style="padding:5px; text-align:center; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                    <tr>
                        <td style="padding:5px; font-weight:600; border:1px solid #cbd5e1;">所在地 / 地名地番</td>
                        <td id="res_info_app_loc" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_info_cad_loc" style="padding:5px; border:1px solid #cbd5e1;">-</td>
                        <td id="res_judge_info_loc" style="padding:5px; text-align:center; font-weight:bold; border:1px solid #cbd5e1;">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 初期保存データ及びスロットファイル情報の受け渡し用スクリプト -->
<script>
window.CAD_COMPARISON_PROJECT_ID = <?= json_encode($project_id) ?>;
window.INITIAL_CAD_COMPARISON_DATA = <?= $saved_cad_comparison ? $saved_cad_comparison : 'null' ?>;
window.SLOT_DOC_FILES = <?= json_encode($slot_files, JSON_UNESCAPED_UNICODE) ?>;
</script>
