<?php
$lines = file('assets/js/project_detail.js');
$new = array_merge(array_slice($lines, 0, 186), array_slice($lines, 380));
file_put_contents('assets/js/project_detail.js', implode('', $new));
echo "Done";
