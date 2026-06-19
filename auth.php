<?php
// auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connect.php';

// 1. URLパラメータに token が含まれる場合の自動ログイン検証
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    
    // 有効な（未使用かつ期限内の）マジックリンクを検索
    $stmt = $pdo->prepare("
        SELECT * FROM magic_links 
        WHERE token = :token 
        AND used = 0 
        AND expires_at > NOW()
    ");
    $stmt->execute(['token' => $token]);
    $link = $stmt->fetch();

    if ($link) {
        // トークンを「使用済み」に更新
        $stmtUpdate = $pdo->prepare("UPDATE magic_links SET used = 1 WHERE id = :id");
        $stmtUpdate->execute(['id' => $link['id']]);

        // ユーザー情報を取得してログインセッションを確立
        $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmtUser->execute(['id' => $link['user_id']]);
        $user = $stmtUser->fetch();

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['contact_name'] = $user['contact_name'];
            $_SESSION['allowed_project_id'] = $user['allowed_project_id'];
            $_SESSION['parent_id'] = $user['parent_id'];
            
            // トークンパラメータを除去したURLへリダイレクトしてログインセッションを保持
            $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
            header("Location: " . $clean_url);
            exit;
        }
    } else {
        // トークンが無効または期限切れの場合
        die("ログインリンクが無効であるか、有効期限が切れています。再度ログインリンクを発行してください。<br><a href='login.php'>ログイン画面へ戻る</a>");
    }
}

// 2. ログインチェックおよびロール判定関数
function check_auth($allowed_roles = []) {
    global $pdo;
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: login.php");
        exit;
    }

    // 常にDBから最新情報を取得してセッションを同期
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT role, contact_name, allowed_project_id, parent_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['role'] = $user['role'];
            $_SESSION['contact_name'] = $user['contact_name'];
            $_SESSION['allowed_project_id'] = $user['allowed_project_id'];
            $_SESSION['parent_id'] = $user['parent_id'];
        } else {
            session_destroy();
            header("Location: login.php");
            exit;
        }
    }

    // allowed_project_id によるアクセス制限
    if (!empty($_SESSION['allowed_project_id'])) {
        $allowed_pid = (int)$_SESSION['allowed_project_id'];
        $current_script = basename($_SERVER['SCRIPT_NAME']);
        if ($current_script !== 'project_detail.php') {
            header("Location: project_detail.php?id=" . $allowed_pid);
            exit;
        } else {
            $requested_pid = (int)($_GET['id'] ?? 0);
            if ($requested_pid !== $allowed_pid) {
                header("HTTP/1.1 403 Forbidden");
                die("この案件へのアクセス権限がありません。");
            }
        }
    }

    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        header("HTTP/1.1 403 Forbidden");
        die("アクセス権限がありません。<br><a href='logout.php'>別のアカウントでログインする</a>");
    }
}
