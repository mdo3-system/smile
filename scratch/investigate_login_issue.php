<?php
// scratch/investigate_login_issue.php
require_once __DIR__ . '/../db_connect.php';

echo "=== 1. Searching projects matching 'フジイエ稲毛' ===\n";
$stmtProj = $pdo->prepare("SELECT id, project_name, status, client_id FROM projects WHERE project_name LIKE :name");
$stmtProj->execute(['name' => '%フジイエ稲毛%']);
$projects = $stmtProj->fetchAll(PDO::FETCH_ASSOC);

if (empty($projects)) {
    echo "No projects found matching 'フジイエ稲毛'. Searching general recent projects...\n";
    $stmtProj = $pdo->query("SELECT id, project_name, status FROM projects ORDER BY id DESC LIMIT 5");
    $projects = $stmtProj->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($projects as $pj) {
    echo "Project ID: {$pj['id']} | Name: {$pj['project_name']} | Status: {$pj['status']}\n";
    
    echo "--- subcontractor_orders for this project ---\n";
    $stmtOrders = $pdo->prepare("
        SELECT o.id, o.subcontractor_id, o.task_title, o.status, u.contact_name, u.email, u.role, u.parent_id 
        FROM subcontractor_orders o
        LEFT JOIN users u ON o.subcontractor_id = u.id
        WHERE o.project_id = :pid
    ");
    $stmtOrders->execute(['pid' => $pj['id']]);
    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($orders)) {
        echo "  No subcontractor orders found.\n";
    }
    foreach ($orders as $ord) {
        echo "  Order ID: {$ord['id']} | Sub ID: {$ord['subcontractor_id']} | Task: {$ord['task_title']} | Status: {$ord['status']} | Contact: {$ord['contact_name']} | Email: {$ord['email']} | Role: {$ord['role']} | Parent ID: {$ord['parent_id']}\n";
        
        if (!empty($ord['subcontractor_id'])) {
            check_user_magic_links($pdo, $ord['subcontractor_id'], $ord['email']);
            // 親アカウントやグループメンバーも調べる
            $parent_id = $ord['parent_id'] ?: $ord['subcontractor_id'];
            echo "  --- Checking related users in company (Parent ID: {$parent_id}) ---\n";
            $stmtGroup = $pdo->prepare("SELECT id, contact_name, email, role, parent_id FROM users WHERE id = :p1 OR parent_id = :p2");
            $stmtGroup->execute(['p1' => $parent_id, 'p2' => $parent_id]);
            foreach ($stmtGroup->fetchAll(PDO::FETCH_ASSOC) as $guser) {
                if ($guser['id'] != $ord['subcontractor_id']) {
                    echo "    Group User: ID={$guser['id']} | Name={$guser['contact_name']} | Email={$guser['email']} | Role={$guser['role']} | Parent={$guser['parent_id']}\n";
                    check_user_magic_links($pdo, $guser['id'], $guser['email']);
                }
            }
        }
    }
}

function check_user_magic_links($pdo, $userId, $email) {
    echo "  --- magic_links for User ID: {$userId} ({$email}) ---\n";
    $stmtLinks = $pdo->prepare("
        SELECT id, expires_at, used, used_at, NOW() as db_now 
        FROM magic_links 
        WHERE user_id = :uid 
        ORDER BY id DESC LIMIT 3
    ");
    $stmtLinks->execute(['uid' => $userId]);
    $links = $stmtLinks->fetchAll(PDO::FETCH_ASSOC);
    if (empty($links)) {
        echo "    No magic links found.\n";
    }
    foreach ($links as $link) {
        echo "    Link ID: {$link['id']} | Expires: {$link['expires_at']} | Used: {$link['used']} | UsedAt: {$link['used_at']} | DB Now: {$link['db_now']}\n";
    }
}
