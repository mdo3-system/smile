<?php if ($is_admin && $project['status'] === 'quote_req'): ?>
            <h2 class="section-title" style="background:#28a745; margin-top:20px;">💰 依頼主宛 自動見積シミュレーター</h2>
            <div class="box" style="background:#e8f5e9;">
                <div style="font-size:12px; margin-bottom:10px;">
                    この案件の見積を算出し、チャットに送信できます。
                </div>
                
                <div style="font-size:11px; margin-bottom:10px; display:grid; gap:8px;">
                    <div>
                        <strong>基本料金（構造）</strong><br>
                        <select id="est_base" style="width:100%; font-size:11px; padding:3px;">
                            <option value="75000">構造計算 平屋建・2階建 (75,000円)</option>
                            <option value="100000">構造計算 3階建 (100,000円)</option>
                        </select>
                    </div>
                    <div>
                        <strong>構造床面積 (㎡)</strong><br>
                        <input type="number" id="est_area" value="100" style="width:100%; font-size:11px; padding:3px;">
                        <span style="color:#666;">※150㎡以上は1㎡につき600円加算</span>
                    </div>
                    <div>
                        <strong>目標等級加算</strong><br>
                        <select id="est_grade" style="width:100%; font-size:11px; padding:3px;">
                            <option value="0">なし (0円)</option>
                            <option value="40000">耐震等級3+耐風等級2 (+40,000円)</option>
                            <option value="20000">耐震等級2 (+20,000円)</option>
                            <option value="40000">耐震等級3 (+40,000円)</option>
                            <option value="40000">耐風等級2 (+40,000円)</option>
                        </select>
                    </div>
                    <div>
                        <strong>形状加算等（基本料金+面積割増に乗算）</strong><br>
                        <label><input type="checkbox" class="est_multiplier" value="0.2"> 準耐火/耐火構造 (+20%)</label><br>
                        <label><input type="checkbox" class="est_multiplier" value="0.2"> PH階がある (+20%)</label><br>
                        <label><input type="checkbox" class="est_multiplier" value="0.1"> 小屋裏収納がある (+10%)</label><br>
                        <label><input type="checkbox" class="est_multiplier" value="0.1"> スキップ等レベル違い (+10%)</label><br>
                        <label><input type="checkbox" class="est_multiplier" value="1.0"> 平面不整形 (+100%)</label><br>
                        <label><input type="checkbox" class="est_multiplier" value="1.0"> 立面不整形 (+100%)</label>
                    </div>
                    <div>
                        <strong>その他加算（固定額）</strong><br>
                        <label>金物工法階数: <input type="number" id="est_kanamono" value="0" style="width:40px; font-size:11px;"> 階 (+15,000円/階)</label><br>
                        <label>斜め壁等特殊箇所数: <input type="number" id="est_special" value="0" style="width:40px; font-size:11px;"> 箇所 (+15,000円/箇所)</label>
                    </div>
                </div>

                <div style="margin-top:10px; padding-top:10px; border-top:1px solid #ccc; font-weight:bold;">
                    見積合計: <span id="est_total_disp" style="color:#d32f2f; font-size:14px;">0</span> 円 (税別)
                </div>

                <div style="margin-top:10px; display:flex; gap:10px;">
                    <button type="button" onclick="calcClientEstimate()" style="flex:1; background:#fff; border:1px solid #28a745; color:#28a745; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">再計算</button>
                    <button type="button" onclick="sendClientEstimate()" style="flex:1; background:#28a745; border:none; color:white; padding:5px; font-size:11px; cursor:pointer; font-weight:bold; border-radius:3px;">チャットに見積を送信</button>
                </div>
            </div>

            <script>
            let currentEstimate = 0;
            function calcClientEstimate() {
                let base = parseInt(document.getElementById('est_base').value) || 0;
                let area = parseFloat(document.getElementById('est_area').value) || 0;
                
                // 面積割増 (150平米以上)
                let area_extra = 0;
                if (area > 150) {
                    area_extra = Math.ceil(area - 150) * 600;
                }
                
                let base_with_area = base + area_extra;

                // 形状加算 (乗算)
                let multiplier = 0;
                document.querySelectorAll('.est_multiplier:checked').forEach(cb => {
                    multiplier += parseFloat(cb.value);
                });
                let shape_extra = Math.round(base_with_area * multiplier);

                // 等級加算
                let grade_extra = parseInt(document.getElementById('est_grade').value) || 0;

                // その他加算
                let kanamono = parseInt(document.getElementById('est_kanamono').value) || 0;
                let special = parseInt(document.getElementById('est_special').value) || 0;
                let other_extra = (kanamono * 15000) + (special * 15000);

                currentEstimate = base_with_area + shape_extra + grade_extra + other_extra;
                document.getElementById('est_total_disp').innerText = currentEstimate.toLocaleString();
            }

            function sendClientEstimate() {
                calcClientEstimate();
                if (currentEstimate === 0) return;
                
                const tax = Math.round(currentEstimate * 0.1);
                const total = currentEstimate + tax;
                
                let msg = `【概算お見積り】\n構造計算等の概算見積を算出いたしました。\n\n`;
                msg += `税抜金額: ${currentEstimate.toLocaleString()}円\n`;
                msg += `消費税: ${tax.toLocaleString()}円\n`;
                msg += `税込合計: ${total.toLocaleString()}円\n\n`;
                msg += `よろしければ正式にご依頼ください。`;

                // フォームにセットして送信
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'project_detail.php?id=<?= $project_id ?>';
                
                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'action';
                inputAction.value = 'send_message';
                form.appendChild(inputAction);

                const inputText = document.createElement('input');
                inputText.type = 'hidden';
                inputText.name = 'message_text';
                inputText.value = msg;
                form.appendChild(inputText);

                document.body.appendChild(form);
                form.submit();
            }

            // 初回計算
            window.addEventListener('DOMContentLoaded', calcClientEstimate);
            </script>
            <?php endif; ?>
