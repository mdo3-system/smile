/**
 * assets/js/cad_comparison.js
 * 建築図書 (CAD/JWW/DXF/PDF) 整合性確認・照合クライアントロジック
 * スロット最新ファイル一括自動取得 & ローカル解析連携
 */

let activeCadApiUrl = "http://127.0.0.1:5005";
const CAD_CANDIDATE_URLS = ["http://127.0.0.1:5005", "http://localhost:5005"];

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

    let connected = false;
    for (const url of CAD_CANDIDATE_URLS) {
        try {
            const res = await fetch(`${url}/api/health`, {
                method: "GET",
                mode: "cors",
                headers: { "Accept": "application/json" }
            });
            if (res.ok) {
                const data = await res.json();
                activeCadApiUrl = url;
                connected = true;
                statusEl.innerHTML = `🟢 ローカル解析サービス稼働中`;
                statusEl.style.background = "#dcfce7";
                statusEl.style.color = "#15803d";
                if (alertEl) alertEl.style.display = "none";
                break;
            }
        } catch (e) {
            // 次の候補を試行
        }
    }

    if (!connected) {
        statusEl.innerHTML = "⚪ 未接続 (Port: 5005)";
        statusEl.style.background = "#fee2e2";
        statusEl.style.color = "#b91c1c";
        if (alertEl) alertEl.style.display = "block";
    }
}

// 【メイン機能】スロットの最新提出図書を一括自動取得して照合
async function runSlotAutoComparison() {
    const slotFiles = window.SLOT_DOC_FILES || {};
    const projectId = window.CAD_COMPARISON_PROJECT_ID;

    const fileAppManual = document.getElementById("cad_file_app")?.files[0];
    const fileKanaManual = document.getElementById("cad_file_kana")?.files[0];
    const fileAreaManual = document.getElementById("cad_file_area")?.files[0];
    const fileElevManual = document.getElementById("cad_file_elev")?.files[0];

    const hasAnySlot = !!(slotFiles.app || slotFiles.kanabakari || slotFiles.area || slotFiles.elevation);
    const hasAnyManual = !!(fileAppManual || fileKanaManual || fileAreaManual || fileElevManual);

    if (!hasAnySlot && !hasAnyManual) {
        alert("スロットに提出された図書（確認申請書・矩計図・面積表・立面図）がまだありません。\n図書がアップロードされた後に実行するか、下部の「手動でファイルを選択」から指定してください。");
        return;
    }

    const progressEl = document.getElementById("cad_compare_progress");
    const btnRun = document.getElementById("btn_run_slot_compare");
    if (progressEl) {
        progressEl.style.display = "block";
        progressEl.innerHTML = "⏳ [1/3] 提出図書ファイルをダウンロード中...";
    }
    if (btnRun) btnRun.disabled = true;

    try {
        const fd = new FormData();
        let fetchedCount = 0;
        const failedItems = [];

        // 1. 確認申請書
        if (fileAppManual) {
            fd.append("app_doc", fileAppManual);
            fetchedCount++;
        } else if (slotFiles.app) {
            if (progressEl) progressEl.innerHTML = `⏳ [1/3] 申請書 (${slotFiles.app.file_name}) をダウンロード中...`;
            const file = await fetchDocBlob(projectId, slotFiles.app.file_category, slotFiles.app.file_name, slotFiles.app.id);
            if (file) {
                fd.append("app_doc", file);
                fetchedCount++;
            } else {
                failedItems.push("確認申請書");
            }
        }

        // 2. 矩計図
        if (fileKanaManual) {
            fd.append("kanabakari", fileKanaManual);
            fetchedCount++;
        } else if (slotFiles.kanabakari) {
            if (progressEl) progressEl.innerHTML = `⏳ [1/3] 矩計図 (${slotFiles.kanabakari.file_name}) をダウンロード中...`;
            const file = await fetchDocBlob(projectId, slotFiles.kanabakari.file_category, slotFiles.kanabakari.file_name, slotFiles.kanabakari.id);
            if (file) {
                fd.append("kanabakari", file);
                fetchedCount++;
            } else {
                failedItems.push("矩計図");
            }
        }

        // 3. 面積表
        if (fileAreaManual) {
            fd.append("area_calc", fileAreaManual);
            fetchedCount++;
        } else if (slotFiles.area) {
            if (progressEl) progressEl.innerHTML = `⏳ [1/3] 面積表 (${slotFiles.area.file_name}) をダウンロード中...`;
            const file = await fetchDocBlob(projectId, slotFiles.area.file_category, slotFiles.area.file_name, slotFiles.area.id);
            if (file) {
                fd.append("area_calc", file);
                fetchedCount++;
            } else {
                failedItems.push("面積表");
            }
        }

        // 4. 立面図
        if (fileElevManual) {
            fd.append("elevation", fileElevManual);
            fetchedCount++;
        } else if (slotFiles.elevation) {
            if (progressEl) progressEl.innerHTML = `⏳ [1/3] 立面図 (${slotFiles.elevation.file_name}) をダウンロード中...`;
            const file = await fetchDocBlob(projectId, slotFiles.elevation.file_category, slotFiles.elevation.file_name, slotFiles.elevation.id);
            if (file) {
                fd.append("elevation", file);
                fetchedCount++;
            } else {
                failedItems.push("立面図");
            }
        }

        if (fetchedCount === 0) {
            throw new Error(`図書ファイルのダウンロードに失敗しました（${failedItems.join('、')}）。\nGoogle Drive連携またはネットワーク接続を確認するか、「手動でファイルを選択」をお試しください。`);
        }

        if (progressEl) {
            progressEl.innerHTML = `⏳ [2/3] ローカル解析サービス（Port: 5005）へ送信中... (対象ファイル: ${fetchedCount}件)`;
        }

        // ローカル解析サービス (Port: 5005) へ一括送信
        const res = await fetch(`${activeCadApiUrl}/api/compare`, {
            method: "POST",
            mode: "cors",
            body: fd
        });

        if (!res.ok) {
            const errText = await res.text().catch(() => "");
            throw new Error(`解析サービスエラー (HTTP ${res.status}): ${errText}`);
        }

        if (progressEl) {
            progressEl.innerHTML = "⏳ [3/3] 照合結果を集計・保存中...";
        }

        const resData = await res.json();
        if (resData.status === "ok") {
            renderCadComparison(resData);
            // サーバー側DBに自動保存
            saveCadComparisonToDb(resData);
            if (progressEl) progressEl.innerHTML = "✅ 自動照合が完了しました！";
            setTimeout(() => { if (progressEl) progressEl.style.display = "none"; }, 3000);
            alert("図書の自動解析および照合が完了しました！");
        } else {
            alert("解析エラー: " + (resData.error || "不明なエラー"));
        }
    } catch (err) {
        console.error("スロット図書自動照合エラー:", err);
        alert(`照合処理中にエラーが発生しました。\n\n詳細: ${err.message}\n\n※'start_cad_parser.bat' の黒い画面が開いているか確認してください。`);
    } finally {
        if (btnRun) btnRun.disabled = false;
    }
}

// サーバーAPIから図書バイナリを取得してFileオブジェクトに変換
async function fetchDocBlob(projectId, category, filename, fileId) {
    try {
        const param = fileId ? `file_id=${encodeURIComponent(fileId)}` : `category=${encodeURIComponent(category)}`;
        const url = `api_get_project_doc_file.php?project_id=${encodeURIComponent(projectId)}&${param}`;
        const res = await fetch(url, { credentials: 'include' });
        if (!res.ok) {
            console.warn(`図書ファイル取得失敗 (${category || fileId}): HTTP ${res.status}`);
            return null;
        }
        const blob = await res.blob();
        return new File([blob], filename, { type: blob.type || 'application/octet-stream' });
    } catch (e) {
        console.warn(`図書ファイルfetchエラー (${category || fileId}):`, e);
        return null;
    }
}

// 手動選択ファイルでの照合
async function runManualCadComparison() {
    const fileApp = document.getElementById("cad_file_app")?.files[0];
    const fileKana = document.getElementById("cad_file_kana")?.files[0];
    const fileArea = document.getElementById("cad_file_area")?.files[0];
    const fileElev = document.getElementById("cad_file_elev")?.files[0];

    if (!fileApp && !fileKana && !fileArea && !fileElev) {
        alert("照合対象の図書ファイルを選択してください。");
        return;
    }

    const progressEl = document.getElementById("cad_compare_progress");
    if (progressEl) progressEl.style.display = "block";

    const fd = new FormData();
    if (fileApp) fd.append("app_doc", fileApp);
    if (fileKana) fd.append("kanabakari", fileKana);
    if (fileArea) fd.append("area_calc", fileArea);
    if (fileElev) fd.append("elevation", fileElev);

    try {
        const res = await fetch(`${activeCadApiUrl}/api/compare`, {
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
            saveCadComparisonToDb(resData);
            alert("手動選択ファイルの解析・照合が完了しました。");
        } else {
            alert("解析エラー: " + (resData.error || "不明なエラー"));
        }
    } catch (err) {
        console.error("手動CAD照合エラー:", err);
        alert(`解析サービスとの通信に失敗しました: ${err.message}`);
    } finally {
        if (progressEl) progressEl.style.display = "none";
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

    const setElem = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };

    // 1. 高さ情報
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
