import re
import os

file_path = os.path.join(os.path.dirname(__file__), '../project_subcontractor.php')
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 改行コードをLFに統一
content_lf = content.replace('\r\n', '\n')

# 1. 各タスクのループの直前にタスク抽出処理を追加する
target_pattern = r'<!-- 各発注タスクの処理 -->\s*<\?php foreach \(\$proj\[\'tasks\'\] as \$task\): \?\>'
replace_before_loop = """<!-- 各発注タスクの処理 -->
                        <?php
                        $design_task = null;
                        $struct_task = null;
                        foreach ($proj['tasks'] as $t) {
                            if ($t['status'] === 'cancelled') continue;
                            if (($t['order_type'] ?? 'design') === 'design') {
                                if (!$design_task || $t['status'] !== 'completed') {
                                    $design_task = $t;
                                }
                            } else {
                                if (!$struct_task || $t['status'] !== 'completed') {
                                    $struct_task = $t;
                                }
                            }
                        }
                        ?>
                        <?php foreach ($proj['tasks'] as $task): ?>"""

if re.search(target_pattern, content_lf):
    content_lf = re.sub(target_pattern, replace_before_loop, content_lf)
    print("1. Inserted task scan logic.")
else:
    print("1. FAILED to find target_pattern")

# 2. task ループ内の delivery-section （764〜938行目に相当）を削除し、ループ閉じの辻褄を合わせる
pattern = r'<\?php if \(\$task\[\'status\'\] \!== \'cancelled\'\):\s*\?\>\s*<\?php\s*.*?\$show_struct_delivery = .*?\?\>\s*<div class="delivery-section".*?<!--\s*■\s*構造図の納品エリア\s*-->.*?</div>\s*<?php endif;\s*\?>\s*</div>\s*<?php endif;\s*\?>'
# Wait, let's look at the structure:
# <?php if ($task['status'] !== 'cancelled'): ?>
# ... <div class="delivery-section" ...> ... </div> (which ends with <?php endif; ?> and </div> and <?php endif; ?>)
# Let's make a very safe non-greedy match of the delivery-section block:
pattern = r'<\?php if \(\$task\[\'status\'\] \!== \'cancelled\'\):\s*\?\>\s*<\?php\s*\$show_struct_delivery\s*=\s*\(\$project_info\[\'req_permit\'\]\s*==\s*1\s*\|\|\s*\$project_info\[\'req_opt_kisohari\'\]\s*==\s*1\);\s*\?\>\s*<div class="delivery-section".*?<!--\s*■\s*構造図の納品エリア\s*-->.*?</div>\s*<\?php endif;\s*\?\>\s*</div>\s*<\?php endif;\s*\?\>'
match = re.search(pattern, content_lf, re.DOTALL)

if match:
    content_lf = content_lf.replace(match.group(0), "")
    print("2. Removed delivery-section from inside the task loop.")
else:
    # 寛容なパターン2
    pattern2 = r'<\?php if \(\$task\[\'status\'\] \!== \'cancelled\'\):\s*\?\>\s*<\?php\s*.*?\$show_struct_delivery\s*=.*?<div class="delivery-section".*?</div>\s*<\?php endif;\s*\?\>\s*</div>\s*<\?php endif;\s*\?\>'
    match2 = re.search(pattern2, content_lf, re.DOTALL)
    if match2:
        content_lf = content_lf.replace(match2.group(0), "")
        print("2. Removed delivery-section from inside the task loop (fallback).")
    else:
        print("2. FAILED to match delivery section pattern inside task loop.")

# 3. ループ終了 <?php endforeach; ?> の直後に、統合された納品ボックスを挿入する
foreach_end = "                        <?php endforeach; ?>"
delivery_section_cleaned = """
                        <!-- 成果物の納品エリア (プロジェクトにつき最大1つずつに統合) -->
                        <?php if (($design_task && $design_task['status'] !== 'completed') || ($struct_task && $struct_task['status'] !== 'completed' && ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1))): ?>
                            <div class="delivery-section" style="border:1px solid #e2e8f0; background:#fdfdfd; padding:15px; border-radius:6px; font-size:13px; display:flex; flex-direction:column; gap:20px; margin-top: 10px;">
                                <strong>📤 成果物（作成した図面）の納品・差し替え:</strong>
                                <p style="font-size:11px; color:#666; margin:-5px 0 5px 0;">※個別にアップロード可能です。差し替えた場合も履歴が残ります。</p>

                                <?php if ($design_task && $design_task['status'] !== 'completed'): ?>
                                    <!-- ■ 意匠図の納品エリア -->
                                    <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:6px;">
                                        <strong style="color:#0f172a; font-size:14px; display:block; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">📐 意匠図の納品・差し替え</strong>
                                        <form id="design_deliver_form_<?php echo $design_task['id']; ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;" onsubmit="return false;">
                                            <input type="hidden" name="action" value="deliver_task">
                                            <input type="hidden" name="order_id" value="<?php echo $design_task['id']; ?>">
                                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                            <input type="hidden" name="deliver_type" value="design">
                                            
                                            <div style="margin-bottom: 12px; border: 1px solid #fed7aa; background: #fff7ed; padding: 12px; border-radius: 6px;">
                                                <strong style="color: #c2410c; display: block; margin-bottom: 8px; font-size: 13px;">📝 意匠図作図基準チェック項目 (全項目確認必須):</strong>
                                                <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px; line-height: 1.4;">
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>新規ﾃﾞｰﾀ作成からの作図</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>ｸﾞﾘｯﾄﾞ、ﾓｼﾞｭｰﾙの設定は意匠図に合わせる</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>高さの設定（設定→物件初期設定→基準高さ情報、平均GLからの高さとする、構造では平均GLは基礎高さで調整する）</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>車庫・吹き抜け・階段 of 部屋属性、室内の部屋を外部部屋としない</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>最高（屋根）の高さは軒高での調整はNG、屋根属性で調整、最後の手段で屋根厚で調整</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>屋根仕上げが矩計で読めたら屋根材は図面通りとする</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>軒の出、ｹﾗﾊﾞの出は図面に整合させる。Minは130とする。</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>屋根属性：垂木WHとﾋﾟｯﾁは矩計図と整合させる</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>ﾊﾞﾙｺﾆｰの仕上げは一般外壁と同じものとする</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>窓ｻｲｽﾞWHと設置高さはできる限り意匠図に整合</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>不整合に気づいたら報告する</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>柱は四角内に×表示とする</span>
                                                    </label>
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; user-select: none;">
                                                        <input type="checkbox" class="design-deliver-check" style="margin-top: 2px;">
                                                        <span>疑義あるときは作業をすすめないで相談する</span>
                                                    </label>
                                                </div>
                                            </div>

                                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                                                <label style="width:150px; font-weight:bold; color:#0056b3;">意匠図用アーキデータ:</label>
                                                <input type="file" name="architrend_design" style="font-size:12px;">
                                            </div>

                                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                                <button type="button" style="background:#28a745; color:white; border:none; padding:8px 18px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="handleIndividualDeliverSubmit(event, this, false, 'design')">意匠図ファイルを納品</button>
                                                <button type="button" style="background:#0284c7; color:white; border:none; padding:8px 18px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="handleIndividualDeliverSubmit(event, this, true, 'design')">☁ 意匠図アーキサーバーUP報告</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php 
                                $show_struct_delivery = ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1);
                                if ($struct_task && $struct_task['status'] !== 'completed' && $show_struct_delivery): 
                                ?>
                                    <!-- ■ 構造図の納品エリア -->
                                    <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:6px;">
                                        <strong style="color:#0f172a; font-size:14px; display:block; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">🏗 構造図の納品・差し替え</strong>
                                        <form id="struct_deliver_form_<?php echo $struct_task['id']; ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;" onsubmit="return false;">
                                            <input type="hidden" name="action" value="deliver_task">
                                            <input type="hidden" name="order_id" value="<?php echo $struct_task['id']; ?>">
                                            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                            <input type="hidden" name="deliver_type" value="struct">
                                            
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
                                            </div>

                                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                <label style="width:150px; font-weight:bold; color:#0056b3;">構造図用アーキデータ:</label>
                                                <input type="file" name="architrend_struct" style="font-size:12px;">
                                            </div>
                                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                                                <label style="width:150px; font-weight:bold; color:#dc3545;">構造図PDF:</label>
                                                <input type="file" name="structural_pdf" style="font-size:12px;">
                                            </div>

                                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                                <button type="button" style="background:#28a745; color:white; border:none; padding:8px 18px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="handleIndividualDeliverSubmit(event, this, false, 'struct')">構造図ファイルを納品</button>
                                                <button type="button" style="background:#0284c7; color:white; border:none; padding:8px 18px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="handleIndividualDeliverSubmit(event, this, true, 'struct')">☁ 構造図アーキサーバーUP報告</button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
"""

if foreach_end in content_lf:
    content_lf = content_lf.replace(foreach_end, foreach_end + "\n" + delivery_section_cleaned)
    print("3. Inserted consolidated delivery-section after loop end.")
else:
    print("3. FAILED to find foreach_end")

# 4. 発注履歴での納品ファイル重複結合バグの修正
history_block_pattern = """<?php if (!empty($o['pdf_id']) || !empty($o['arc_d_id']) || !empty($o['arc_s_id'])): ?>
                                    <div style="margin-top:8px; padding:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                                        <strong style="color:#334155; font-size:12px;">📤 納品ファイル一覧:</strong>
                                        <ul style="margin:4px 0 0 0; padding-left:20px; font-size:12px;">
                                            <?php if (!empty($o['arc_d_id'])): 
                                                $d_url = (strpos($o['arc_d_id'], 'uploads/') === 0) ? $o['arc_d_id'] : 'https://drive.google.com/file/d/' . $o['arc_d_id'] . '/view?usp=drivesdk';
                                            ?>
                                                <li>意匠用アーキ: <a href="<?= htmlspecialchars($d_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['arc_d_name'], ENT_QUOTES) ?> (V<?= $o['arc_d_ver'] ?>)</a></li>
                                            <?php endif; ?>
                                            <?php if (!empty($o['arc_s_id'])): 
                                                $s_url = (strpos($o['arc_s_id'], 'uploads/') === 0) ? $o['arc_s_id'] : 'https://drive.google.com/file/d/' . $o['arc_s_id'] . '/view?usp=drivesdk';
                                            ?>
                                                <li>構造用アーキ: <a href="<?= htmlspecialchars($s_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['arc_s_name'], ENT_QUOTES) ?> (V<?= $o['arc_s_ver'] ?>)</a></li>
                                            <?php endif; ?>
                                            <?php if (!empty($o['pdf_id'])): 
                                                $pdf_url = (strpos($o['pdf_id'], 'uploads/') === 0) ? $o['pdf_id'] : 'https://drive.google.com/file/d/' . $o['pdf_id'] . '/view?usp=drivesdk';
                                                $is_published = ($o['status'] === 'completed');
                                            ?>
                                                <li>
                                                     構造図PDF: <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['pdf_name'], ENT_QUOTES) ?> (V<?= $o['pdf_ver'] ?>)</a>
                                                     <?php if ($is_published): ?>
                                                         <span class="badge" style="background:#28a745; color:white; font-size:10px; padding:2px 5px; border-radius:3px; margin-left:5px;">公開中</span>
                                                     <?php else: ?>
                                                         <span class="badge" style="background:#dc3545; color:white; font-size:10px; padding:2px 5px; border-radius:3px; margin-left:5px;">未公開</span>
                                                     <?php endif; ?>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>"""

history_block_replacement = """<?php 
                                 $has_matching_files = false;
                                 $is_design_order = (($o['order_type'] ?? 'design') === 'design');
                                 if ($is_design_order && !empty($o['arc_d_id'])) $has_matching_files = true;
                                 if (!$is_design_order && (!empty($o['arc_s_id']) || !empty($o['pdf_id']))) $has_matching_files = true;
                                 if ($has_matching_files): 
                                 ?>
                                     <div style="margin-top:8px; padding:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                                         <strong style="color:#334155; font-size:12px;">📤 納品ファイル一覧:</strong>
                                         <ul style="margin:4px 0 0 0; padding-left:20px; font-size:12px;">
                                             <?php if ($is_design_order && !empty($o['arc_d_id'])): 
                                                 $d_url = (strpos($o['arc_d_id'], 'uploads/') === 0) ? $o['arc_d_id'] : 'https://drive.google.com/file/d/' . $o['arc_d_id'] . '/view?usp=drivesdk';
                                             ?>
                                                 <li>意匠用アーキ: <a href="<?= htmlspecialchars($d_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['arc_d_name'], ENT_QUOTES) ?> (V<?= $o['arc_d_ver'] ?>)</a></li>
                                             <?php endif; ?>
                                             <?php if (!$is_design_order && !empty($o['arc_s_id'])): 
                                                 $s_url = (strpos($o['arc_s_id'], 'uploads/') === 0) ? $o['arc_s_id'] : 'https://drive.google.com/file/d/' . $o['arc_s_id'] . '/view?usp=drivesdk';
                                             ?>
                                                 <li>構造用アーキ: <a href="<?= htmlspecialchars($s_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['arc_s_name'], ENT_QUOTES) ?> (V<?= $o['arc_s_ver'] ?>)</a></li>
                                             <?php endif; ?>
                                             <?php if (!$is_design_order && !empty($o['pdf_id'])): 
                                                 $pdf_url = (strpos($o['pdf_id'], 'uploads/') === 0) ? $o['pdf_id'] : 'https://drive.google.com/file/d/' . $o['pdf_id'] . '/view?usp=drivesdk';
                                                 $is_published = ($o['status'] === 'completed');
                                             ?>
                                                 <li>
                                                     構造図PDF: <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($o['pdf_name'], ENT_QUOTES) ?> (V<?= $o['pdf_ver'] ?>)</a>
                                                     <?php if ($is_published): ?>
                                                         <span class="badge" style="background:#28a745; color:white; font-size:10px; padding:2px 5px; border-radius:3px; margin-left:5px;">公開中</span>
                                                     <?php else: ?>
                                                         <span class="badge" style="background:#dc3545; color:white; font-size:10px; padding:2px 5px; border-radius:3px; margin-left:5px;">未公開</span>
                                                     <?php endif; ?>
                                                 </li>
                                             <?php endif; ?>
                                         </ul>
                                     </div>
                                 <?php endif; ?>"""

history_block_pattern_lf = history_block_pattern.replace('\r\n', '\n')
history_block_replacement_lf = history_block_replacement.replace('\r\n', '\n')

if history_block_pattern_lf in content_lf:
    content_lf = content_lf.replace(history_block_pattern_lf, history_block_replacement_lf)
    print("4. Updated history matching file rendering.")
else:
    # 部分的な正規表現マッチングでフォールバック
    pattern_sub = r'<\?php if \(!empty\(\$o\[\'pdf_id\'\]\).*?\<\/ul\>\s*\<\/div\>\s*\<\?php endif; \?\>'
    match_sub = re.search(pattern_sub, content_lf, re.DOTALL)
    if match_sub:
        content_lf = content_lf.replace(match_sub.group(0), history_block_replacement_lf)
        print("4. Updated history matching file rendering (regex fallback).")
    else:
        print("4. FAILED to find history block pattern.")

# CRLFに復元して保存
final_content = content_lf.replace('\n', '\r\n')
with open(file_path, 'w', encoding='utf-8') as f:
    f.write(final_content)
print("Finished!")
