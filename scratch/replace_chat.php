<?php
// scratch/replace_chat.php
$file = __DIR__ . '/../project_subcontractor.php';
if (!file_exists($file)) {
    die("File not found: " . $file . "\n");
}
$content = file_get_contents($file);

// チャット入力 textarea の置換（2箇所）
// 旧: style="flex:1; border:1px solid #ccc; border-radius:20px; padding:8px 12px; font-size:13px; resize:none;" rows="1"
// 新: style="flex:1; border:1px solid #ccc; border-radius:6px; padding:8px 12px; font-size:13px; resize:none;" rows="3"

$pattern = '/style="flex:1;\s*border:1px\s+solid\s+#ccc;\s*border-radius:20px;\s*padding:8px\s+12px;\s*font-size:13px;\s*resize:none;"\s+rows="1"/is';
$replacement = 'style="flex:1; border:1px solid #ccc; border-radius:6px; padding:8px 12px; font-size:13px; resize:none;" rows="3"';

$new_content = preg_replace($pattern, $replacement, $content);

if ($new_content !== $content) {
    file_put_contents($file, $new_content);
    echo "Chat textarea successfully expanded!\n";
} else {
    echo "Chat textarea replacement failed (no match found).\n";
}
