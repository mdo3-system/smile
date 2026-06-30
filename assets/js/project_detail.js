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

    const avatarHtml = !isMe ? `<div class="chat-avatar ${avatarClass}" title="${senderName}">${avatarIcon}</div>` : '';
    const nameHtml = !isMe ? `<div class="chat-name">${senderName}</div>` : '';
    const textHtml = msg.message_text ? `<div class="chat-bubble ${bubbleClass}">${msg.message_text.replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')}</div>` : '';

    let deleteBtnHtml = '';
    if (isMe || window.APP_USER_ROLE === 'admin') {
        deleteBtnHtml = `<span class="chat-delete-btn" style="cursor:pointer; color:#ef4444; font-size:10px; margin-left:8px;" onclick="deleteChatMessage(${msg.id})">取り消し</span>`;
    }

    return `<div class="chat-bubble-row ${rowClass}" data-msg-id="${msg.id}">
        ${avatarHtml}
        <div class="chat-content">
            ${nameHtml}
            ${textHtml}
            ${fileHtml}
            <div class="chat-time">${timeStr}${deleteBtnHtml}</div>
        </div>
    </div>`;
}

// ===== ポーリング（30秒ごと） =====
function pollMessages() {
    const tabParam = window.APP_ACTIVE_TAB ? `&tab=${encodeURIComponent(window.APP_ACTIVE_TAB)}` : '';
    fetch(`api_get_messages.php?project_id=${window.APP_PROJECT_ID}&since_id=${window.APP_LAST_MSG_ID}${tabParam}`)
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
    if (!msg && chatSelectedFiles.length === 0) return;

    const formData = new FormData();
    formData.append('project_id', window.APP_PROJECT_ID);
    formData.append('action', 'send_message');
    formData.append('message_text', msg);
    if (window.APP_ACTIVE_TAB) {
        formData.append('tab', window.APP_ACTIVE_TAB);
    }
    if (targetSelect && targetSelect.value) {
        formData.append('target_file', targetSelect.value);
    }
    if (chatSelectedFiles && chatSelectedFiles.length > 0) {
        chatSelectedFiles.forEach(f => {
            formData.append('files[]', f);
        });
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
                chatSelectedFiles = [];
                renderChatFilePreview();
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

let chatSelectedFiles = [];

function previewFile(input) {
    const preview = document.getElementById('filePreview');
    const label = input.closest('.chat-attach-btn');
    const textarea = document.getElementById('chatTextarea');
    const sendBtn = document.querySelector('.chat-send-btn');

    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach(f => {
            if (!chatSelectedFiles.some(existing => existing.name === f.name)) {
                chatSelectedFiles.push(f);
            }
        });
        input.value = '';
    }
    renderChatFilePreview();
}

function renderChatFilePreview() {
    const preview = document.getElementById('filePreview');
    const textarea = document.getElementById('chatTextarea');
    const sendBtn = document.querySelector('.chat-send-btn');
    const fileInput = document.getElementById('chatFileInput');
    const label = fileInput ? fileInput.closest('.chat-attach-btn') : null;

    if (chatSelectedFiles.length > 0) {
        let badgesHtml = '';
        chatSelectedFiles.forEach((f, index) => {
            badgesHtml += `<span class="preview-badge" style="background:#dcfce7; color:#15803d; padding:6px 12px; border-radius:6px; font-size:12px; display:inline-flex; align-items:center; gap:5px; border:2px solid #bbf7d0; font-weight:bold; box-shadow:0 2px 4px rgba(0,0,0,0.05); margin-right:5px; margin-bottom:5px;">📎 ${f.name} <span class="preview-remove" style="cursor:pointer; color:#ef4444; font-weight:bold; margin-left:8px; font-size:14px; line-height:1; padding:2px 6px; background:#fee2fee; border-radius:50%;" onclick="removeChatFile(${index})">×</span></span>`;
        });
        preview.innerHTML = badgesHtml;
        if (label) {
            label.classList.add('attached');
            label.style.background = '#10b981';
            label.style.borderColor = '#059669';
        }
        if (textarea) {
            textarea.style.background = '#f0fdf4';
            textarea.style.borderColor = '#10b981';
            textarea.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.2)';
        }
        if (sendBtn) {
            sendBtn.style.background = '#10b981';
            sendBtn.style.animation = 'pulse-green 1.5s infinite';
        }
    } else {
        preview.innerHTML = '';
        if (label) {
            label.classList.remove('attached');
            label.style.background = '';
            label.style.borderColor = '';
        }
        if (textarea) {
            textarea.style.background = '';
            textarea.style.borderColor = '';
            textarea.style.boxShadow = '';
        }
        if (sendBtn) {
            sendBtn.style.background = '';
            sendBtn.style.animation = '';
        }
    }
}

function removeChatFile(index) {
    chatSelectedFiles.splice(index, 1);
    renderChatFilePreview();
}

// ===== 協力業者チャット用関数 (Admin <-> Subcontractor) =====
function sendSubMessage() {
    const textarea = document.getElementById('subChatTextarea');
    const fileInput = document.getElementById('subChatFileInput');
    const msg = textarea ? textarea.value.trim() : '';
    if (!msg && subSelectedFiles.length === 0) return;

    const formData = new FormData();
    formData.append('project_id', window.APP_PROJECT_ID);
    formData.append('action', 'send_message');
    formData.append('thread_type', 'sub_admin');
    formData.append('message_text', msg);
    if (subSelectedFiles && subSelectedFiles.length > 0) {
        subSelectedFiles.forEach(f => {
            formData.append('files[]', f);
        });
    }

    fetch('api_send_message.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                if (textarea) textarea.value = '';
                subSelectedFiles = [];
                renderSubFilePreview();
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

let subSelectedFiles = [];

function previewSubFile(input) {
    const preview = document.getElementById('subFilePreview');
    const label = input.closest('.chat-attach-btn');

    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach(f => {
            if (!subSelectedFiles.some(existing => existing.name === f.name)) {
                subSelectedFiles.push(f);
            }
        });
        input.value = '';
    }
    renderSubFilePreview();
}

function renderSubFilePreview() {
    const preview = document.getElementById('subFilePreview');
    const fileInput = document.getElementById('subChatFileInput');
    const label = fileInput ? fileInput.closest('.chat-attach-btn') : null;

    if (subSelectedFiles.length > 0) {
        let badgesHtml = '';
        subSelectedFiles.forEach((f, index) => {
            badgesHtml += `<span class="preview-badge" style="background:#e0f2fe; color:#0369a1; padding:4px 8px; border-radius:4px; font-size:12px; display:inline-flex; align-items:center; gap:5px; border:1px solid #bae6fd; font-weight:bold; margin-right:5px; margin-bottom:5px;">📎 ${f.name} <span class="preview-remove" style="cursor:pointer; color:#ef4444; font-weight:bold; margin-left:5px;" onclick="removeSubFile(${index})">×</span></span>`;
        });
        preview.innerHTML = badgesHtml;
        if (label) label.classList.add('attached');
    } else {
        preview.innerHTML = '';
        if (label) label.classList.remove('attached');
    }
}

function removeSubFile(index) {
    subSelectedFiles.splice(index, 1);
    renderSubFilePreview();
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
        let jintsu_permit = parseInt(document.getElementById('est_jintsu_permit').value) || 0;
        let moya_permit = parseInt(document.getElementById('est_moya_permit').value) || 0;
        let slant_no_bearing = parseInt(document.getElementById('est_slant_wall_no_bearing').value) || 0;
        let slant_bearing = parseInt(document.getElementById('est_slant_wall_bearing').value) || 0;
        
        // 全部の等級をリストアップしたいが、selectなので選ばれたものだけが確定。他もリストアップするか？
        // Selectは選択されたもの以外は「0円」などのオプションなので、選択されたものだけを表示でOKとする。
        // もし全部見せたいなら固定でリストアップする必要がある。
        // "等級加算なし" でも行としては出す
        let gradeName = "許容応力度計算 目標等級加算";
        if(grade == 40000) gradeName = "許容応力度計算 目標等級加算 (耐震3/耐風2等)";
        if(grade == 20000) gradeName = "許容応力度計算 目標等級加算 (耐震2)";
        currentEstimate += pushEstimateItem(gradeName, 1, "式", grade, grade > 0);

        currentEstimate += pushEstimateItem("許容応力度計算 金物工法割増", kanamono > 0 ? kanamono : 1, "階", 15000, kanamono > 0);
        currentEstimate += pushEstimateItem("許容応力度計算 人通口補強計算", 1, "式", jintsu_permit, jintsu_permit > 0);
        currentEstimate += pushEstimateItem("許容応力度計算 母屋下がり加算", moya_permit > 0 ? moya_permit : 1, "箇所", 15000, moya_permit > 0);
        currentEstimate += pushEstimateItem("許容応力度計算 斜め壁等（耐力壁なし）", slant_no_bearing > 0 ? slant_no_bearing : 1, "箇所", 15000, slant_no_bearing > 0);
        currentEstimate += pushEstimateItem("許容応力度計算 斜め壁等（耐力壁あり）", slant_bearing > 0 ? slant_bearing : 1, "箇所", 30000, slant_bearing > 0);
        
        const el_opt_kisohari = document.getElementById('est_opt_kisohari_calc');
        const opt_kisohari = el_opt_kisohari ? el_opt_kisohari.checked : false;
        currentEstimate += pushEstimateItem("許容応力度計算 基礎横架材計算", 1, "式", 15000, opt_kisohari);
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
        let moya_qty = parseInt(document.getElementById('est_moya_wall').value) || 0;
        
        currentEstimate += pushEstimateItem("性能表示壁量計算 構造図(基礎伏図)作成", 1, "式", dwg, dwg > 0);
        currentEstimate += pushEstimateItem("性能表示壁量計算 人通孔箇所数割増", 1, "式", jintsu, jintsu > 0);
        currentEstimate += pushEstimateItem("性能表示壁量計算 母屋下がり加算", moya_qty > 0 ? moya_qty : 1, "箇所", 15000, moya_qty > 0);
        
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
    
    // 5. 手動追加明細の処理
    document.querySelectorAll('.manual-est-row').forEach(row => {
        const nameInput = row.querySelector('.manual-est-name');
        const priceInput = row.querySelector('.manual-est-price');
        if (nameInput && priceInput) {
            const name = nameInput.value.trim();
            const price = parseInt(priceInput.value) || 0;
            if (name !== '' && price !== 0) {
                currentEstimate += pushEstimateItem(name, 1, "式", price, true);
            }
        }
    });
    
    currentTax = Math.round(currentEstimate * 0.1);
    currentTotal = currentEstimate + currentTax;
    
    const elTotal = document.getElementById('est_total_disp');
    if (elTotal) elTotal.innerText = currentEstimate.toLocaleString();
    
    const elTax = document.getElementById('est_tax_disp');
    if (elTax) elTax.innerText = currentTax.toLocaleString();
    
    const elGrand = document.getElementById('est_grand_total_disp');
    if (elGrand) elGrand.innerText = currentTotal.toLocaleString();
}

function addManualEstimateRow() {
    const container = document.getElementById('manual_estimates_container');
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'manual-est-row';
    div.style.display = 'flex';
    div.style.gap = '5px';
    div.style.marginBottom = '5px';
    div.style.alignItems = 'center';
    div.innerHTML = `
        <input type="text" placeholder="項目名" class="manual-est-name" oninput="calcClientEstimate()" onfocus="if(this.value==='') { this.value='　'; this.setSelectionRange(0, 1); setTimeout(() => { if(this.value==='　') { this.value=''; } }, 20); }" style="flex:1; padding:3px; font-size:11px; ime-mode: active;" inputmode="text" lang="ja" required>
        <input type="text" placeholder="金額(税抜)" class="manual-est-price" oninput="this.value = this.value.replace(/[^0-9]/g, ''); calcClientEstimate();" style="width:80px; padding:3px; font-size:11px; ime-mode: disabled;" inputmode="numeric" pattern="[0-9]*" required>
        <button type="button" onclick="this.parentElement.remove(); calcClientEstimate();" style="background:#ef4444; color:white; border:none; padding:2px 5px; border-radius:3px; cursor:pointer; font-weight:bold;">✕</button>
    `;
    container.appendChild(div);

    // 追加された項目名入力欄に自動でフォーカスをあてる
    const inputName = div.querySelector('.manual-est-name');
    if (inputName) {
        inputName.focus();
    }
}

function saveAndPrintEstimate(isFormal = false, isAdditional = false) {
    calcClientEstimate();
    if (currentEstimate === 0) {
        alert('計算する対象を選択してください。');
        return;
    }
    
    let btn = document.getElementById('pdf_issue_btn');
    if (isFormal) btn = document.getElementById('formal_pdf_issue_btn');
    if (isAdditional) btn = document.getElementById('additional_pdf_issue_btn');
    
    if (btn) {
        btn.disabled = true;
        if (isFormal) btn.innerText = '本見積確定中...';
        else if (isAdditional) btn.innerText = '追加見積発行中...';
        else btn.innerText = 'PDF発行中...';
    }
    
    // シミュレーターの入力値を収集
    const inputs = {};
    const estimatorInputs = document.querySelectorAll('[id^="est_active_"], [id^="est_base_"], [id^="est_area_"], [id^="est_mult_"], [id^="est_grade_"], [id^="est_kanamono_"], [id^="est_special_"], [id^="est_dwg_"], [id^="est_jintsu_"], [id^="est_moya_"], [id^="est_kisohari_"], [id^="est_kisotachi_"], [id^="est_setsumei_"], [id^="est_road_"], [id^="est_north_"], [id^="est_extra_"], [id^="est_site_area_"], [id^="est_building_area_"], [id^="est_detail_"], [id^="est_slant_"]');
    estimatorInputs.forEach(el => {
        if (el.type === 'checkbox') {
            inputs[el.id] = el.checked;
        } else {
            inputs[el.id] = el.value;
        }
    });
    // 手動追加項目も収集
    const manualItems = [];
    document.querySelectorAll('.manual-est-row').forEach(row => {
        const nameInput = row.querySelector('.manual-est-name');
        const priceInput = row.querySelector('.manual-est-price');
        if (nameInput && priceInput) {
            manualItems.push({
                name: nameInput.value,
                price: priceInput.value
            });
        }
    });
    inputs['manual_items'] = manualItems;

    const formData = new FormData();
    formData.append('project_id', window.APP_PROJECT_ID);
    formData.append('total_price', currentEstimate);
    formData.append('note', JSON.stringify(estimateItems));
    formData.append('is_formal', isFormal ? '1' : '0');
    formData.append('is_additional', isAdditional ? '1' : '0');
    formData.append('inputs_json', JSON.stringify(inputs));
    
    fetch('api_save_estimate.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.drive_file_id) {
                // 発行成功時にチャットへも自動送信するロジック
                let msg = "【お見積書が発行されました】\n";
                if (isFormal) msg = "【本見積書が確定・発行されました】\n";
                if (isAdditional) msg = "【追加見積書が確定・発行されました】\n";
                
                msg += `税抜金額: ${currentEstimate.toLocaleString()}円\n`;
                msg += `消費税: ${currentTax.toLocaleString()}円\n`;
                msg += `税込合計: ${currentTotal.toLocaleString()}円\n\n`;
                msg += "詳細は左パネルの最新の見積書からご確認ください。\n";
                msg += "\n";
                if (isFormal) {
                    msg += "本見積内容にて確定いたしました。追って一次請求書（着手金50%）を発行いたしますので、ご確認のほどよろしくお願いいたします。";
                } else if (isAdditional) {
                    msg += "追加見積内容にて確定いたしました。経理・請求管理より追加入金履歴に反映されます。";
                } else {
                    msg += "ご依頼いただける場合は、「設計依頼データの送付」ボタンから必須ファイル（CADデータ等）をアップロードの上、正式にご発注をお願いいたします。";
                }

                // sendMessageを使ってチャットへ投稿（APIへPOST）
                const chatData = new FormData();
                chatData.append('project_id', window.APP_PROJECT_ID);
                chatData.append('message_text', msg);
                chatData.append('thread_type', 'client_admin');

                return fetch('api_send_message.php', { method: 'POST', body: chatData })
                    .then(chatRes => chatRes.json())
                    .then(() => {
                        let alertMsg = 'お見積書PDFが作成され、チャットへ送信されました。';
                        if (isFormal) alertMsg = '本見積書が確定し、PDFが作成されてチャットへ送信されました。';
                        if (isAdditional) alertMsg = '追加見積書が確定し、PDFが作成されてチャットへ送信されました。';
                        alert(alertMsg);
                        window.open(`https://drive.google.com/file/d/${data.drive_file_id}/view?usp=drivesdk`, '_blank');
                        location.reload();
                    });
            } else {
                alert('見積保存に失敗しました\nエラー詳細: ' + (data.error || '不明なエラー'));
            }
        })
        .catch(e => alert('通信エラー: ' + e))
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                if (isFormal) btn.innerText = '本見積として確定・PDF発行';
                else if (isAdditional) btn.innerText = '追加見積として確定・PDF発行';
                else btn.innerText = '初回見積PDFを発行';
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

function deleteChatMessage(msgId) {
    if (!confirm('このメッセージを取り消しますか？')) return;
    const formData = new FormData();
    formData.append('message_id', msgId);

    fetch('api_delete_message.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // 画面から該当バブルを削除
                const bubbleRow = document.querySelector(`[data-msg-id="${msgId}"]`);
                if (bubbleRow) {
                    bubbleRow.remove();
                } else {
                    location.reload();
                }
            } else {
                alert(data.error || 'メッセージの取り消しに失敗しました。');
            }
        }).catch(e => alert('通信エラー: ' + e));
}
