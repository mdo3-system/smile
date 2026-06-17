with open('project_subcontractor.php', 'r', encoding='utf-8', newline='') as f:
    content = f.read()

# 1. Replace the checklist inside structural form
old_form_checklist = """                                                        <!-- 構造図チェックリスト (12項目) -->
                                                        <div style="margin-bottom: 12px; border: 1px solid #fed7aa; background: #fff7ed; padding: 12px; border-radius: 6px;">
                                                            <strong style="color: #c2410c; display: block; margin-bottom: 8px; font-size: 13px;">📝 構造図作図時チェック項目 (全項目確認必須):</strong>
                                                            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px; line-height: 1.4;">
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>1. 意匠図の不整合（柱・耐力壁・サイズ等）の有無を相互チェックしました。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>2. 土台・大引・柱・横架材・小屋束・母屋・棟木・垂木・金物・耐力壁は指定した部材寸法（木材・金物）と合致している。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>3. プレカットの打ち合わせ内容（配置等）と合致している。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>4. 火打梁（梁・床面）は設定されている。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>5. 吹き抜け・階段・バルコニーまわりの補強梁は設定されている。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>6. 基礎伏せ、基礎断面の寸法（深さ・立ち上がり等）は意匠図および構造上の要件と合致している。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>7. 耐力壁の位置は意匠図の筋交いや面材の位置と整合している。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>8. 柱・耐力壁の直下率を意識し、不整合（偏心・耐力バランス）がないか確認した。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>9. アンカーボルト、ホールダウン金物の位置は確認した。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>10. 各階の平面図、立面図、断面図と構造部材の干渉（窓・ダクト・階段等）がないか確認した。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>11. 特記仕様書の設計基準（積雪荷重、風圧力、地震力等）を正しく設定した。</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>12. 疑義がある場合は作業を中断し、管理者と相談して解決済みである。</span>
                                                                </label>
                                                            </div>
                                                        </div>"""

new_form_checklist = """                                                        <!-- 構造図チェックリスト (12項目) -->
                                                        <div style="margin-bottom: 12px; border: 1px solid #fed7aa; background: #fff7ed; padding: 12px; border-radius: 6px;">
                                                            <strong style="color: #c2410c; display: block; margin-bottom: 8px; font-size: 13px;">📝 構造図作図時チェック項目 (全項目確認必須):</strong>
                                                            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px; line-height: 1.4;">
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>1. 図枠は依頼者の図枠として下さい</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>2. アーキのデータだけではなく、PDFの書き込みファイルを参照し、不整合あれば必ず通知してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>3. 基礎断面図には、設計GLと平均GLあるときは平均GLともに記載してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>4. 柱下には必ず通り芯が入っていることを確認してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>5. 通り芯間距離を明示してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>6. 金物凡例を計算書と整合してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>7. 構造材他、計算書と整合を確認してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>8. 見附面積、断面図、軸組図の整合を確認してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>9. 耐力壁の凡例、認定番号、釘種、ピッチ、受け材など必要事項を明示してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>10. 地盤調査未了時の令96条但し書き記載してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>11. 小屋筋違いについて記載してください</span>
                                                                </label>
                                                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                                    <input type="checkbox" class="struct-deliver-check" style="margin-top: 2px;">
                                                                    <span>12. 横架材接合部が凡例以外の時の記載を確認してください</span>
                                                                </label>
                                                            </div>
                                                        </div>"""

# 2. Replace the checklist inside structural modal
old_modal_checklist = """                <div id="struct_checklist_modal" style="display:none;">
                    <strong style="color: #c2410c; display:block; margin-bottom:5px;">構造図作図基準チェック項目:</strong>
                    <ul style="margin:0; padding-left:20px; line-height:1.8;">
                        <li>1. 意匠図の不整合（柱・耐力壁・サイズ等）の有無を相互チェックしました。</li>
                        <li>2. 土台・大引・柱・横架材・小屋束・母屋・棟木・垂木・金物・耐力壁は指定した部材寸法（木材・金物）と合致している。</li>
                        <li>3. プレカットの打ち合わせ内容（配置等）と合致している。</li>
                        <li>4. 火打梁（梁・床面）は設定されている。</li>
                        <li>5. 吹き抜け・階段・バルコニーまわりの補強梁は設定されている。</li>
                        <li>6. 基礎伏せ、基礎断面の寸法（深さ・立ち上がり等）は意匠図および構造上の要件と合致している。</li>
                        <li>7. 耐力壁の位置は意匠図の筋交いや面材の位置と整合している。</li>
                        <li>8. 柱・耐力壁の直下率を意識し、不整合（偏心・耐力バランス）がないか確認した。</li>
                        <li>9. アンカーボルト、ホールダウン金物の位置は確認した。</li>
                        <li>10. 各階の平面図、立面図、断面図と構造部材の干渉（窓・ダクト・階段等）がないか確認した。</li>
                        <li>11. 特記仕様書の設計基準（積雪荷重、風圧力、地震力等）を正しく設定した。</li>
                        <li>12. 疑義がある場合は作業を中断し、管理者と相談して解決済みである。</li>
                    </ul>
                </div>"""

new_modal_checklist = """                <div id="struct_checklist_modal" style="display:none;">
                    <strong style="color: #c2410c; display:block; margin-bottom:5px;">構造図作図基準チェック項目:</strong>
                    <ul style="margin:0; padding-left:20px; line-height:1.8;">
                        <li>1. 図枠は依頼者の図枠として下さい</li>
                        <li>2. アーキのデータだけではなく、PDFの書き込みファイルを参照し、不整合あれば必ず通知してください</li>
                        <li>3. 基礎断面図には、設計GLと平均GLあるときは平均GLともに記載してください</li>
                        <li>4. 柱下には必ず通り芯が入っていることを確認してください</li>
                        <li>5. 通り芯間距離を明示してください</li>
                        <li>6. 金物凡例を計算書と整合してください</li>
                        <li>7. 構造材他、計算書と整合を確認してください</li>
                        <li>8. 見附面積、断面図、軸組図の整合を確認してください</li>
                        <li>9. 耐力壁の凡例、認定番号、釘種、ピッチ、受け材など必要事項を明示してください</li>
                        <li>10. 地盤調査未了時の令96条但し書き記載してください</li>
                        <li>11. 小屋筋違いについて記載してください</li>
                        <li>12. 横架材接合部が凡例以外の時の記載を確認してください</li>
                    </ul>
                </div>"""

# Replace in content (handling CRLF/LF line endings safely by normalization first)
normalized_content = content.replace('\\r\\n', '\\n')
normalized_old_form = old_form_checklist.replace('\\r\\n', '\\n')
normalized_new_form = new_form_checklist.replace('\\r\\n', '\\n')
normalized_old_modal = old_modal_checklist.replace('\\r\\n', '\\n')
normalized_new_modal = new_modal_checklist.replace('\\r\\n', '\\n')

if normalized_old_form in normalized_content:
    normalized_content = normalized_content.replace(normalized_old_form, normalized_new_form)
    print("Replaced structural checklist in form!")
else:
    print("Could not find structural checklist in form via exact match. Checking alternatives...")

if normalized_old_modal in normalized_content:
    normalized_content = normalized_content.replace(normalized_old_modal, normalized_new_modal)
    print("Replaced structural checklist in modal!")
else:
    print("Could not find structural checklist in modal via exact match. Checking alternatives...")

with open('project_subcontractor.php', 'w', encoding='utf-8', newline='') as f:
    f.write(normalized_content)
