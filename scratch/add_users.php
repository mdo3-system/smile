<?php
// scratch/add_users.php
// 指定された7件のテスト用クライアント（client）をデータベースに追加するスクリプト

require_once __DIR__ . '/../db_connect.php';

$clients = [
    ['company_name' => 'デザイン・フォース', 'email' => 'designF@thanks.work'],
    ['company_name' => 'HIGH-END', 'email' => 'highE@thanks.work'],
    ['company_name' => 'フジ設計企画', 'email' => 'fuji@thanks.work'],
    ['company_name' => '株式会社ウィッシュホーム', 'email' => 'wish@thanks.work'],
    ['company_name' => '株式会社Mieux', 'email' => 'mieux@thanks.work'],
    ['company_name' => '株式会社宮﨑一級建築士事務所', 'email' => 'miya@thanks.work'],
    ['company_name' => 'しの設計', 'email' => 'shino@thanks.work'],
];

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (company_name, contact_name, email, role) 
        VALUES (:company, :contact, :email, 'client')
    ");
    
    foreach ($clients as $c) {
        // 重複チェック
        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
        $stmtChk->execute(['email' => $c['email']]);
        if ($stmtChk->fetchColumn() > 0) {
            echo "登録済み（スキップ）: {$c['company_name']} ({$c['email']})\n";
            continue;
        }

        $stmt->execute([
            'company' => $c['company_name'],
            'contact' => $c['company_name'],
            'email' => $c['email']
        ]);
        echo "ユーザーを追加しました: {$c['company_name']} ({$c['email']})\n";
    }
} catch (Exception $e) {
    echo "エラーが発生しました: " . $e->getMessage() . "\n";
}
