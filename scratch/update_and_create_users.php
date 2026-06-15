<?php
require_once __DIR__ . '/../db_connect.php';

try {
    $pdo->beginTransaction();

    // 1. 既存のクライアント ID 2 の会社名を「株式会社テスト工務店1」へ更新
    $stmt1 = $pdo->prepare("UPDATE users SET company_name = '株式会社テスト工務店1' WHERE id = 2");
    $stmt1->execute();
    echo "Updated user ID 2 company name to '株式会社テスト工務店1'\n";

    // 2. abc@def.com の既存レコードがあれば削除（重複エラー防止）
    $stmtDel = $pdo->prepare("DELETE FROM users WHERE email = 'abc@def.com'");
    $stmtDel->execute();

    // 3. 新規クライアントアカウント「株式会社テスト工務店2」を作成
    $stmt2 = $pdo->prepare("
        INSERT INTO users (company_name, contact_name, email, role, phone_number) 
        VALUES ('株式会社テスト工務店2', 'テスト依頼主2', 'abc@def.com', 'client', '')
    ");
    $stmt2->execute();
    $newId = $pdo->lastInsertId();
    echo "Created new user '株式会社テスト工務店2' (email: abc@def.com) with ID: {$newId}\n";

    $pdo->commit();
    echo "All operations completed successfully!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
