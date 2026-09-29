<?php
// components/col_cad_comparison.php
// 建築図書（CAD/JWW/PDF）整合性確認・自動照合コンポーネント
// 管理者ダッシュボード 第2カラム（一次回答後アップロード図書の下）に配置

$saved_cad_comparison = $project_info['cad_comparison_json'] ?? null;
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
        確認申請書（PDF）、矩計図（かなばかり）、面積表、立面図（CAD/JWW/DXF/PDF）から高さ・面積・図枠情報を抽出し、整合性を自動照合します。
    </div>

    <!-- サービス未接続時のアラート (初期非表示) -->
    <div id="cad_service_alert" style="display:none; background:#fffbeb; border:1px solid #fde68a; border-radius:4px; padding:8px; margin-bottom:10px; font-size:11px; color:#92400e;">
        ⚠️ <strong>ローカル解析サービスが停止しています</strong><br>
        JWW/DXF/PDFの自動解析を行うには、お手元のPCで <code>start_cad_parser.bat</code> を起動してください。<br>
        <button type="button" onclick="checkCadServiceStatus()" style="margin-top:4px; background:#f59e0b; color:white; border:none; border-radius:3px; padding:2px 8px; font-size:10px; cursor:pointer;">再接続を確認</button>
    </div>

    <!-- アップロード & 照合コントロールエリア -->
    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:4px; padding:10px; margin-bottom:12px;">
        <div style="font-weight:bold; font-size:11px; color:#334155; margin-bottom:6px;">📂 照合対象ファイルの指定</div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; font-size:11px;">
            <div>
                <label style="font-weight:bold; color:#1e293b; display:block; margin-bottom:2px;">① 確認申請書 (PDF)</label>
                <input type="file" id="cad_file_app" accept=".pdf" style="font-size:10px; width:100%; border:1px solid #cbd5e1; border-radius:3px; padding:2px;">
            </div>
            <div>
                <label style="font-weight:bold; color:#1e293b; display:block; margin-bottom:2px;">② 矩計図 (PDF/DXF/JWW)</label>
                <input type="file" id="cad_file_kana" accept=".pdf,.dxf,.jww" style="font-size:10px; width:100%; border:1px solid #cbd5e1; border-radius:3px; padding:2px;">
            </div>
            <div>
                <label style="font-weight:bold; color:#1e293b; display:block; margin-bottom:2px;">③ 面積表・求積図 (PDF/DXF/JWW)</label>
                <input type="file" id="cad_file_area" accept=".pdf,.dxf,.jww" style="font-size:10px; width:100%; border:1px solid #cbd5e1; border-radius:3px; padding:2px;">
            </div>
            <div>
                <label style="font-weight:bold; color:#1e293b; display:block; margin-bottom:2px;">④ 立面図 (PDF/DXF/JWW)</label>
                <input type="file" id="cad_file_elev" accept=".pdf,.dxf,.jww" style="font-size:10px; width:100%; border:1px solid #cbd5e1; border-radius:3px; padding:2px;">
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px; pt-2; border-top:1px dashed #e2e8f0; padding-top:8px;">
            <button type="button" id="btn_run_cad_compare" onclick="runCadComparison()" style="background:#16a34a; hover:background:#15803d; color:white; border:none; border-radius:4px; padding:6px 14px; font-size:11px; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:4px; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                <span>🚀 図書を自動照合・解析</span>
            </button>
            <button type="button" id="btn_export_cad_pdf" onclick="exportCadComparisonPdf()" style="background:#2563eb; color:white; border:none; border-radius:4px; padding:6px 12px; font-size:11px; font-weight:bold; cursor:pointer; display:flex; align-items:center; gap:4px;">
                <span>📄 A4照合票を出力</span>
            </button>
        </div>
        <div id="cad_compare_progress" style="display:none; font-size:11px; color:#2563eb; font-weight:bold; margin-top:6px;">
            ⏳ ファイルを解析中... (JWWのDXF自動変換およびテキスト抽出を実行しています)
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

<!-- 初期保存データの受け渡し用スクリプト -->
<script>
window.CAD_COMPARISON_PROJECT_ID = <?= json_encode($project_id) ?>;
window.INITIAL_CAD_COMPARISON_DATA = <?= $saved_cad_comparison ? $saved_cad_comparison : 'null' ?>;
</script>
