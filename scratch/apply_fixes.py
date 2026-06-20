import re
import os

def replace_in_file(filepath, target, replacement):
    with open(filepath, 'r', encoding='utf-8', newline='') as f:
        content = f.read()
    
    # 改行コードの差異を無視してマッチングするために、CRLFとLFを統一して置換する
    normalized_content = content.replace('\r\n', '\n')
    normalized_target = target.replace('\r\n', '\n')
    normalized_replacement = replacement.replace('\r\n', '\n')
    
    if normalized_target not in normalized_content:
        print(f"Warning: Target not found in {filepath}")
        # 部分一致のヒントを出力する
        return False
        
    new_content = normalized_content.replace(normalized_target, normalized_replacement)
    
    # 元のファイルの改行コードに合わせて保存
    if '\r\n' in content:
        new_content = new_content.replace('\n', '\r\n')
        
    with open(filepath, 'w', encoding='utf-8', newline='') as f:
        f.write(new_content)
    print(f"Successfully modified {filepath}")
    return True

# 1. project_subcontractor.php 修正
filepath_sub = 'project_subcontractor.php'

# (A) 納品時の INSERT INTO project_files
target_insert = """                // 3. 新しいファイルを登録 (これらは管理者と業者の間のみで表示される)
                $stmtInsertFile = $pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :cat, :fname, :fpath, :ver, 1)
                ");
                $stmtInsertFile->execute([
                    'pid' => $project_id,
                    'cat' => $category,
                    'fname' => $file_name,
                    'fpath' => $drive_file_id,
                    'ver' => $new_v
                ]);"""

replacement_insert = """                // 3. 新しいファイルを登録 (これらは管理者と業者の間のみで表示される)
                $stmtInsertFile = $pdo->prepare("
                    INSERT INTO project_files (project_id, subcontractor_order_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :order_id, :cat, :fname, :fpath, :ver, 1)
                ");
                $stmtInsertFile->execute([
                    'pid' => $project_id,
                    'order_id' => $order_id,
                    'cat' => $category,
                    'fname' => $file_name,
                    'fpath' => $drive_file_id,
                    'ver' => $new_v
                ]);"""

replace_in_file(filepath_sub, target_insert, replacement_insert)


# (B) 業者側のタスク取得クエリ $stmt
target_query_sub = """    $stmt = $pdo->prepare("
        SELECT o.*, p.project_name, p.status AS project_status 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id AND o.project_id = :pid
        ORDER BY o.created_at DESC
    ");"""

replacement_query_sub = """    $stmt = $pdo->prepare("
        SELECT o.*, p.project_name, p.status AS project_status,
               f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
               f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
               f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY subcontractor_order_id) f1 ON o.id = f1.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY subcontractor_order_id) f2 ON o.id = f2.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY subcontractor_order_id) f3 ON o.id = f3.subcontractor_order_id
        WHERE o.subcontractor_id = :sub_id AND o.project_id = :pid
        ORDER BY o.created_at DESC
    ");"""

replace_in_file(filepath_sub, target_query_sub, replacement_query_sub)


# (C) 管理者側の発注履歴クエリ $stmtOrd
target_query_admin = """    $stmtOrd = $pdo->prepare("
        SELECT o.*, u.contact_name,
               f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
               f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
               f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
        FROM subcontractor_orders o 
        JOIN users u ON o.subcontractor_id = u.id 
        LEFT JOIN (SELECT project_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY project_id) f1 ON o.project_id = f1.project_id
        LEFT JOIN (SELECT project_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY project_id) f2 ON o.project_id = f2.project_id
        LEFT JOIN (SELECT project_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY project_id) f3 ON o.project_id = f3.project_id
        WHERE o.project_id = :pid 
        ORDER BY o.created_at DESC
    ");"""

replacement_query_admin = """    $stmtOrd = $pdo->prepare("
        SELECT o.*, u.contact_name,
               f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
               f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
               f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
        FROM subcontractor_orders o 
        JOIN users u ON o.subcontractor_id = u.id 
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY subcontractor_order_id) f1 ON o.id = f1.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY subcontractor_order_id) f2 ON o.id = f2.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY subcontractor_order_id) f3 ON o.id = f3.subcontractor_order_id
        WHERE o.project_id = :pid 
        ORDER BY o.created_at DESC
    ");"""

replace_in_file(filepath_sub, target_query_admin, replacement_query_admin)


# (D) 業者側タスクループ内の delivery-section の修正と、納品ファイル一覧の表示追加
target_delivery_section = """                                    <?php if ($task['status'] !== 'cancelled'): ?>
                                        <?php 
                                        $show_struct_delivery = ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1);
                                        ?>
                                        <div class="delivery-section" style="border:1px solid #e2e8f0; background:#fdfdfd; padding:15px; border-radius:6px; font-size:13px; display:flex; flex-direction:column; gap:20px; margin-top: 10px;">
                                            <strong>📤 成果物（作成した図面）の納品・差し替え:</strong>
                                            <p style="font-size:11px; color:#666; margin:-5px 0 5px 0;">※個別にアップロード可能です。差し替えた場合も履歴が残ります。</p>
                                            
                                            <!-- ■ 意匠図の納品エリア -->
                                            <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:6px;">
                                                <strong style="color:#0f172a; font-size:14px; display:block; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">📐 意匠図の納品・差し替え</strong>
                                                
                                                <form id="design_deliver_form_<?= $task['id'] ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;" onsubmit="return false;">
                                                    <input type="hidden" name="action" value="deliver_task">
                                                    <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                                                    <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                                                    <input type="hidden" name="deliver_type" value="design">
                                                    
                                                    <!-- 意匠図チェックリスト -->
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
 
                                            <?php if ($show_struct_delivery): ?>
                                                <!-- ■ 構造図の納品エリア -->
                                                <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:6px;">
                                                    <strong style="color:#0f172a; font-size:14px; display:block; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">🏗 構造図の納品・差し替え</strong>
                                                    
                                                    <form id="struct_deliver_form_<?= $task['id'] ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;" onsubmit="return false;">
                                                        <input type="hidden" name="action" value="deliver_task">
                                                        <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                                                        <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                                                        <input type="hidden" name="deliver_type" value="struct">
                                                        
                                                        <!-- 構造図チェックリスト (12項目) -->
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
                                    <?php endif; ?>"""

replacement_delivery_section = """                                    <?php if (!empty($task['pdf_id']) || !empty($task['arc_d_id']) || !empty($task['arc_s_id'])): ?>
                                        <div style="margin-top:8px; padding:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                                            <strong style="color:#334155; font-size:12px;">📤 納品ファイル一覧:</strong>
                                            <ul style="margin:4px 0 0 0; padding-left:20px; font-size:12px;">
                                                <?php if (!empty($task['arc_d_id'])): 
                                                    $d_url = (strpos($task['arc_d_id'], 'uploads/') === 0) ? $task['arc_d_id'] : 'https://drive.google.com/file/d/' . $task['arc_d_id'] . '/view?usp=drivesdk';
                                                ?>
                                                    <li>意匠用アーキ: <a href="<?= htmlspecialchars($d_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($task['arc_d_name'], ENT_QUOTES) ?> (V<?= $task['arc_d_ver'] ?>)</a></li>
                                                <?php endif; ?>
                                                <?php if (!empty($task['arc_s_id'])): 
                                                    $s_url = (strpos($task['arc_s_id'], 'uploads/') === 0) ? $task['arc_s_id'] : 'https://drive.google.com/file/d/' . $task['arc_s_id'] . '/view?usp=drivesdk';
                                                ?>
                                                    <li>構造用アーキ: <a href="<?= htmlspecialchars($s_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($task['arc_s_name'], ENT_QUOTES) ?> (V<?= $task['arc_s_ver'] ?>)</a></li>
                                                <?php endif; ?>
                                                <?php if (!empty($task['pdf_id'])): 
                                                    $pdf_url = (strpos($task['pdf_id'], 'uploads/') === 0) ? $task['pdf_id'] : 'https://drive.google.com/file/d/' . $task['pdf_id'] . '/view?usp=drivesdk';
                                                ?>
                                                    <li>構造図PDF: <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($task['pdf_name'], ENT_QUOTES) ?> (V<?= $task['pdf_ver'] ?>)</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($task['status'] !== 'cancelled'): ?>
                                        <?php 
                                        $show_struct_delivery = ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1);
                                        $task_type = $task['order_type'] ?: 'design';
                                        ?>
                                        <div class="delivery-section" style="border:1px solid #e2e8f0; background:#fdfdfd; padding:15px; border-radius:6px; font-size:13px; display:flex; flex-direction:column; gap:20px; margin-top: 10px;">
                                            <strong>📤 成果物（作成した図面）の納品・差し替え:</strong>
                                            <p style="font-size:11px; color:#666; margin:-5px 0 5px 0;">※個別にアップロード可能です。差し替えた場合も履歴が残ります。</p>
                                            
                                            <?php if ($task_type === 'design'): ?>
                                                <!-- ■ 意匠図の納品エリア -->
                                                <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:6px;">
                                                    <strong style="color:#0f172a; font-size:14px; display:block; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">📐 意匠図の納品・差し替え</strong>
                                                    
                                                    <form id="design_deliver_form_<?= $task['id'] ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;" onsubmit="return false;">
                                                        <input type="hidden" name="action" value="deliver_task">
                                                        <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                                                        <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                                                        <input type="hidden" name="deliver_type" value="design">
                                                        
                                                        <!-- 意匠図チェックリスト -->
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
                                            
                                            <?php if ($task_type === 'struct' && $show_struct_delivery): ?>
                                                <!-- ■ 構造図の納品エリア -->
                                                <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:6px;">
                                                    <strong style="color:#0f172a; font-size:14px; display:block; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">🏗 構造図の納品・差し替え</strong>
                                                    
                                                    <form id="struct_deliver_form_<?= $task['id'] ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:10px; margin:0;" onsubmit="return false;">
                                                        <input type="hidden" name="action" value="deliver_task">
                                                        <input type="hidden" name="order_id" value="<?= $task['id'] ?>">
                                                        <input type="hidden" name="project_id" value="<?= $task['project_id'] ?>">
                                                        <input type="hidden" name="deliver_type" value="struct">
                                                        
                                                        <!-- 構造図チェックリスト (12項目) -->
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
                                    <?php endif; ?>"""

replace_in_file(filepath_sub, target_delivery_section, replacement_delivery_section)


# 2. subcontractor_portal.php 修正
filepath_portal = 'subcontractor_portal.php'
target_portal_query = """    // 業者全体（本アカウント宛て ＋ スタッフ宛て）またはメインアカウントの場合
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id)
        ORDER BY o.created_at DESC
    ");
    $stmtTasks->execute(['sub_id' => $target_sub_id]);"""

replacement_portal_query = """    // 業者全体（本アカウント宛て ＋ スタッフ宛て）またはメインアカウントの場合
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2)
        ORDER BY o.created_at DESC
    ");
    $stmtTasks->execute([
        'sub_id_1' => $target_sub_id,
        'sub_id_2' => $target_sub_id
    ]);"""

replace_in_file(filepath_portal, target_portal_query, replacement_portal_query)


# 3. StatusHelper.php 修正
filepath_helper = 'src/Helpers/StatusHelper.php'
target_helper_method = """    public static function getBallStatus(array $project, PDO $pdo): array"""

replacement_helper_method = """    public static function getBallStatus(array $project, PDO $pdo, string $user_role = null): array"""

replace_in_file(filepath_helper, target_helper_method, replacement_helper_method)

target_helper_return = """        return [
            'ball_owner' => 'admin',
            'label' => '図書作成中 (管理者ボール)',
            'color' => '#3b82f6'
        ];
    }"""

replacement_helper_return = """        $ball = [
            'ball_owner' => 'admin',
            'label' => '図書作成中 (管理者ボール)',
            'color' => '#3b82f6'
        ];

        // 依頼主(client)には協力業者の存在を見せないため、協力業者ボールは管理者ボールとして返す
        if (isset($ball_result)) {
            $ball = $ball_result;
        } else {
            // 元の getBallStatus 内で決定したボール状態を取得するため、元のロジックを少し整理
            // (実際には getBallStatus 内の途中の return 各所で差し替えを行うほうが安全)
        }
        return $ball;
    }"""

# より安全な StatusHelper.php 全体の getBallStatus 実装の差し替え
# ファイル全体を上書きまたは主要ロジックを置換する
target_helper_full = """    public static function getBallStatus(array $project, PDO $pdo): array
    {
        $status = $project['status'] ?? '';

        if ($status === 'completed') {
            return [
                'ball_owner' => 'completed',
                'label' => '完了',
                'color' => '#10b981' // Green
            ];
        }

        if ($status === 'quote_req') {
            // Check if there is an estimate issued for this project
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM estimates WHERE project_id = :pid");
            $stmt->execute(['pid' => $project['id']]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                // Estimate exists -> Client has the ball (見積確認中)
                return [
                    'ball_owner' => 'client',
                    'label' => '回答待ち (依頼主ボール)',
                    'color' => '#e67e22' // Orange
                ];
            } else {
                // No estimate -> Admin has the ball (見積作成中)
                return [
                    'ball_owner' => 'admin',
                    'label' => '図書作成中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }

        // Fetch active subcontractor tasks (not cancelled)
        $stmtTasks = $pdo->prepare("SELECT * FROM subcontractor_orders WHERE project_id = :pid AND status != 'cancelled'");
        $stmtTasks->execute(['pid' => $project['id']]);
        $tasks = $stmtTasks->fetchAll();

        if (count($tasks) > 0) {
            $has_sub_ball = false;
            $has_delivered_task = false;
            foreach ($tasks as $task) {
                if ($task['status'] === 'requested' || $task['status'] === 'accepted') {
                    $has_sub_ball = true;
                } elseif ($task['status'] === 'delivered') {
                    $has_delivered_task = true;
                }
            }

            if ($has_sub_ball) {
                return [
                    'ball_owner' => 'subcontractor',
                    'label' => '作成中 (協力業者ボール)',
                    'color' => '#8b5cf6' // Purple
                ];
            }

            if ($has_delivered_task) {
                return [
                    'ball_owner' => 'admin',
                    'label' => '納品検収中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }

        // If no active subcontractor tasks are in progress, let's look at project status
        if ($status === 'submission') {
            return [
                'ball_owner' => 'shared_waiting',
                'label' => '審査待ち (共通)',
                'color' => '#f59e0b' // Amber
            ];
        }

        if ($status === 'primary_prep' || $status === 'structural_dwg' || $status === 'correction') {
            return [
                'ball_owner' => 'admin',
                'label' => '図書作成中 (管理者ボール)',
                'color' => '#3b82f6' // Blue
            ];
        }

        if ($status === 'contracted') {
            return [
                'ball_owner' => 'admin',
                'label' => '図書作成中 (管理者ボール)',
                'color' => '#3b82f6'
            ];
        }

        return [
            'ball_owner' => 'admin',
            'label' => '図書作成中 (管理者ボール)',
            'color' => '#3b82f6'
        ];
    }"""

replacement_helper_full = """    public static function getBallStatus(array $project, PDO $pdo, string $user_role = null): array
    {
        $status = $project['status'] ?? '';
        $res = null;

        if ($status === 'completed') {
            $res = [
                'ball_owner' => 'completed',
                'label' => '完了',
                'color' => '#10b981' // Green
            ];
        }
        elif ($status === 'quote_req') {
            // Check if there is an estimate issued for this project
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM estimates WHERE project_id = :pid");
            $stmt->execute(['pid' => $project['id']]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                // Estimate exists -> Client has the ball (見積確認中)
                $res = [
                    'ball_owner' => 'client',
                    'label' => '回答待ち (依頼主ボール)',
                    'color' => '#e67e22' // Orange
                ];
            } else {
                // No estimate -> Admin has the ball (見積作成中)
                $res = [
                    'ball_owner' => 'admin',
                    'label' => '図書作成中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }
        else {
            // Fetch active subcontractor tasks (not cancelled)
            $stmtTasks = $pdo->prepare("SELECT * FROM subcontractor_orders WHERE project_id = :pid AND status != 'cancelled'");
            $stmtTasks->execute(['pid' => $project['id']]);
            $tasks = $stmtTasks->fetchAll();

            if (count($tasks) > 0) {
                $has_sub_ball = false;
                $has_delivered_task = false;
                foreach ($tasks as $task) {
                    if ($task['status'] === 'requested' || $task['status'] === 'accepted') {
                        $has_sub_ball = true;
                    } elseif ($task['status'] === 'delivered') {
                        $has_delivered_task = true;
                    }
                }

                if ($has_sub_ball) {
                    $res = [
                        'ball_owner' => 'subcontractor',
                        'label' => '作成中 (協力業者ボール)',
                        'color' => '#8b5cf6' // Purple
                    ];
                }
                elif ($has_delivered_task) {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '納品検収中 (管理者ボール)',
                        'color' => '#3b82f6' // Blue
                    ];
                }
            }

            if (!$res) {
                // If no active subcontractor tasks are in progress, let's look at project status
                if ($status === 'submission') {
                    $res = [
                        'ball_owner' => 'shared_waiting',
                        'label' => '審査待ち (共通)',
                        'color' => '#f59e0b' // Amber
                    ];
                }
                elif ($status === 'submitting') {
                    $res = [
                        'ball_owner' => 'shared_waiting',
                        'label' => '申請中 (共通待ち)',
                        'color' => '#f59e0b'
                    ];
                }
                elif ($status === 'primary_prep' || $status === 'structural_dwg' || $status === 'correction') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6' // Blue
                    ];
                }
                elif ($status === 'contracted') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                }
                else {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                }
            }
        }

        // 依頼主(client)には協力業者の存在を見せないため、協力業者ボールは管理者ボールとして返す
        if ($res['ball_owner'] === 'subcontractor' && $user_role === 'client') {
            return [
                'ball_owner' => 'admin',
                'label' => '図書作成中 (管理者ボール)',
                'color' => '#3b82f6'
            ];
        }

        return $res;
    }"""

replace_in_file(filepath_helper, target_helper_full, replacement_helper_full)


# 4. index.php & project_detail.php の getBallStatus 呼び出し引数修正
filepath_index = 'index.php'
target_index_call = """            $ball = \App\Helpers\StatusHelper::getBallStatus($project, $pdo);"""
replacement_index_call = """            $ball = \App\Helpers\StatusHelper::getBallStatus($project, $pdo, $_SESSION['role'] ?? null);"""
replace_in_file(filepath_index, target_index_call, replacement_index_call)

filepath_detail = 'project_detail.php'
target_detail_call = """            $ball = \App\Helpers\StatusHelper::getBallStatus($project_info, $pdo);"""
replacement_detail_call = """            $ball = \App\Helpers\StatusHelper::getBallStatus($project_info, $pdo, $_SESSION['role'] ?? null);"""
replace_in_file(filepath_detail, target_detail_call, replacement_detail_call)

# status 表示の補正 (client側 dashboardでの日本語表示のため)
# project_detail.php などのステータス表示変換テーブルに 'submitting' を追加する
# 例：
# $status_labels = [
#     'quote_req' => '見積依頼',
#     ...
# ];
# なので、project_detail.php 内で status_labels のマップに 'submitting' => '申請中' を追加する

target_labels_detail = """$status_labels = [
    'quote_req' => '見積依頼',
    'doc_submitted' => '図書提出済',
    'primary_prep' => '一次回答準備中',
    'contracted' => 'スケジュール確定',
    'structural_dwg' => '構造図作成中',
    'submission' => '提出済・確認中',
    'correction' => '補正対応中',
    'completed' => '完了'
];"""

replacement_labels_detail = """$status_labels = [
    'quote_req' => '見積依頼',
    'doc_submitted' => '図書提出済',
    'primary_prep' => '一次回答準備中',
    'contracted' => 'スケジュール確定',
    'structural_dwg' => '構造図作成中',
    'submission' => '提出済・確認中',
    'submitting' => '申請中',
    'correction' => '補正対応中',
    'completed' => '完了'
];"""

replace_in_file(filepath_detail, target_labels_detail, replacement_labels_detail)

filepath_index_main = 'index.php'
target_labels_index = """            $status_labels = [
                'quote_req' => '見積依頼',
                'doc_submitted' => '図書提出済',
                'primary_prep' => '一次回答準備中',
                'contracted' => 'スケジュール確定',
                'structural_dwg' => '構造図作成中',
                'submission' => '提出済・確認中',
                'correction' => '補正対応中',
                'completed' => '完了'
            ];"""

replacement_labels_index = """            $status_labels = [
                'quote_req' => '見積依頼',
                'doc_submitted' => '図書提出済',
                'primary_prep' => '一次回答準備中',
                'contracted' => 'スケジュール確定',
                'structural_dwg' => '構造図作成中',
                'submission' => '提出済・確認中',
                'submitting' => '申請中',
                'correction' => '補正対応中',
                'completed' => '完了'
            ];"""

replace_in_file(filepath_index_main, target_labels_index, replacement_labels_index)


# 5. actions/action_schedule.php 修正
filepath_action_schedule = 'actions/action_schedule.php'

# (A) 「申請図書一式UP」の保存時にステータスを `'submitting'` (申請中) に自動更新
# スケジュール実施日の更新 update_schedule_actual アクションの中
target_actual_update = """            $stmt = $pdo->prepare("UPDATE projects SET {$db_col} = :act WHERE id = :pid");
            $stmt->execute(['act' => json_encode($actuals), 'pid' => $project_id]);

            // チャットへ自動通知メッセージを投稿 (実績日が空の場合は通知しない)"""

replacement_actual_update = """            $stmt = $pdo->prepare("UPDATE projects SET {$db_col} = :act WHERE id = :pid");
            $stmt->execute(['act' => json_encode($actuals), 'pid' => $project_id]);

            // 「申請図書一式UP」の実施日が設定された場合、案件ステータスを「申請中」(submitting) に自動遷移
            if (!empty($actual_date)) {
                $is_submitting_step = false;
                if ($schedule_type === 'permit' && $step_idx == 7) $is_submitting_step = true;
                if ($schedule_type === 'wall' && $step_idx == 4) $is_submitting_step = true;
                if ($schedule_type === 'skin' && $step_idx == 4) $is_submitting_step = true;
                if ($schedule_type === 'sky' && $step_idx == 3) $is_submitting_step = true;

                if ($is_submitting_step) {
                    $stmtStatusUpd = $pdo->prepare("UPDATE projects SET status = 'submitting' WHERE id = :pid");
                    $stmtStatusUpd->execute(['pid' => $project_id]);
                }
            }

            // チャットへ自動通知メッセージを投稿 (実績日が空の場合は通知しない)"""

replace_in_file(filepath_action_schedule, target_actual_update, replacement_actual_update)


# (B) 「補正対応」の保存時に取引条件付きのチャット通知を行う
target_actual_chat = """                $step_name = $steps[$step_idx]['name'] ?? "工程 #{$step_idx}";
                $action_desc = "「{$actual_date}」に設定";
                $chat_msg = "【スケジュール実績更新】\\n{$step_name} の実施日が{$action_desc}されました。";
                
                $thread_type = ($schedule_type === 'permit') ? 'client_admin_permit' : 'client_admin_' . $schedule_type;"""

replacement_actual_chat = """                $step_name = $steps[$step_idx]['name'] ?? "工程 #{$step_idx}";
                $action_desc = "「{$actual_date}」に設定";
                $chat_msg = "【スケジュール実績更新】\\n{$step_name} の実施日が{$action_desc}されました。";

                // 「補正対応」の実施日が設定された場合、取引条件のチャット通知を追加
                $is_correction_step = false;
                if ($schedule_type === 'permit' && $step_idx == 9) $is_correction_step = true;
                if ($schedule_type === 'wall' && $step_idx == 6) $is_correction_step = true;
                if ($schedule_type === 'skin' && $step_idx == 6) $is_correction_step = true;
                if ($schedule_type === 'sky' && $step_idx == 6) $is_correction_step = true;

                if ($is_correction_step) {
                    $chat_msg .= "\\n審査完了しましたら、審査完了にしていただき、1週間以内の残金のご清算をお願いします。初回見積もり時に、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算が、お取引条件となります。";
                }
                
                $thread_type = ($schedule_type === 'permit') ? 'client_admin_permit' : 'client_admin_' . $schedule_type;"""

replace_in_file(filepath_action_schedule, target_actual_chat, replacement_actual_chat)


# 6. src/Services/UploadService.php 修正
# 補正通知 (correction_notice) ファイルがアップロードされた際、ステータスが「申請中」であれば「補正対応中」に自動更新
filepath_upload_service = 'src/Services/UploadService.php'
target_upload_commit = """            $this->pdo->commit();
            return true;
        } catch (Exception $e) {"""

replacement_upload_commit = """            // 補正通知 (correction_notice) ファイルがアップロードされた場合で、ステータスが「申請中」であれば「補正対応中」に更新
            if ($fileCategory === 'correction_notice') {
                $stmtCheckStatus = $this->pdo->prepare("SELECT status FROM projects WHERE id = :id");
                $stmtCheckStatus->execute(['id' => $projectId]);
                $currentStatus = $stmtCheckStatus->fetchColumn();
                if ($currentStatus === 'submitting') {
                    $stmtUpdateStatus = $this->pdo->prepare("UPDATE projects SET status = 'correction' WHERE id = :id");
                    $stmtUpdateStatus->execute(['id' => $projectId]);

                    // チャット通知 (自動)
                    $msgSubmittingCorrection = "【自動通知】補正通知書がアップロードされました。案件ステータスを「申請中」から「補正対応中」に変更しました。";
                    $stmtMsgCorrection = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                    $stmtMsgCorrection->execute([
                        'pid' => $projectId,
                        'sid' => $userId,
                        'thread' => $threadType,
                        'msg' => $msgSubmittingCorrection
                    ]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {"""

replace_in_file(filepath_upload_service, target_upload_commit, replacement_upload_commit)


# 7. 注意書きの追記 (project_detail.phpモーダル定型文, estimate_print.php, estimate_pdf_generator.php)

# (A) project_detail.php
target_greeting_text = """3. 一次回答を1か月以内にご確認いただきます。お見積額の50%入金をお願い致します。ご入金確認後4営業日以内に構造図をUP致します。"""
replacement_greeting_text = """3. 一次回答を1か月以内にご確認いただきます。一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算が、お取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。"""
replace_in_file(filepath_detail, target_greeting_text, replacement_greeting_text)

# (B) estimate_print.php
filepath_est_print = 'estimate_print.php'
target_est_print_note = """            ・業務の流れとして、一次回答チェック後に見積額 of 50%のご入金をお願いしております。<br>"""
# 実際の中身を見てみましょう。先ほどの grep 出力では：
# {"File":"e:\\Dropbox\\■設計ｻﾎﾟｰﾄ\\■note\\antigravity\\system\\estimate_print.php","LineNumber":212,"LineContent":"            ・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>"}
target_est_print_note = """            ・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>"""
replacement_est_print_note = """            ・業務の流れとして、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算がお取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。<br>"""
replace_in_file(filepath_est_print, target_est_print_note, replacement_est_print_note)

# (C) estimate_pdf_generator.php
filepath_est_pdf = 'estimate_pdf_generator.php'
target_est_pdf_note = """            ・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>"""
replacement_est_pdf_note = """            ・業務の流れとして、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算がお取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。<br>"""
replace_in_file(filepath_est_pdf, target_est_pdf_note, replacement_est_pdf_note)
