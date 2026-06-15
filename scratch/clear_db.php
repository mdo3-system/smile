<?php
require 'db_connect.php';

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$pdo->exec("TRUNCATE TABLE projects;");
$pdo->exec("TRUNCATE TABLE project_specs;");
$pdo->exec("TRUNCATE TABLE project_files;");
$pdo->exec("TRUNCATE TABLE messages;");
$pdo->exec("TRUNCATE TABLE subcontractor_orders;");
$pdo->exec("TRUNCATE TABLE estimates;");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

echo "Database cleared successfully (except users table).\n";
