<?php
// logout.php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';

// 1. DB側の remember_token をクリア
$user_id = $_SESSION['user_id'] ?? null;
$cookie_token = $_COOKIE['remember_token'] ?? null;

if (isset($pdo)) {
    if ($user_id) {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_token_expires = NULL WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
    } elseif ($cookie_token) {
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_token_expires = NULL WHERE remember_token = :token");
        $stmt->execute(['token' => $cookie_token]);
    }
}

// 2. クッキーの消去
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// 3. セッションの完全破棄
$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
