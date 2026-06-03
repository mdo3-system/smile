// project_detail.js

// ===== チャット自動スクロール =====
function scrollToBottom() {
    const el = document.getElementById('chatMessages');
    if (el) el.scrollTop = el.scrollHeight;
}
window.addEventListener('DOMContentLoaded', () => {
    scrollToBottom();
    if (typeof toggleEstContainers === 'function') {
        toggleEstContainers();
        calcClientEstimate();
    }
});

// ===== メッセージバブルHTML生成 =====
function buildBubble(msg) {
    const isMe = (msg.sender_id == window.APP_CURRENT_USER_ID);
    const isAdminMsg = (msg.sender_id == 1);
    const rowClass = isMe ? 'from-me' : '';
    const bubbleClass = isAdminMsg ? 'bubble-admin' : 'bubble-client';
    const avatarClass = isAdminMsg ? 'admin-avatar' : 'client-avatar';
    const avatarIcon = isAdminMsg ? '👷' : '👤';
    const senderName = isAdminMsg ? '管理者' : window.APP_CLIENT_NAME;
    const timeStr = msg.created_at ? msg.created_at.substring(5, 16).replace('T', ' ') : '';

    let fileHtml = '';
    if (msg.file_path) {
        const isGdrive = msg.file_path.length > 15 && !msg.file_path.includes('/');
        const furl = isGdrive ? `https://drive.google.com/file/d/${msg.file_path}/view?usp=drivesdk` : msg.file_path;
        if (msg.file_type === 'image' && isGdrive) {
            const thumb = `https://drive.google.com/thumbnail?id=${msg.file_path}&sz=w200`;
            fileHtml = `<a href="${furl}" target="_blank"><img src="${thumb}" class="chat-image-thumb" alt="添付画像"></a>`;
        } else if (msg.file_path) {
            fileHtml = `<a href="${furl}" target="_blank" class="chat-pdf-link">📄 添付ファイルを開く</a>`;
        }
    }

    const nameHtml = !isMe ? `<div class="chat-name">${senderName}</div>` : '';
    const textHtml = msg.message_text ? `<div class="chat-bubble ${bubbleClass}">${msg.message_text.replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}</div>` : '';

    return `<div class="chat-bubble-row ${rowClass}" data-msg-id="${msg.id}">
        <div class="chat-avatar ${avatarClass}">${avatarIcon}</div>
        <div class="chat-content">
            ${nameHtml}
            ${textHtml}
            ${fileHtml}
            <div class="chat-time">${timeStr}</div>
        </div>
    </div>`;
}

// ===== ポーリング（30秒ごと） =====
function pollMessages() {
    fetch(`api_get_messages.php?project_id=${window.APP_PROJECT_ID}&since_id=${window.APP_LAST_MSG_ID}`)
        .then(r => r.json())
        .then(msgs => {
            if (msgs && msgs.length > 0) {
                const container = document.getElementById('chatMessages');
                const empty = container.querySelector('[data-empty]');
                if (empty) empty.remove();
                msgs.forEach(msg => {
                    container.insertAdjacentHTML('beforeend', buildBubble(msg));
                    window.APP_LAST_MSG_ID = msg.id;
                });
                scrollToBottom();
            }
        }).catch(e => console.error('ポーリングエラー:', e));
}
setInterval(pollMessages, 30000);

// ===== メッセージ送信 =====
function sendMessage(text) {
    const textarea = document.getElementById('chatTextarea');
    const fileInput = document.getElementById('chatFileInput');
    const targetSelect = document.getElementById('chatTargetFile');
    const msg = text || (textarea ? textarea.value.trim() : '');
    if (!msg && (!fileInput || fileInput.files.length === 0)) return;

    const formData = new FormData();
    formData.append('project_id', window.APP_PROJECT_ID);
    formData.append('action', 'send_message');
    formData.append('message_text', msg);
    if (targetSelect && targetSelect.value) {
        formData.append('target_file', targetSelect.value);
    }
    if (fileInput && fileInput.files.length > 0) {
        formData.append('file', fileInput.files[0]);
    }

    const sendBtn = document.querySelector('.chat-send-btn');
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.textContent = '...';
    }

    fetch('api_send_message.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                if (textarea) textarea.value = '';
                if (fileInput) fileInput.value = '';
                document.getElementById('filePreview').innerHTML = '';
                pollMessages();
            } else {
                alert(data.error || '送信失敗');
            }
        })
        .finally(() => {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.textContent = '➤';
            }
        });
}

function handleKey(e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        sendMessage();
    }
}

function previewFile(input) {
    const preview = document.getElementById('filePreview');
    if (input.files && input.files[0]) {
        preview.innerHTML = `📎 ${input.files[0].name}`;
    } else {
        preview.innerHTML = '';
    }
}

// ===== 協力業者チャット用関数 (Admin <-> Subcontractor) =====
function sendSubMessage() {
    const textarea = document.getElementById('subChatTextarea');
    const fileInput = document.getElementById('subChatFileInput');
    const msg = textarea ? textarea.value.trim() : '';
    if (!msg && (!fileInput || fileInput.files.length === 0)) return;

    const formData = new FormData();
    formData.append('project_id', window.APP_PROJECT_ID);
    formData.append('action', 'send_message');
    formData.append('thread_type', 'sub_admin');
    formData.append('message_text', msg);
    if (fileInput && fileInput.files.length > 0) {
        formData.append('file', fileInput.files[0]);
    }

    fetch('api_send_message.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                if (textarea) textarea.value = '';
                if (fileInput) fileInput.value = '';
                document.getElementById('subFilePreview').innerHTML = '';
                // 簡易的にリロードして反映させる（非同期更新も可能だがシンプル化のため）
                window.location.reload();
            } else {
                alert(data.error || '送信失敗');
            }
        });
}

function handleSubKey(e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        sendSubMessage();
    }
}

function previewSubFile(input) {
    const preview = document.getElementById('subFilePreview');
    if (input.files && input.files[0]) {
        preview.innerHTML = `📎 ${input.files[0].name}`;
    } else {
        preview.innerHTML = '';
    }
}

function sendGreeting() {
    const textEl = document.getElementById('greetingText');
    if (textEl) {
        const text = textEl.innerText;
        document.getElementById('greetingModal').classList.remove('active');
        sendMessage(text);
    }
}

// ===== 見積計算ロジック =====
let currentEstimate = 0, currentTax = 0, currentTotal = 0;
let estimateItems = [];

function toggleEstContainers() {
    if(document.getElementById('container_permit')) {
        document.getElementById('container_permit').style.display = document.getElementById('est_active_permit').checked ? 'block' : 'none';
    }
    if(document.getElementById('container_wall')) {
        document.getElementById('container_wall').style.display = document.getElementById('est_active_wall').checked ? 'block' : 'none';
    }
    if(document.getElementById('container_skin')) {
        document.getElementById('container_skin').style.display = document.getElementById('est_active_skin').checked ? 'block' : 'none';
    }
    if(document.getElementById('container_sky')) {
        document.getElementById('container_sky').style.display = document.getElementById('est_active_sky').checked ? 'block' : 'none';
    }
}

// ヘルパー: 全アイテムをestimateItemsに追加する。checkedかどうかに応じてamountを変える
function pushEstimateItem(name, qty, unit, price, isActive) {
    let amount = isActive ? price * qty : 0;
    estimateItems.push({ name: name, qty: qty, unit: unit, price: price, amount: amount, is_active: isActive });
    return amount;
}

function calcClientEstimate() {
    currentEstimate = 0;
    estimateItems = [];
    
    const el_permit = document.getElementById('est_active_permit');
    const permit_active = el_permit ? el_permit.checked : false;
    const el_wall = document.getElementById('est_active_wall');
    const wall_active = el_wall ? el_wall.checked : false;
    const el_skin = document.getElementById('est_active_skin');
    const skin_active = el_skin ? el_skin.checked : false;
    const el_sky = document.getElementById('est_active_sky');
    const sky_active = el_sky ? el_sky.checked : false;
    
    // 1. 許容応力度計算
    if (permit_active) {
        let base = parseInt(document.getElementById('est_base_permit').value) || 0;
        let area = parseFloat(document.getElementById('est_area_permit').value) || 0;
        let area_qty = area > 150 ? Math.ceil(area - 150) : 0;
        let base_with_area = base + (area_qty * 600);
        
        currentEstimate += pushEstimateItem("許容応力度計算 基本料金", 1, "式", base, true);
        currentEstimate += pushEstimateItem("許容応力度計算 構造床面積割増 (150㎡超)", area_qty, "㎡", 600, area_qty > 0);
        
        // 形状加算 (基本料金+面積割増に乗算)
        document.querySelectorAll('.est_mult_permit').forEach(cb => {
            let labelText = cb.parentElement.innerText.trim(); // e.g. "準耐火/耐火構造 (+20%)"
            let rate = parseFloat(cb.value);
            let opt_price = Math.round(base_with_area * rate);
            currentEstimate += pushEstimateItem(labelText, 1, "式", opt_price, cb.checked);
        });

        let grade = parseInt(document.getElementById('est_grade_permit').value) || 0;
        let kanamono = parseInt(document.getElementById('est_kanamono_permit').value) || 0;
        let special = parseInt(document.getElementById('est_special_permit').value) || 0;
        
        // 全部の等級をリストアップしたいが、selectなので選ばれたものだけが確定。他もリストアップするか？
        // Selectは選択されたもの以外は「0円」などのオプションなので、選択されたものだけを表示でOKとする。
        // もし全部見せたいなら固定でリストアップする必要がある。
        // "等級加算なし" でも行としては出す
        let gradeName = "許容応力度計算 目標等級加算";
        if(grade == 40000) gradeName = "許容応力度計算 目標等級加算 (耐震3/耐風2等)";
        if(grade == 20000) gradeName = "許容応力度計算 目標等級加算 (耐震2)";
        currentEstimate += pushEstimateItem(gradeName, 1, "式", grade, grade > 0);

        currentEstimate += pushEstimateItem("許容応力度計算 金物工法割増", kanamono > 0 ? kanamono : 1, "階", 15000, kanamono > 0);
        currentEstimate += pushEstimateItem("許容応力度計算 特殊箇所割増", special > 0 ? special : 1, "箇所", 15000, special > 0);
    }
    
    // 2. 性能表示壁量計算
    if (wall_active) {
        let base = parseInt(document.getElementById('est_base_wall').value) || 0;
        let area = parseFloat(document.getElementById('est_area_wall').value) || 0;
        let area_qty = area > 150 ? Math.ceil(area - 150) : 0;
        let base_with_area = base + (area_qty * 500);
        
        currentEstimate += pushEstimateItem("性能表示壁量計算 基本料金", 1, "式", base, true);
        currentEstimate += pushEstimateItem("性能表示壁量計算 構造床面積割増 (150㎡超)", area_qty, "㎡", 500, area_qty > 0);
        
        document.querySelectorAll('.est_mult_wall').forEach(cb => {
            let labelText = cb.parentElement.innerText.trim();
            let rate = parseFloat(cb.value);
            let opt_price = Math.round(base_with_area * rate);
            currentEstimate += pushEstimateItem("性能表示壁量計算 " + labelText, 1, "式", opt_price, cb.checked);
        });

        let dwg = parseInt(document.getElementById('est_dwg_wall').value) || 0;
        let jintsu = parseInt(document.getElementById('est_jintsu_wall').value) || 0;
        
        currentEstimate += pushEstimateItem("性能表示壁量計算 構造図(基礎伏図)作成", 1, "式", dwg, dwg > 0);
        currentEstimate += pushEstimateItem("性能表示壁量計算 人通孔箇所数割増", 1, "式", jintsu, jintsu > 0);
        
        let kisohari = document.getElementById('est_kisohari_wall').checked;
        let kisohari_price = 20000 + (area > 150 ? Math.ceil(area - 150) * 500 : 0);
        currentEstimate += pushEstimateItem("性能表示壁量計算 基礎梁許容応力度計算", 1, "式", kisohari_price, kisohari);
    }
    
    // 3. 外皮計算
    if (skin_active) {
        let base = parseInt(document.getElementById('est_base_skin').value) || 0;
        let area = parseFloat(document.getElementById('est_area_skin').value) || 0;
        let area_qty = area > 100 ? Math.ceil(area - 100) : 0;
        let base_with_area = base + (area_qty * 500);
        
        currentEstimate += pushEstimateItem("外皮計算 基本料金", 1, "式", base, true);
        currentEstimate += pushEstimateItem("外皮計算 外皮床面積割増 (100㎡超)", area_qty, "㎡", 500, area_qty > 0);
        
        document.querySelectorAll('.est_mult_skin').forEach(cb => {
            let labelText = cb.parentElement.innerText.trim();
            let rate = parseFloat(cb.value);
            let opt_price = Math.round(base_with_area * rate);
            currentEstimate += pushEstimateItem("外皮計算 " + labelText, 1, "式", opt_price, cb.checked);
        });
        
        let kisotachi = parseInt(document.getElementById('est_kisotachi_skin').value) || 0;
        currentEstimate += pushEstimateItem("外皮計算 基礎立上り400超割増", kisotachi > 0 ? kisotachi : 1, "箇所", 15000, kisotachi > 0);
        
        let setsumei = document.getElementById('est_setsumei_skin').checked;
        currentEstimate += pushEstimateItem("外皮計算 設計内容説明書を作成する", 1, "式", 15000, setsumei);
        
        currentEstimate += pushEstimateItem("一次消費エネルギー量計算", 1, "式", 15000, true);
    }
    
    // 4. 天空率計算
    if (sky_active) {
        let road = document.getElementById('est_road_sky').checked;
        let north = document.getElementById('est_north_sky').checked;
        currentEstimate += pushEstimateItem("天空率 道路斜線", 1, "式", 50000, road);
        currentEstimate += pushEstimateItem("天空率 北側斜線", 1, "式", 50000, north);
        
        let extra = parseInt(document.getElementById('est_extra_sky').value) || 0;
        currentEstimate += pushEstimateItem("天空率 追加斜線面検討", extra > 0 ? extra : 1, "面", 25000, extra > 0);
        
        let site_area = parseFloat(document.getElementById('est_site_area_sky').value) || 0;
        let site_area_qty = site_area > 150 ? Math.ceil(site_area - 150) : 0;
        currentEstimate += pushEstimateItem("天空率 敷地面積割増 (150㎡超)", site_area_qty > 0 ? site_area_qty : 1, "㎡", 200, site_area_qty > 0);
        
        let building_area = parseFloat(document.getElementById('est_building_area_sky').value) || 0;
        let building_area_qty = building_area > 150 ? Math.ceil(building_area - 150) : 0;
        currentEstimate += pushEstimateItem("天空率 建物床面積割増 (150㎡超)", building_area_qty > 0 ? building_area_qty : 1, "㎡", 200, building_area_qty > 0);
        
        let detail = document.getElementById('est_detail_sky').checked;
        currentEstimate += pushEstimateItem("天空率 詳細モデル検討", 1, "式", 15000, detail);
    }
    
    currentTax = Math.round(currentEstimate * 0.1);
    currentTotal = currentEstimate + currentTax;
    
    const elTotal = document.getElementById('est_total_disp');
    if (elTotal) elTotal.innerText = currentEstimate.toLocaleString();
    
    const elTax = document.getElementById('est_tax_disp');
    if (elTax) elTax.innerText = currentTax.toLocaleString();
    
    const elGrand = document.getElementById('est_grand_total_disp');
    if (elGrand) elGrand.innerText = currentTotal.toLocaleString();
}

function saveAndPrintEstimate(isFormal = false) {
    calcClientEstimate();
    if (currentEstimate === 0) {
        alert('計算する対象を選択してください。');
        return;
    }
    
    const btn = isFormal ? document.getElementById('formal_pdf_issue_btn') : document.getElementById('pdf_issue_btn');
    if (btn) {
        btn.disabled = true;
        btn.innerText = isFormal ? '本見積確定中...' : 'PDF発行中...';
    }
    
    const formData = new FormData();
    formData.append('project_id', window.APP_PROJECT_ID);
    formData.append('total_price', currentEstimate);
    formData.append('note', JSON.stringify(estimateItems));
    formData.append('is_formal', isFormal ? '1' : '0');
    
    fetch('api_save_estimate.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.drive_file_id) {
                // 発行成功時にチャットへも自動送信するロジック
                let msg = isFormal ? "【本見積書が確定・発行されました】\n" : "【お見積書が発行されました】\n";
                msg += `税抜金額: ${currentEstimate.toLocaleString()}円\n`;
                msg += `消費税: ${currentTax.toLocaleString()}円\n`;
                msg += `税込合計: ${currentTotal.toLocaleString()}円\n\n`;
                msg += "詳細は左パネルの最新の見積書からご確認ください。\n";
                msg += "\n";
                if (!isFormal) {
                    msg += "ご依頼いただける場合は、「設計依頼データの送付」ボタンから必須ファイル（CADデータ等）をアップロードの上、正式にご発注をお願いいたします。";
                } else {
                    msg += "本見積内容にて確定いたしました。追って一次請求書（着手金50%）を発行いたしますので、ご確認のほどよろしくお願いいたします。";
                }

                // sendMessageを使ってチャットへ投稿（APIへPOST）
                const chatData = new FormData();
                chatData.append('project_id', window.APP_PROJECT_ID);
                chatData.append('message_text', msg);

                return fetch('api_send_message.php', { method: 'POST', body: chatData })
                    .then(chatRes => chatRes.json())
                    .then(() => {
                        alert(isFormal ? '本見積書が確定し、PDFが作成されてチャットへ送信されました。' : 'お見積書PDFが作成され、チャットへ送信されました。');
                        window.open(`https://drive.google.com/file/d/${data.drive_file_id}/view?usp=drivesdk`, '_blank');
                        location.reload();
                    });
            } else {
                alert('見積保存に失敗しました');
            }
        })
        .catch(e => alert('通信エラー: ' + e))
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerText = isFormal ? '本見積として確定・PDF発行' : '印刷用PDFを発行';
            }
        });
}

// ===== 自動リロード/通知ポーリング =====
let autoPollInterval = null;
let lastMaxMsgId = window.APP_LAST_MSG_ID || 0;

function checkUpdates() {
    if (!window.APP_PROJECT_ID) return;
    
    // 入力中かどうか判定
    const chatInput = document.getElementById('chatInput');
    const isTyping = chatInput && (document.activeElement === chatInput || chatInput.value.trim().length > 0);
    
    fetch('api_check_updates.php?project_id=' + window.APP_PROJECT_ID)
        .then(r => r.json())
        .then(data => {
            if (data.max_message_id > lastMaxMsgId) {
                if (isTyping) {
                    showUpdateToast();
                } else {
                    window.location.reload();
                }
            }
        })
        .catch(e => console.error("Poll error", e));
}

function showUpdateToast() {
    if (document.getElementById('updateToast')) return;
    
    const toast = document.createElement('div');
    toast.id = 'updateToast';
    toast.innerHTML = `
        <div style="position:fixed; bottom:20px; right:20px; background:#10b981; color:white; padding:15px 20px; border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.1); z-index:9999; display:flex; align-items:center; gap:15px;">
            <span style="font-size:14px; font-weight:bold;">新着メッセージがあります</span>
            <button onclick="window.location.reload()" style="background:white; color:#10b981; border:none; padding:5px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">更新</button>
            <button onclick="this.parentElement.remove()" style="background:transparent; color:white; border:1px solid white; padding:5px; border-radius:4px; cursor:pointer;">閉じる</button>
        </div>
    `;
    document.body.appendChild(toast);
}

// 15秒ごとにポーリング
if (window.APP_PROJECT_ID) {
    autoPollInterval = setInterval(checkUpdates, 15000);
}
