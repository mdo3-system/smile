<?php
// register.php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$devel_link = '';

$invite_parent_id = intval($_GET['invite_parent_id'] ?? $_POST['invite_parent_id'] ?? 0);
$invite_project_id = intval($_GET['invite_project_id'] ?? $_POST['invite_project_id'] ?? 0);
$invite_role = trim($_GET['invite_role'] ?? $_POST['invite_role'] ?? '');

$prefilled_company = '';
$prefilled_role = 'client';

if ($invite_parent_id > 0) {
    // 親ユーザーの情報を取得して、同じroleを引き継ぎ、会社名をプリフィルする
    $stmtParent = $pdo->prepare("SELECT company_name, role FROM users WHERE id = :id");
    $stmtParent->execute(['id' => $invite_parent_id]);
    $parent = $stmtParent->fetch();
    if ($parent) {
        $prefilled_company = $parent['company_name'] ?: '';
        $prefilled_role = $parent['role'] ?: 'client';
    }
} elseif ($invite_role === 'client') {
    $prefilled_role = 'client';
} elseif ($invite_role === 'subcontractor') {
    $prefilled_role = 'subcontractor';
}

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
            // 新規ユーザー登録
            $stmtInsert = $pdo->prepare("
                INSERT INTO users (company_name, contact_name, email, phone_number, role, allowed_project_id, parent_id) 
                VALUES (:company, :contact, :email, :phone, :role, :allowed_project_id, :parent_id)
            ");
            $stmtInsert->execute([
                'company' => $company_name,
                'contact' => $contact_name,
                'email'   => $email,
                'phone'   => $phone_number,
                'role'    => $prefilled_role,
                'allowed_project_id' => $invite_project_id > 0 ? $invite_project_id : null,
                'parent_id' => $invite_parent_id > 0 ? $invite_parent_id : null
            ]);
            
            $new_user_id = $pdo->lastInsertId();
            
            // トークン生成 (64文字)
            $token = bin2hex(random_bytes(32));
            // magic_links へ登録 (有効期限は24時間後)
            $stmtMagic = $pdo->prepare("
                INSERT INTO magic_links (user_id, token, expires_at) 
                VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 24 HOUR))
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
            
            // リダイレクト先の判定
            if ($invite_project_id > 0) {
                $target_page = "project_detail.php?id=" . $invite_project_id;
            } elseif ($invite_parent_id > 0) {
                $target_page = ($prefilled_role === 'subcontractor') ? "subcontractor_portal.php" : "index.php";
            } else {
                $target_page = "index.php";
            }
            $login_url = "{$app_url}/{$target_page}" . (strpos($target_page, '?') === false ? '?' : '&') . "token={$token}";
            
            // 本物のメール送信処理 (XServer本番環境用)
            $to = $email;
            $subject = "【構造設計サポート・ポータル】ご登録完了およびログインのご案内";
            $body = "いつもお世話になっております。構造設計サポート・ポータルです。\n\n";
            $body .= "この度はポータルへのご登録、誠にありがとうございます。\n";
            $body .= "登録が完了いたしましたので、以下のログインリンクをクリックしてシステムにアクセスしてください。\n";
            $body .= "（このリンクは送信から24時間有効です）\n\n";
            $body .= "{$login_url}\n\n";
            $body .= "※本メールに心当たりがない場合は、破棄してください。\n";
            
            $headers = "From: support@thanks.work\r\n";
            $headers .= "Reply-To: support@thanks.work\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            mb_language("Japanese");
            mb_internal_encoding("UTF-8");
            
            $mail_sent = mb_send_mail($to, $subject, $body, $headers);
            
            if ($mail_sent) {
                $message = "ご登録ありがとうございました。ご登録のメールアドレス宛にログインリンクを送信しましたのでご確認ください。";
            } else {
                $message = "登録完了メールの送信に失敗しましたが、アカウント作成は完了しています。ログイン画面よりログインをお試しください。";
            }
            
            // 開発環境 (HTTP_HOST が localhost や IP の場合) のみ、デバッグ用に画面へもリンクを露出する
            $is_local = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false || strpos($_SERVER['HTTP_HOST'], '192.168.') !== false);
            if ($is_local) {
                $devel_link = $login_url;
            }
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
                <input type="hidden" name="invite_project_id" value="<?= $invite_project_id ?>">
                <input type="hidden" name="invite_parent_id" value="<?= $invite_parent_id ?>">
                <div class="form-group">
                    <label for="company_name">会社名（必須）</label>
                    <input type="text" id="company_name" name="company_name" placeholder="例: 株式会社○○工務店" value="<?= htmlspecialchars($prefilled_company, ENT_QUOTES) ?>" required>
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
