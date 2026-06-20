<?php
$file = dirname(__DIR__) . '/project_subcontractor.php';
if (!file_exists($file)) {
    die("Error: File not found: $file\n");
}

$content = file_get_contents($file);
// Backup the file first
file_put_contents($file . '.bak', $content);

// Normalize line endings to LF for easier regex matching
$content = str_replace("\r\n", "\n", $content);

// Extract the blocks
$form_block = '';
$history_block = '';
$publish_block = '';
$chat_block = '';

// 1. Extract Order Form
if (preg_match('/(<!-- 発注フォーム -->.*?<\/div>\s*)(?=<!-- 発注履歴 -->)/s', $content, $m)) {
    $form_block = trim($m[1]);
} else {
    die("Error extracting Order Form\n");
}

// 2. Extract Orders History
if (preg_match('/(<!-- 発注履歴 -->.*?<\/div>\s*)(?=<!-- 共通図書)/s', $content, $m)) {
    $history_block = trim($m[1]);
} else {
    die("Error extracting Orders History\n");
}

// 3. Extract File Sharing Settings
if (preg_match('/(<!-- 共通図書・CADデータの公開設定 -->.*?<\/div>\s*)(?=<!-- 管理者用 案件別チャットUI -->)/s', $content, $m)) {
    $publish_block = trim($m[1]);
} else {
    die("Error extracting File Sharing Settings\n");
}

// 4. Extract Chat UI
$chat_delim = '<' . '?php else: ?' . '>';
if (preg_match('/(<!-- 管理者用 案件別チャットUI -->.*?<\/div>\s*)(?=<\/div>\s*<\/div>\s*' . preg_quote($chat_delim, '/') . ')/s', $content, $m)) {
    $chat_block = trim($m[1]);
} else {
    die("Error extracting Chat UI\n");
}

// Modify the Orders History block to filter out cancelled orders and display fallback message correctly
$old_history_loop = '<' . '?php foreach($admin_orders as $o):' . '>';
$new_history_loop = '<' . '?php
                // キャンセルされていない有効な発注履歴が存在するか確認
                $has_active_orders = false;
                if (!empty($admin_orders)) {
                    foreach ($admin_orders as $o) {
                        if ($o[\'status\'] !== \'cancelled\') {
                            $has_active_orders = true;
                            break;
                        }
                    }
                }
                ?' . '>
                <' . '?php if (!$has_active_orders): ?' . '>
                    <p style="color:#666;">まだ有効な発注依頼履歴はありません。</p>
                <' . '?php else: ?' . '>
                    <' . '?php foreach($admin_orders as $o): 
                        if ($o[\'status\'] === \'cancelled\') continue;';

// Replace the loop condition
$history_block = str_replace($old_history_loop, $new_history_loop, $history_block);

// In the history loop, we also need to change the if condition for empty checking
$old_empty_check = '<' . '?php if (empty($admin_orders)): ?' . '>
                    <p style="color:#666;">まだ発注依頼履歴はありません。</p>
                <' . '?php else: ?' . '>';
// We should remove this empty check structure since we now use $has_active_orders
$history_block = str_replace($old_empty_check, '', $history_block);

// Remove margins from blocks for better fitting in columns
$form_block = str_replace('class="task-card"', 'class="task-card" style="margin-bottom:0;"', $form_block);
$publish_block = str_replace('class="task-card" style="border-left-color: #3b82f6;"', 'class="task-card" style="border-left-color: #3b82f6; margin-bottom:0;"', $publish_block);
$history_block = str_replace('class="task-card"', 'class="task-card" style="margin-bottom:0;"', $history_block);
$chat_block = str_replace('class="task-card"', 'class="task-card" style="margin-bottom:0;"', $chat_block);

// Assemble the new 3-column layout
$new_admin_layout = <<<HTML
        <!-- 3カラムレイアウト化 -->
        <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 20px; align-items: start;">
            
            <!-- カラム1（左）: 新規発注依頼 ＆ 共通図書公開設定 -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                {$form_block}
                {$publish_block}
            </div>

            <!-- カラム2（中）: 発注履歴 -->
            {$history_block}

            <!-- カラム3（右）: 協力業者連絡チャット -->
            {$chat_block}
        </div>
HTML;

// Find the target layout container to replace
$search_start = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
$start_pos = strpos($content, $search_start);
if ($start_pos === false) {
    die("Error: target layout start not found\n");
}
$else_delim = '<' . '?php else: ?' . '>';
$end_pos = strpos($content, $else_delim, $start_pos);
if ($end_pos === false) {
    die("Error: target layout end (else block) not found\n");
}

// Find the last </div> before the else block
$sub_to_end = substr($content, $start_pos, $end_pos - $start_pos);
$last_div_pos = strrpos($sub_to_end, '</div>');
if ($last_div_pos === false) {
    die("Error: closing div of the grid not found\n");
}

// The target range to replace is from $start_pos up to $start_pos + $last_div_pos + 6 (length of '</div>')
$replace_len = $last_div_pos + 6;

$modified_content = substr_replace($content, $new_admin_layout, $start_pos, $replace_len);

// Write back to the file
file_put_contents($file, $modified_content);
echo "Successfully modified project_subcontractor.php!\n";
