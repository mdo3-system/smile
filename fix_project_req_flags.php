<?php
require_once __DIR__ . '/db_connect.php';

echo "=== 案件仕様フラグ (req_*) 自動修復処理開始 ===\n";

$stmt = $pdo->query("SELECT id, project_name, req_permit, req_wall, req_skin, req_sky, req_opt_kisohari FROM projects");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$repaired_count = 0;

foreach ($projects as $p) {
    $pid = $p['id'];
    $pname = $p['project_name'];
    
    $req_permit = (int)$p['req_permit'];
    $req_wall   = (int)$p['req_wall'];
    $req_skin   = (int)$p['req_skin'];
    $req_sky    = (int)$p['req_sky'];
    $req_opt_kisohari = (int)$p['req_opt_kisohari'];

    $orig_permit = $req_permit;
    $orig_wall = $req_wall;
    $orig_skin = $req_skin;
    $orig_sky = $req_sky;
    $orig_kisohari = $req_opt_kisohari;

    // 1. estimates テーブルの過去入力履歴 (inputs_json) から復元
    $stmtEst = $pdo->prepare("SELECT inputs_json FROM estimates WHERE project_id = :pid");
    $stmtEst->execute(['pid' => $pid]);
    $estimates = $stmtEst->fetchAll(PDO::FETCH_ASSOC);

    foreach ($estimates as $est) {
        $json = $est['inputs_json'] ?? '{}';
        $inputs = json_decode($json, true) ?: [];

        if (!empty($inputs['est_active_permit'])) $req_permit = 1;
        if (!empty($inputs['est_active_wall']))   $req_wall = 1;
        if (!empty($inputs['est_active_skin']))   $req_skin = 1;
        if (!empty($inputs['est_active_sky']))    $req_sky = 1;

        if (!empty($inputs['est_active_permit']) || !empty($inputs['est_kisohari_wall']) || !empty($inputs['est_opt_kisohari_calc'])) {
            $req_opt_kisohari = 1;
        }
    }

    // 2. project_files テーブルに保存されている成果物カテゴリから復元
    $stmtFiles = $pdo->prepare("SELECT file_category FROM project_files WHERE project_id = :pid");
    $stmtFiles->execute(['pid' => $pid]);
    $files = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);

    foreach ($files as $cat) {
        if (in_array($cat, ['safety_cert', 'standard_dwg', 'structural_dwg', 'calc_doc'])) {
            $req_permit = 1;
        }
        if (in_array($cat, ['wall_spreadsheet', 'wall_calc_doc'])) {
            $req_wall = 1;
        }
        if (in_array($cat, ['skin_calc_doc', 'skin_web_prog', 'skin_doc'])) {
            $req_skin = 1;
        }
        if (in_array($cat, ['sky_dwg'])) {
            $req_sky = 1;
        }
        if (in_array($cat, ['kiso_hari_calc_doc'])) {
            $req_opt_kisohari = 1;
        }
    }

    // 3. subcontractor_orders テーブルから復元
    $stmtOrders = $pdo->prepare("SELECT order_type, task_title FROM subcontractor_orders WHERE project_id = :pid");
    $stmtOrders->execute(['pid' => $pid]);
    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as $ord) {
        $type = $ord['order_type'] ?? '';
        $title = $ord['task_title'] ?? '';
        if ($type === 'struct' || strpos($title, '構造') !== false) {
            $req_permit = 1;
        }
    }

    // フラグ全てが 0 の場合でデフォルト復元が必要な場合（デフォルトとして壁量計算または許容応力度計算を想定）
    // 変更があった場合のみ DB を更新
    if ($req_permit !== $orig_permit || $req_wall !== $orig_wall || $req_skin !== $orig_skin || $req_sky !== $orig_sky || $req_opt_kisohari !== $orig_kisohari) {
        $stmtUpdate = $pdo->prepare("
            UPDATE projects 
            SET req_permit = :permit, 
                req_wall = :wall, 
                req_skin = :skin, 
                req_sky = :sky, 
                req_opt_kisohari = :kisohari 
            WHERE id = :pid
        ");
        $stmtUpdate->execute([
            'permit'   => $req_permit,
            'wall'     => $req_wall,
            'skin'     => $req_skin,
            'sky'      => $req_sky,
            'kisohari' => $req_opt_kisohari,
            'pid'      => $pid
        ]);

        echo "修復完了 [ID: {$pid} - {$pname}]:\n";
        echo "  - req_permit: {$orig_permit} -> {$req_permit}\n";
        echo "  - req_wall: {$orig_wall} -> {$req_wall}\n";
        echo "  - req_skin: {$orig_skin} -> {$req_skin}\n";
        echo "  - req_sky: {$orig_sky} -> {$req_sky}\n";
        echo "  - req_opt_kisohari: {$orig_kisohari} -> {$req_opt_kisohari}\n";
        $repaired_count++;
    }
}

echo "=== 自動修復処理終了 (合計 {$repaired_count} 件修復) ===\n";
