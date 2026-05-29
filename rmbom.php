<?php
$f = file_get_contents('project_detail.php');
if (substr($f, 0, 3) === "\xEF\xBB\xBF") {
    file_put_contents('project_detail.php', substr($f, 3));
    echo "BOM removed";
} else {
    echo "No BOM found";
}
