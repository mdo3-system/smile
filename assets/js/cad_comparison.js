/**
 * assets/js/cad_comparison.js
 * 建築図書 (CAD/JWW/DXF/PDF) 整合性確認・照合クライアントロジック
 */

const CAD_LOCAL_API_URL = "http://localhost:5005";

document.addEventListener("DOMContentLoaded", function () {
    // サービスの稼働確認
    checkCadServiceStatus();

    // 過去の保存データがあれば復元表示
    if (window.INITIAL_CAD_COMPARISON_DATA) {
        try {
            const data = (typeof window.INITIAL_CAD_COMPARISON_DATA === 'string')
                ? JSON.parse(window.INITIAL_CAD_COMPARISON_DATA)
                : window.INITIAL_CAD_COMPARISON_DATA;
            renderCadComparison(data);
        } catch (e) {
            console.error("保存済みCAD照合データのパースエラー:", e);
        }
    }
});

// ローカルサービスの接続チェック
async function checkCadServiceStatus() {
    const statusEl = document.getElementById("cad_service_status");
    const alertEl = document.getElementById("cad_service_alert");
    if (!statusEl) return;

    statusEl.innerHTML = "🔄 接続確認中...";
    statusEl.style.background = "#e2e8f0";
    statusEl.style.color = "#475569";

    try {
        const res = await fetch(`${CAD_LOCAL_API_URL}/api/health`, {
            method: "GET",
            mode: "cors",
            headers: { "Accept": "application/json" }
        });
        if (res.ok) {
            const data = await res.json();
            statusEl.innerHTML = "🟢 ローカル解析サービス稼働中";
            statusEl.style.background = "#dcfce7";
            statusEl.style.color = "#15803d";
            if (alertEl) alertEl.style.display = "none";
        } else {
            throw new Error("HTTP " + res.status);
        }
    } catch (err) {
        statusEl.innerHTML = "⚪ 未接続 (Port: 5005)";
        statusEl.style.background = "#fee2e2";
        statusEl.style.color = "#b91c1c";
        if (alertEl) alertEl.style.display = "block";
    }
}

// 図書の自動照合・解析を実行
async function runCadComparison() {
    const fileApp = document.getElementById("cad_file_app")?.files[0];
    const fileKana = document.getElementById("cad_file_kana")?.files[0];
    const fileArea = document.getElementById("cad_file_area")?.files[0];
    const fileElev = document.getElementById("cad_file_elev")?.files[0];

    if (!fileApp && !fileKana && !fileArea && !fileElev) {
        alert("照合対象の図書ファイル（確認申請書、矩計図、面積表、立面図のいずれか）を選択してください。");
        return;
    }

    const progressEl = document.getElementById("cad_compare_progress");
    const btnRun = document.getElementById("btn_run_cad_compare");
    if (progressEl) progressEl.style.display = "block";
    if (btnRun) btnRun.disabled = true;

    const fd = new FormData();
    if (fileApp) fd.append("app_doc", fileApp);
    if (fileKana) fd.append("kanabakari", fileKana);
    if (fileArea) fd.append("area_calc", fileArea);
    if (fileElev) fd.append("elevation", fileElev);

    try {
        const res = await fetch(`${CAD_LOCAL_API_URL}/api/compare`, {
            method: "POST",
            mode: "cors",
            body: fd
        });

        if (!res.ok) {
            throw new Error(`解析サービスエラー (HTTP ${res.status})`);
        }

        const resData = await res.json();
        if (resData.status === "ok") {
            renderCadComparison(resData);
            // サーバー側DBに自動保存
            saveCadComparisonToDb(resData);
            alert("図書の自動解析および照合が完了しました。");
        } else {
            alert("解析エラー: " + (resData.error || "不明なエラー"));
        }
    } catch (err) {
        console.error("CAD照合エラー:", err);
        alert(`ローカル解析サービスとの通信に失敗しました。\n'start_cad_parser.bat' が起動しているか確認してください。\n詳細: ${err.message}`);
    } finally {
        if (progressEl) progressEl.style.display = "none";
        if (btnRun) btnRun.disabled = false;
    }
}

// 照合結果をテーブルに描画
function renderCadComparison(data) {
    const results = data.results || {};
    const app = results.app || {};
    const kana = results.kanabakari || {};
    const area = results.area || {};
    const elev = results.elevation || {};

    const formatNum = (val, unit) => (val !== null && val !== undefined) ? `${val} ${unit}` : '-';
    const formatStr = (val) => val ? String(val).trim() : '-';

    // 1. 高さ情報
    const setElem = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };

    setElem("res_h_app_max", formatNum(app.max_height, "m"));
    setElem("res_h_kana_max", formatNum(kana.max_height, "m"));
    setElem("res_h_elev_max", formatNum(elev.max_height, "m"));

    setJudge("res_judge_h_max", [app.max_height, kana.max_height, elev.max_height]);

    setElem("res_h_app_eaves", formatNum(app.eaves_height, "m"));
    setElem("res_h_kana_eaves", formatNum(kana.eaves_height, "m"));
    setElem("res_h_elev_eaves", formatNum(elev.eaves_height, "m"));

    setJudge("res_judge_h_eaves", [app.eaves_height, kana.eaves_height, elev.eaves_height]);

    // 2. 面積情報
    setElem("res_a_app_build", formatNum(app.building_area, "㎡"));
    setElem("res_a_area_build", formatNum(area.building_area, "㎡"));
    setJudge("res_judge_a_build", [app.building_area, area.building_area]);

    setElem("res_a_app_f1", formatNum(app.floor_1_area, "㎡"));
    setElem("res_a_area_f1", formatNum(area.floor_1_area, "㎡"));
    setJudge("res_judge_a_f1", [app.floor_1_area, area.floor_1_area]);

    setElem("res_a_app_f2", formatNum(app.floor_2_area, "㎡"));
    setElem("res_a_area_f2", formatNum(area.floor_2_area, "㎡"));
    setJudge("res_judge_a_f2", [app.floor_2_area, area.floor_2_area]);

    setElem("res_a_app_total", formatNum(app.total_area, "㎡"));
    setElem("res_a_area_total", formatNum(area.total_area, "㎡"));
    setJudge("res_judge_a_total", [app.total_area, area.total_area]);

    // 3. 物件・図枠情報
    setElem("res_info_app_proj", formatStr(app.project_name));
    setElem("res_info_cad_proj", formatStr(elev.project_name || kana.project_name));
    setTextJudge("res_judge_info_proj", app.project_name, elev.project_name || kana.project_name);

    setElem("res_info_app_client", formatStr(app.client_name));
    setElem("res_info_cad_client", formatStr(elev.client_name || kana.client_name));
    setTextJudge("res_judge_info_client", app.client_name, elev.client_name || kana.client_name);

    setElem("res_info_app_arch", formatStr(app.architect_name));
    setElem("res_info_cad_arch", formatStr(elev.architect_name || kana.architect_name));
    setTextJudge("res_judge_info_arch", app.architect_name, elev.architect_name || kana.architect_name);

    setElem("res_info_app_loc", formatStr(app.location));
    setElem("res_info_cad_loc", formatStr(elev.location || kana.location));
    setTextJudge("res_judge_info_loc", app.location, elev.location || kana.location);
}

// 数値の判定 (許容誤差 0.01)
function setJudge(elemId, values) {
    const el = document.getElementById(elemId);
    if (!el) return;

    const validVals = values.filter(v => v !== null && v !== undefined && !isNaN(v));
    if (validVals.length <= 1) {
        el.textContent = "-";
        el.style.color = "#64748b";
        return;
    }

    const first = validVals[0];
    const isAllMatch = validVals.every(v => Math.abs(v - first) <= 0.01);

    if (isAllMatch) {
        el.innerHTML = "<span style='color:#16a34a;'>✅ 一致 (OK)</span>";
    } else {
        el.innerHTML = "<span style='color:#dc2626;'>⚠️ 不一致</span>";
    }
}

// テキストの判定
function setTextJudge(elemId, txt1, txt2) {
    const el = document.getElementById(elemId);
    if (!el) return;

    if (!txt1 || !txt2) {
        el.textContent = "-";
        el.style.color = "#64748b";
        return;
    }

    const clean1 = String(txt1).replace(/\s+/g, "");
    const clean2 = String(txt2).replace(/\s+/g, "");

    if (clean1.includes(clean2) || clean2.includes(clean1)) {
        el.innerHTML = "<span style='color:#16a34a;'>✅ 一致</span>";
    } else {
        el.innerHTML = "<span style='color:#dc2626;'>⚠️ 相違</span>";
    }
}

// 照合結果をサーバー側DBへ保存
async function saveCadComparisonToDb(data) {
    const projectId = window.CAD_COMPARISON_PROJECT_ID;
    if (!projectId) return;

    try {
        await fetch("api_save_cad_comparison.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                project_id: projectId,
                comparison_data: data
            })
        });
    } catch (e) {
        console.warn("照合結果のDB自動保存エラー:", e);
    }
}

// A4 帳票 PDF 出力
async function exportCadComparisonPdf() {
    const reportEl = document.getElementById("cad_comparison_report");
    if (!reportEl) return;

    // html2pdf が未ロードなら動的ロード
    if (typeof html2pdf === "undefined") {
        await new Promise((resolve, reject) => {
            const script = document.createElement("script");
            script.src = "https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js";
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    const opt = {
        margin: [8, 8, 8, 8],
        filename: `建築図書_整合性照合票_案件${window.CAD_COMPARISON_PROJECT_ID || ''}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(reportEl).save();
}
