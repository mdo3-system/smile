<?php
// db_connect.php
require_once __DIR__ . '/vendor/autoload.php';

$host = 'localhost'; 
$db   = 'mdo3_system'; // ★ここはお手元の設定のままにしてください
$user = 'mdo3_system01'; // ★ここはお手元の設定のままにしてください
$pass = 'koki2989'; // ★ここはお手元の設定のままにしてください
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}