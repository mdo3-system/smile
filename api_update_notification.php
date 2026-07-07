<?php
// api_update_notification.php
require_once 'auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// GETリクエスト時は、登録済みの追加メールアドレスの一覧を返す
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT email FROM user_notification_emails WHERE user_id = :uid ORDER BY id ASC");
        $stmt->execute(['uid' => $user_id]);
        $emails = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        echo json_encode(['success' => true, 'emails' => $emails]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$enabled = isset($input['enabled']) ? intval($input['enabled']) : 1;
$additional_emails_str = $input['additional_emails'] ?? '';

$pdo->beginTransaction();
try {
    // 1. 通知トグル設定の更新
    $stmt = $pdo->prepare("UPDATE users SET email_notification_enabled = :val WHERE id = :uid");
    $stmt->execute([
        'val' => $enabled,
        'uid' => $user_id
    ]);
    $_SESSION['email_notification_enabled'] = $enabled;

    // 2. 追加通知メールアドレスの更新
    $stmtDelete = $pdo->prepare("DELETE FROM user_notification_emails WHERE user_id = :uid");
    $stmtDelete->execute(['uid' => $user_id]);

    $inserted_emails = [];
    if (!empty($additional_emails_str)) {
        // 改行、またはカンマで分割
        $lines = preg_split('/[\r\n,]+/', $additional_emails_str);
        $stmtInsert = $pdo->prepare("INSERT INTO user_notification_emails (user_id, email) VALUES (:uid, :email)");
        
        foreach ($lines as $line) {
            $email = trim($line);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stmtInsert->execute([
                    'uid' => $user_id,
                    'email' => $email
                ]);
                $inserted_emails[] = $email;
            }
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'enabled' => $enabled, 
        'emails' => $inserted_emails
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
