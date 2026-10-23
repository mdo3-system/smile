<?php
// api_generate_client_login_url.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// 管理者権限チェック
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '管理者権限が必要です。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$project_id = !empty($input['project_id']) ? (int)$input['project_id'] : 0;
$user_id = !empty($input['user_id']) ? (int)$input['user_id'] : 0;
$new_email = isset($input['new_email']) ? trim($input['new_email']) : null;

try {
    $pdo->beginTransaction();

    // 1. 対象ユーザーの特定
    if ($project_id > 0) {
        $stmtProj = $pdo->prepare("SELECT id, project_name, client_id FROM projects WHERE id = :id");
        $stmtProj->execute(['id' => $project_id]);
        $project = $stmtProj->fetch(PDO::FETCH_ASSOC);

        if (!$project) {
            throw new Exception("指定された案件（ID: {$project_id}）が見つかりません。");
        }
        if (empty($project['client_id'])) {
            throw new Exception("案件に依頼主（client_id）が紐付いていません。");
        }
        $user_id = (int)$project['client_id'];
    }

    if ($user_id <= 0) {
        throw new Exception("案件IDまたは依頼主ユーザーIDが指定されていません。");
    }

    $stmtUser = $pdo->prepare("SELECT id, company_name, contact_name, email, role, phone_number, mobile_number FROM users WHERE id = :id");
    $stmtUser->execute(['id' => $user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("依頼主ユーザー（ID: {$user_id}）が見つかりません。");
    }

    // 2. メールアドレスの更新（指定された場合）
    $email_updated = false;
    if ($new_email !== null && $new_email !== '' && $new_email !== $user['email']) {
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("有効なメールアドレス形式を入力してください。");
        }

        // 他のユーザーで既に同じメールアドレスが存在するかチェック
        $stmtConflict = $pdo->prepare("SELECT id, company_name, contact_name, role FROM users WHERE email = :email AND id != :id");
        $stmtConflict->execute(['email' => $new_email, 'id' => $user_id]);
        $conflictUser = $stmtConflict->fetch(PDO::FETCH_ASSOC);

        if ($conflictUser) {
            // もし案件が紐付いていない別ユーザー（誤登録等）であれば統合・削除を試行
            $stmtConfProjects = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = :cid");
            $stmtConfProjects->execute(['cid' => $conflictUser['id']]);
            $confProjCount = (int)$stmtConfProjects->fetchColumn();

            if ($confProjCount === 0) {
                // 案件を持たない重複ユーザーのマジックリンクを引き継いで削除
                $pdo->prepare("UPDATE magic_links SET user_id = :new_uid WHERE user_id = :old_uid")->execute([
                    'new_uid' => $user_id,
                    'old_uid' => $conflictUser['id']
                ]);
                $pdo->prepare("DELETE FROM users WHERE id = :old_uid")->execute(['old_uid' => $conflictUser['id']]);
            } else {
                throw new Exception("メールアドレス「{$new_email}」は既に別のユーザー（ID: {$conflictUser['id']}, {$conflictUser['company_name']}）で使用されています。統合が必要な場合はご確認ください。");
            }
        }

        $stmtUpd = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
        $stmtUpd->execute(['email' => $new_email, 'id' => $user_id]);
        $user['email'] = $new_email;
        $email_updated = true;
    }

    // 3. マジックリンクトークンの生成（7日間有効）
    // ポータル用トークン
    $token_portal = bin2hex(random_bytes(32));
    $stmtMagicPortal = $pdo->prepare("
        INSERT INTO magic_links (user_id, token, expires_at, used) 
        VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 7 DAY), 0)
    ");
    $stmtMagicPortal->execute([
        'user_id' => $user_id,
        'token'   => $token_portal
    ]);

    // 案件専用トークン（案件指定がある場合）
    $token_project = null;
    if ($project_id > 0) {
        $token_project = bin2hex(random_bytes(32));
        $stmtMagicProj = $pdo->prepare("
            INSERT INTO magic_links (user_id, token, expires_at, used) 
            VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 7 DAY), 0)
        ");
        $stmtMagicProj->execute([
            'user_id' => $user_id,
            'token'   => $token_project
        ]);
    }

    $pdo->commit();

    // 4. URL生成
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

    $portal_url = "{$app_url}/index.php?token={$token_portal}";
    $project_url = $project_id > 0 ? "{$app_url}/project_detail.php?id={$project_id}&token={$token_project}" : null;

    echo json_encode([
        'success'           => true,
        'user_id'           => $user_id,
        'company_name'      => $user['company_name'],
        'contact_name'      => $user['contact_name'],
        'email'             => $user['email'],
        'email_updated'     => $email_updated,
        'project_id'        => $project_id,
        'project_name'      => $project['project_name'] ?? null,
        'portal_login_url'  => $portal_url,
        'project_login_url' => $project_url,
        'expires_text'      => '発行から7日間有効'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
