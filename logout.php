<?php
// logout.php
require_once __DIR__ . '/session_config.php';

$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;
?>
