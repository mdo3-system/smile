<?php
// session_config.php
// 24時間ログインセッション維持のための共通セッション初期化設定

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 86400; // 24時間 (秒)

    // ガベージコレクション有効期限
    ini_set('session.gc_maxlifetime', (string)$lifetime);

    // 専用セッションディレクトリを設定（共有サーバー環境での他プロセスのGC干渉を防止）
    $session_dir = sys_get_temp_dir() . '/thanks_work_sessions';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0700, true);
    }
    if (is_dir($session_dir) && is_writable($session_dir)) {
        session_save_path($session_dir);
    }

    // Cookieの有効期限・セキュリティ設定
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}
