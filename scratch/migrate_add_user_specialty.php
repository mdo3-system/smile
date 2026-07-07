<?php
// scratch/migrate_add_user_specialty.php
require_once __DIR__ . '/../db_connect.php';

try {
    // 1. カラムの追加 (存在しない場合のみ)
    $stmtCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'sub_specialty'");
    $columnExists = $stmtCheck->fetch();

    if (!$columnExists) {
        $pdo->exec("ALTER TABLE users ADD COLUMN sub_specialty VARCHAR(50) DEFAULT 'both' AFTER parent_id");
        echo "Column 'sub_specialty' added to 'users' table successfully.\n";
    } else {
        echo "Column 'sub_specialty' already exists in 'users' table.\n";
    }

    // 2. ID 15 と ID 17 の初期設定
    // ID 15: 意匠図担当
    $stmt15 = $pdo->prepare("UPDATE users SET sub_specialty = 'design' WHERE id = 15");
    $stmt15->execute();
    echo "User ID 15 specialty set to 'design'.\n";

    // ID 17: 構造図担当
    $stmt17 = $pdo->prepare("UPDATE users SET sub_specialty = 'structural' WHERE id = 17");
    $stmt17->execute();
    echo "User ID 17 specialty set to 'structural'.\n";

    // 親ID: 3 は 'both' に設定 (念のため)
    $stmt3 = $pdo->prepare("UPDATE users SET sub_specialty = 'both' WHERE id = 3");
    $stmt3->execute();
    echo "User ID 3 specialty set to 'both'.\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
