<?php
// session_config.php
// 24時間ログインセッション維持のための共通セッション初期化設定

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 86400; // 24時間 (秒)

    // ガベージコレクション有効期限 (24時間)
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', (string)$lifetime);

    // 専用セッションディレクトリを設定（共有サーバー環境でのOS・他プロセスのGC干渉を防止）
    $session_dir = __DIR__ . '/sessions';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0700, true);
        // Webアクセス完全遮断用の .htaccess を自動作成
        @file_put_contents($session_dir . '/.htaccess', "Deny from all\n");
    }
    if (is_dir($session_dir) && is_writable($session_dir)) {
        session_save_path($session_dir);
    }

    // HTTPS環境判定（プロキシ経由・Cloudflare等を含む）
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    // Cookieの有効期限・セキュリティ設定
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    // アクセスごとにセッションCookieの有効期限を24時間延長（スライディング有効期限）
    if (isset($_COOKIE[session_name()])) {
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $is_https,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }
}
