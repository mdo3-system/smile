// ==========================================
// データ取得
// ==========================================
// 案件と仕様情報を取得
$stmtProj = $pdo->prepare("
    SELECT p.*, s.soil_status, u.company_name, u.contact_name as client_name
    FROM projects p 
    LEFT JOIN project_specs s ON p.id = s.project_id 
    LEFT JOIN users u ON p.client_id = u.id
    WHERE p.id = :id
");
$stmtProj->execute(['id' => $project_id]);
$project_info = $stmtProj->fetch();

if (!$project_info) {
    die("案件情報の取得に失敗しました。");
}

// 案件に関連する全ファイルを取得
$stmtFiles = $pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND is_latest = 1");
$stmtFiles->execute(['pid' => $project_id]);
$all_files = $stmtFiles->fetchAll();

// カテゴリごとに整理
$files_by_cat = [];
foreach($all_files as $f) {
    $files_by_cat[$f['file_category']] = $f;
}

// 協力業者一覧を取得
$subcontractors = $pdo->query("SELECT id, contact_name FROM users WHERE role = 'subcontractor'")->fetchAll();

// この案件への発注履歴を取得
$stmtOrders = $pdo->prepare("SELECT o.*, u.contact_name FROM subcontractor_orders o JOIN users u ON o.subcontractor_id = u.id WHERE o.project_id = :pid ORDER BY o.created_at DESC");
$stmtOrders->execute(['pid' => $project_id]);
$orders = $stmtOrders->fetchAll();

// 未承認の納品を取得
$stmtDelivered = $pdo->prepare("
    SELECT o.*, u.contact_name, f.drive_file_id, f.file_name, f.version
    FROM subcontractor_orders o 
    JOIN users u ON o.subcontractor_id = u.id 
    LEFT JOIN project_files f ON o.project_id = f.project_id AND f.file_category = 'structural_dwg' AND f.is_latest = 1
    WHERE o.project_id = :pid AND o.status = 'delivered'
");
$stmtDelivered->execute(['pid' => $project_id]);
$delivered_orders = $stmtDelivered->fetchAll();

// チャット履歴を取得
$stmtMsgs = $pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = 'client_admin' ORDER BY id ASC");
$stmtMsgs->execute(['pid' => $project_id]);
$chat_messages = $stmtMsgs->fetchAll();
