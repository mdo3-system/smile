<?php
// scratch/replace_cb.php
$file = __DIR__ . '/../project_subcontractor.php';
if (!file_exists($file)) {
    die("File not found: " . $file . "\n");
}
$content = file_get_contents($file);

// 1. 管理者側チェックバック入力フォームの置換
$pattern_form = '/(<form\s+action="project_detail\.php\?id=<\?=\s*\$project_id\s*\?>"\s+method="POST"\s+style="margin:0;">\s*<input\s+type="hidden"\s+name="action"\s+value="submit_checkback">.*?<textarea\s+name="checkback_text"\s+style="width:100%;\s*min-height:80px;\s*padding:6px;\s*box-sizing:border-box;\s*font-size:12px;\s*border:1px\s+solid\s+#cbd5e1;\s*border-radius:4px;\s*margin-bottom:5px;"\s+placeholder="修正指示を入力してください\.\.\.">.*?<\/textarea>)/is';

// 実際にマッチするかテスト
if (preg_match($pattern_form, $content, $matches)) {
    echo "Form match found!\n";
    
    // 具体的な置換テキストを構築（インデントに合わせる）
    $replacement_form = '<form action="project_detail.php?id=<?= $project_id ?>" method="POST" enctype="multipart/form-data" style="margin:0; display:flex; flex-direction:column; gap:8px;">
                                             <input type="hidden" name="action" value="submit_checkback">
                                             <input type="hidden" name="order_id" value="<?= $o[\'id\'] ?>">
                                             <label style="display:block; font-size:12px; font-weight:bold; color:#c53030;">📝 チェックバック（修正指示）入力・更新枠:</label>
                                             <textarea name="checkback_text" style="width:100%; min-height:80px; padding:6px; box-sizing:border-box; font-size:12px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="修正指示を入力してください..."><?= htmlspecialchars($o[\'checkback_text\'] ?? \'\', ENT_QUOTES) ?></textarea>
                                             
                                             <!-- 既存チェックバックファイル表示 -->
                                             <?php if (!empty($o[\'checkback_file_path\'])): 
                                                 $cb_url = (strpos($o[\'checkback_file_path\'], \'uploads/\') !== 0 && strlen($o[\'checkback_file_path\']) > 15 && strpos($o[\'checkback_file_path\'], \'/\') === false)
                                                     ? \'https://drive.google.com/file/d/\' . htmlspecialchars($o[\'checkback_file_path\'], ENT_QUOTES) . \'/view?usp=drivesdk\'
                                                     : htmlspecialchars($o[\'checkback_file_path\'], ENT_QUOTES);
                                             ?>
                                                 <div style="font-size:11px; color:#475569; background:#fff; padding:6px; border:1px solid #cbd5e1; border-radius:4px; display:flex; align-items:center; gap:5px;">
                                                     📎 <a href="<?= $cb_url ?>" target="_blank" style="color:#0284c7; text-decoration:underline; font-weight:bold;">現在の修正指示ファイルを確認</a>
                                                 </div>
                                             <?php endif; ?>

                                             <div style="display:flex; align-items:center; gap:10px; font-size:12px; flex-wrap:wrap;">
                                                 <label style="font-weight:bold; color:#475569;">修正指示ファイルを添付:</label>
                                                 <input type="file" name="checkback_file" style="font-size:11px;">
                                             </div>';
                                             
    $content = preg_replace($pattern_form, $replacement_form, $content, 1);
} else {
    echo "Form match failed!\n";
}

// 2. 協力業者画面のチェックバック指示ファイルのプレビュー表示の置換
$pattern_preview = '/(<\?php\s+if\s+\(!empty\(\$task\[\'checkback_text\'\]\)\):\s*\?>\s*<div\s+style="margin-top:10px;\s*padding:10px;\s*background:#fff5f5;\s*border:1px\s+solid\s+#feb2b2;\s*border-radius:6px;\s*font-size:12px;\s*color:#2d3748;\s*text-align:left;">.*?<\/div>\s*<\?php\s+endif;\s*\?>)/is';

if (preg_match($pattern_preview, $content, $matches)) {
    echo "Preview match found!\n";
    
    $replacement_preview = '<?php if (!empty($task[\'checkback_text\']) || !empty($task[\'checkback_file_path\'])): ?>
                                         <div style="margin-top:10px; padding:10px; background:#fff5f5; border:1px solid #feb2b2; border-radius:6px; font-size:12px; color:#2d3748; text-align:left;">
                                             <strong style="color:#c53030; display:block; margin-bottom:5px;">📝 管理者からの修正指示 (チェックバック):</strong>
                                             <?php if (!empty($task[\'checkback_text\'])): ?>
                                                 <div style="white-space:pre-wrap; line-height:1.5; background:white; padding:8px; border-radius:4px; border:1px solid #fed7aa; margin-bottom:5px;"><?= htmlspecialchars($task[\'checkback_text\'], ENT_QUOTES) ?></div>
                                             <?php endif; ?>
                                             <?php if (!empty($task[\'checkback_file_path\'])): 
                                                 $cb_url = (strpos($task[\'checkback_file_path\'], \'uploads/\') !== 0 && strlen($task[\'checkback_file_path\']) > 15 && strpos($task[\'checkback_file_path\'], \'/\') === false)
                                                     ? \'https://drive.google.com/file/d/\' . htmlspecialchars($task[\'checkback_file_path\'], ENT_QUOTES) . \'/view?usp=drivesdk\'
                                                     : htmlspecialchars($task[\'checkback_file_path\'], ENT_QUOTES);
                                             ?>
                                                 <div style="background:white; padding:6px; border-radius:4px; border:1px solid #fed7aa; display:flex; align-items:center; gap:5px;">
                                                     📎 修正指示ファイル: <a href="<?= $cb_url ?>" target="_blank" style="color:#0284c7; text-decoration:underline; font-weight:bold;">修正指示ファイルをダウンロード</a>
                                                 </div>
                                             <?php endif; ?>
                                             <div style="font-size:10px; color:#718096; margin-top:5px; text-align:right;">最終更新: <?= htmlspecialchars($task[\'checkback_updated_at\'], ENT_QUOTES) ?></div>
                                         </div>
                                     <?php endif; ?>';
                                     
    $content = preg_replace($pattern_preview, $replacement_preview, $content, 1);
} else {
    echo "Preview match failed!\n";
}

file_put_contents($file, $content);
echo "Replacement process completed!\n";
