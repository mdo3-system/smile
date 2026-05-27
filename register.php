<?php
// register.php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$devel_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = trim($_POST['company_name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    
    if (empty($company_name) || empty($contact_name) || empty($email)) {
        $message = "必須項目を入力してください。";
    } else {
        // メールアドレスの重複チェック
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmtCheck->execute(['email' => $email]);
        if ($stmtCheck->fetch()) {
            $message = "指定されたメールアドレスは既に登録されています。<a href='login.php' style='color:#6ee7b7; text-decoration:underline;'>ログイン画面</a>をご利用ください。";
        } else {
            // 新規ユーザー登録（依頼主として）
            $stmtInsert = $pdo->prepare("
                INSERT INTO users (company_name, contact_name, email, phone_number, role) 
                VALUES (:company, :contact, :email, :phone, 'client')
            ");
            $stmtInsert->execute([
                'company' => $company_name,
                'contact' => $contact_name,
                'email'   => $email,
                'phone'   => $phone_number
            ]);
            
            $new_user_id = $pdo->lastInsertId();
            
            // トークン生成 (64文字)
            $token = bin2hex(random_bytes(32));
            // magic_links へ登録 (有効期限は15分後)
            $stmtMagic = $pdo->prepare("
                INSERT INTO magic_links (user_id, token, expires_at) 
                VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
            ");
            $stmtMagic->execute([
                'user_id' => $new_user_id,
                'token' => $token
            ]);
            
            // .env のロードと APP_URL の取得
            $app_url = '';
            if (file_exists(__DIR__ . '/.env')) {
                $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    if (strpos($line, '=') !== false) {
                        list($name, $value) = explode('=', $line, 2);
                        if (trim($name) === 'APP_URL') {
                            $app_url = trim(trim($value), '"\'');
                            break;
                        }
                    }
                }
            }
            if (empty($app_url)) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $script_dir = dirname($_SERVER['SCRIPT_NAME']);
                $script_dir = str_replace('\\', '/', $script_dir);
                $script_dir = rtrim($script_dir, '/');
                $app_url = "{$protocol}://{$host}{$script_dir}";
            } else {
                $app_url = rtrim($app_url, '/');
            }
            
            // 新規登録の依頼主は index.php へ
            $login_url = "{$app_url}/index.php?token={$token}";
            
            $message = "ご登録ありがとうございます。以下のログインリンクからポータルへアクセスできます。";
            $devel_link = $login_url;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規登録 | 構造設計サポート・ポータル</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&family=Noto+Sans+JP:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            --card-bg: rgba(30, 41, 59, 0.7);
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --text-color: #f1f5f9;
            --text-muted: #94a3b8;
        }
        
        body {
            font-family: 'Outfit', 'Noto Sans JP', sans-serif;
            background: var(--bg-gradient);
            color: var(--text-color);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .login-container {
            width: 100%;
            max-width: 480px;
            padding: 20px;
        }
        
        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 40px 30px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        .logo {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
            background: linear-gradient(to right, #3b82f6, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 30px;
        }
        
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 6px;
            font-weight: 600;
        }
        
        input[type="text"], input[type="email"], input[type="tel"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.6);
            border-radius: 8px;
            color: var(--text-color);
            font-size: 15px;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        
        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }
        
        .message-box {
            margin-top: 20px;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.4;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            text-align: left;
        }
        
        .devel-link-box {
            margin-top: 15px;
            padding: 12px;
            border-radius: 8px;
            font-size: 12px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            word-break: break-all;
            text-align: left;
        }
        
        .devel-link-box a {
            color: #6ee7b7;
            text-decoration: underline;
            font-weight: bold;
        }

        .login-link {
            display: inline-block;
            margin-top: 25px;
            font-size: 13px;
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s;
        }
        .login-link:hover {
            color: var(--text-color);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo">NEW REGISTRATION</div>
            <div class="subtitle">構造設計サポート・ポータル 新規登録</div>
            
            <?php if (empty($devel_link)): // 登録成功後はフォームを隠す ?>
            <form method="POST">
                <div class="form-group">
                    <label for="company_name">会社名（必須）</label>
                    <input type="text" id="company_name" name="company_name" placeholder="例: 株式会社○○工務店" required>
                </div>
                <div class="form-group">
                    <label for="contact_name">担当者名（必須）</label>
                    <input type="text" id="contact_name" name="contact_name" placeholder="例: 山田 太郎" required>
                </div>
                <div class="form-group">
                    <label for="email">メールアドレス（必須）</label>
                    <input type="email" id="email" name="email" placeholder="example@domain.com" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="phone_number">電話番号</label>
                    <input type="tel" id="phone_number" name="phone_number" placeholder="例: 090-1234-5678">
                </div>
                <button type="submit" class="btn-submit">登録してログインリンクを発行</button>
            </form>
            <?php endif; ?>
            
            <?php if (!empty($message)): ?>
                <div class="message-box">
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($devel_link)): ?>
                <div class="devel-link-box">
                    <strong>【開発環境用ログインリンク】</strong><br>
                    <a href="<?= $devel_link ?>">このリンクをクリックしてポータルへアクセス ➔</a>
                </div>
            <?php endif; ?>

            <?php if (empty($devel_link)): ?>
            <a href="login.php" class="login-link">すでにアカウントをお持ちの方はこちら（ログイン）</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
