<?php
// api_save_estimate.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin']); // 管理者のみアクセス可能

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'] ?? 0;
    $base_price = $_POST['base_price'] ?? 0;
    $area = $_POST['area'] ?? 0;
    $grade_price = $_POST['grade_price'] ?? 0;
    $total_price = $_POST['total_price'] ?? 0;

    // 既にレコードがあるか確認
    $stmt = $pdo->prepare("SELECT id FROM estimates WHERE project_id = :pid");
    $stmt->execute(['pid' => $project_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmtUpdate = $pdo->prepare("UPDATE estimates SET base_price = :base, area = :area, grade_price = :grade, total_price = :total, updated_at = NOW() WHERE project_id = :pid");
        $stmtUpdate->execute([
            'base' => $base_price,
            'area' => $area,
            'grade' => $grade_price,
            'total' => $total_price,
            'pid' => $project_id
        ]);
    } else {
        // テーブルが存在しない場合は作成（初回のみ）
        $pdo->exec("CREATE TABLE IF NOT EXISTS estimates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            base_price INT DEFAULT 0,
            area FLOAT DEFAULT 0,
            grade_price INT DEFAULT 0,
            total_price INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )");
        
        $stmtInsert = $pdo->prepare("INSERT INTO estimates (project_id, base_price, area, grade_price, total_price) VALUES (:pid, :base, :area, :grade, :total)");
        $stmtInsert->execute([
            'pid' => $project_id,
            'base' => $base_price,
            'area' => $area,
            'grade' => $grade_price,
            'total' => $total_price
        ]);
    }

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
