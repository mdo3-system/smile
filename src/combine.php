<?php
$good = file_get_contents('src/project_detail_good.php');
// Extract up to the line "// ==========================================\n// データ取得"
$split_pos = strpos($good, "// ==========================================\n// データ取得");
if ($split_pos === false) {
    // try different newline
    $split_pos = strpos($good, "// ==========================================\r\n// データ取得");
}
$top_part = substr($good, 0, $split_pos);

$fetch = file_get_contents('src/fetch_logic.php');
$html = file_get_contents('src/html_layout.php');

$new_content = $top_part . $fetch . "?>\n" . $html;
file_put_contents('project_detail.php', $new_content);
echo "Done replacing project_detail.php correctly.";
