<?php
require_once 'db_connect.php';

try {
    // 協力業者（role = subcontractor）が送信したメッセージで、誤って client_admin に分類されているものを sub_admin に修正する
    $stmt = $pdo->prepare("
        UPDATE messages m
        JOIN users u ON m.sender_id = u.id
        SET m.thread_type = 'sub_admin'
        WHERE u.role = 'subcontractor' AND m.thread_type = 'client_admin'
    ");
    $stmt->execute();
    echo "Updated " . $stmt->rowCount() . " messages to sub_admin thread.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
