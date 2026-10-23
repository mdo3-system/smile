<?php
// migrate_fix_tsukada_user.php
require_once __DIR__ . '/db_connect.php';

try {
    $pdo->beginTransaction();

    // 1. 現状確認
    $stmt6 = $pdo->prepare("SELECT * FROM users WHERE id = 6");
    $stmt6->execute();
    $user6 = $stmt6->fetch(PDO::FETCH_ASSOC);

    $stmt96 = $pdo->prepare("SELECT * FROM users WHERE id = 96");
    $stmt96->execute();
    $user96 = $stmt96->fetch(PDO::FETCH_ASSOC);

    if (!$user6) {
        throw new Exception("User 6 (designf@thanks.work) not found.");
    }

    echo "Found user 6: " . $user6['email'] . " (" . $user6['company_name'] . ")\n";

    // 2. ID 96 のマジックリンクを ID 6 に引き継いで ID 96 を削除
    if ($user96) {
        echo "Found temporary user 96: " . $user96['email'] . " - migrating links & deleting...\n";
        $pdo->exec("UPDATE magic_links SET user_id = 6, used = 0 WHERE user_id = 96");
        $pdo->exec("DELETE FROM users WHERE id = 96");
        echo "User 96 removed successfully.\n";
    }

    // 3. ID 6 のメールアドレス・担当者名・電話番号を最新情報に更新
    $stmtUpdate6 = $pdo->prepare("
        UPDATE users 
        SET email = 'tsukada_yuji@ymail.ne.jp', 
            contact_name = '塚田裕治',
            phone_number = '08067644126',
            mobile_number = '08067644126',
            parent_id = NULL,
            allowed_project_id = NULL
        WHERE id = 6
    ");
    $stmtUpdate6->execute();
    echo "User 6 updated: email set to tsukada_yuji@ymail.ne.jp, contact_name set to 塚田裕治\n";

    // 4. 新しいマジックリンクトークンを発行（有効期限7日間）
    $token_portal = bin2hex(random_bytes(32));
    $stmtMagicPortal = $pdo->prepare("
        INSERT INTO magic_links (user_id, token, expires_at, used) 
        VALUES (6, :token, DATE_ADD(NOW(), INTERVAL 7 DAY), 0)
    ");
    $stmtMagicPortal->execute(['token' => $token_portal]);

    $token_project21 = bin2hex(random_bytes(32));
    $stmtMagicProj = $pdo->prepare("
        INSERT INTO magic_links (user_id, token, expires_at, used) 
        VALUES (6, :token, DATE_ADD(NOW(), INTERVAL 7 DAY), 0)
    ");
    $stmtMagicProj->execute(['token' => $token_project21]);

    $pdo->commit();

    echo "SUCCESS: Tsukada user migration complete!\n";
    echo "\n=== 生成されたログインリンク ===\n";
    echo "1. 依頼者ポータルログインリンク:\n";
    echo "   https://system.thanks.work/index.php?token=" . $token_portal . "\n\n";
    echo "2. 案件21個別ダッシュボードログインリンク:\n";
    echo "   https://system.thanks.work/project_detail.php?id=21&token=" . $token_project21 . "\n\n";

    // 案件の確認
    $stmtProjects = $pdo->query("SELECT id, project_name, client_id, status FROM projects WHERE client_id = 6");
    echo "=== ユーザー6に紐付いている案件一覧 ===\n";
    print_r($stmtProjects->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
