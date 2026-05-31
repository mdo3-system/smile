<?php
session_start();
require_once '../db_connect.php';
require_once '../functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("権限がありません。");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'] ?? null;
    if (!$project_id) {
        die("Invalid project ID.");
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE projects 
            SET 
                initial_est_amount = :initial_amt,
                initial_est_date = :initial_date,
                formal_est_amount = :formal_amt,
                formal_est_date = :formal_date,
                add_est_amount = :add_amt,
                add_est_date = :add_date,
                deposit_amount = :dep_amt,
                deposit_date = :dep_date
            WHERE id = :id
        ");
        
        $stmt->execute([
            'initial_amt' => $_POST['initial_est_amount'] === '' ? null : (int)$_POST['initial_est_amount'],
            'initial_date' => $_POST['initial_est_date'] === '' ? null : $_POST['initial_est_date'],
            'formal_amt' => $_POST['formal_est_amount'] === '' ? null : (int)$_POST['formal_est_amount'],
            'formal_date' => $_POST['formal_est_date'] === '' ? null : $_POST['formal_est_date'],
            'add_amt' => $_POST['add_est_amount'] === '' ? null : (int)$_POST['add_est_amount'],
            'add_date' => $_POST['add_est_date'] === '' ? null : $_POST['add_est_date'],
            'dep_amt' => $_POST['deposit_amount'] === '' ? null : (int)$_POST['deposit_amount'],
            'dep_date' => $_POST['deposit_date'] === '' ? null : $_POST['deposit_date'],
            'id' => $project_id
        ]);

        header("Location: ../project_detail.php?id=" . $project_id . "&t=" . time());
        exit;
    } catch (Exception $e) {
        die("更新に失敗しました: " . $e->getMessage());
    }
}
