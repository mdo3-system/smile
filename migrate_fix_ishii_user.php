<?php
// migrate_fix_ishii_user.php
require_once __DIR__ . '/db_connect.php';

try {
    $pdo->beginTransaction();

    // 1. 現状確認
    $stmt87 = $pdo->prepare("SELECT * FROM users WHERE id = 87");
    $stmt87->execute();
    $user87 = $stmt87->fetch(PDO::FETCH_ASSOC);

    $stmt93 = $pdo->prepare("SELECT * FROM users WHERE id = 93");
    $stmt93->execute();
    $user93 = $stmt93->fetch(PDO::FETCH_ASSOC);

    if (!$user87) {
        throw new Exception("User 87 (ishi@thanks.work) not found.");
    }

    echo "Found user 87: " . $user87['email'] . "\n";

    // 2. ID 93 のマジックリンクを ID 87 に引き継ぐか削除
    if ($user93) {
        echo "Found user 93: " . $user93['email'] . " - removing/migrating links...\n";
        // magic_links の user_id を 87 に更新（石井様が直前にクリックしたリンクでもログイン可能にする）
        $pdo->exec("UPDATE magic_links SET user_id = 87 WHERE user_id = 93");
        
        // user 93 を削除
        $pdo->exec("DELETE FROM users WHERE id = 93");
        echo "User 93 removed successfully.\n";
    }

    // 3. ID 87 のメールアドレスを contact@ishii-komuten.jp に更新
    $stmtUpdate87 = $pdo->prepare("
        UPDATE users 
        SET email = 'contact@ishii-komuten.jp', 
            contact_name = '代表取締役 石井淳一',
            parent_id = NULL,
            allowed_project_id = NULL
        WHERE id = 87
    ");
    $stmtUpdate87->execute();
    echo "User 87 updated: email set to contact@ishii-komuten.jp\n";

    // 4. 新しいマジックリンクトークンを発行（有効期限30分）
    $token = bin2hex(random_bytes(32));
    $stmtMagic = $pdo->prepare("
        INSERT INTO magic_links (user_id, token, expires_at, used) 
        VALUES (87, :token, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 0)
    ");
    $stmtMagic->execute(['token' => $token]);
    echo "New magic link created for user 87: token=" . $token . "\n";

    $pdo->commit();

    echo "SUCCESS: Ishii Komuten portal migration complete!\n";

    // 案件67の確認
    $stmt67 = $pdo->query("SELECT id, project_name, client_id FROM projects WHERE id = 67");
    print_r($stmt67->fetch(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
