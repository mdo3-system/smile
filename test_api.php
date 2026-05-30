<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['project_id'] = 1;
$_POST['total_price'] = 1000;
$_POST['note'] = '[]';
session_start();
$_SESSION['user_id'] = 1; // mock admin
ob_start();
require 'api_save_estimate.php';
$output = ob_get_clean();
echo "API OUTPUT:\n";
echo $output;
