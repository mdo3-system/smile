<?php
session_start();
$_SESSION['user_id'] = 1; // Admin ID
$_SESSION['role'] = 'admin';
$_GET['id'] = 9; // Assuming 9 is a valid project_id from previous context
ob_start();
require 'project_detail.php';
$output = ob_get_clean();
echo "Execution completed without fatal errors. Output length: " . strlen($output);
