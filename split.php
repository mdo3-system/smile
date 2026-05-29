<?php
function extractDivBlock($fileContent, $startSearchStr) {
    $startPos = strpos($fileContent, $startSearchStr);
    if ($startPos === false) return false;

    // 最初の '<div' を探す
    $divStartPos = strpos($fileContent, '<div', $startPos);
    if ($divStartPos === false) return false;

    $depth = 0;
    $pos = $divStartPos;
    $len = strlen($fileContent);

    while ($pos < $len) {
        $nextDivOpen = strpos($fileContent, '<div', $pos);
        $nextDivClose = strpos($fileContent, '</div', $pos);

        if ($nextDivOpen === false && $nextDivClose === false) {
            break;
        }

        if ($nextDivOpen !== false && ($nextDivOpen < $nextDivClose || $nextDivClose === false)) {
            $depth++;
            $pos = $nextDivOpen + 4;
        } else if ($nextDivClose !== false) {
            $depth--;
            $pos = $nextDivClose + 6; // '</div>' length is 6
            if ($depth === 0) {
                // 終了位置が見つかった
                return [
                    'start' => $startPos,
                    'end' => $pos,
                    'content' => substr($fileContent, $startPos, $pos - $startPos)
                ];
            }
        }
    }
    return false;
}

$file = 'project_detail.php';
$content = file_get_contents($file);

$sections = [
    'col_left.php' => '<div class="column col-left">',
    'col_center.php' => '<div class="column col-center">',
    'col_right.php' => '<div class="column col-right"'
];

foreach ($sections as $filename => $searchStr) {
    $result = extractDivBlock($content, $searchStr);
    if ($result) {
        file_put_contents('components/' . $filename, $result['content']);
        $requireCode = "<?php require __DIR__ . '/components/" . $filename . "'; ?>";
        $content = substr_replace($content, $requireCode, $result['start'], $result['end'] - $result['start']);
        echo "Extracted $filename\n";
    } else {
        echo "Failed to extract $filename\n";
    }
}

file_put_contents($file, $content);
echo "Done.\n";
