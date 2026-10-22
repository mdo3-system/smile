<?php
require_once __DIR__ . '/db_connect.php';

// 成果物カテゴリ一覧
$artifact_categories = [
    'structural_dwg', 'standard_dwg', 'calc_doc', 'safety_cert', 'inv_primary', 'inv_primary_rev',
    'wall_calc_doc', 'wall_kiso_dwg', 'wall_perf_doc', 'wall_spreadsheet',
    'skin_calc_doc', 'skin_energy_doc', 'skin_desc_doc', 'skin_web_prog', 'skin_doc',
    'sky_calc_doc', 'sky_dwg', 'other_artifact', 'kiso_hari_calc_doc'
];

$placeholders = implode(',', array_fill(0, count($artifact_categories), '?'));
$sql = "
    SELECT project_id, file_category, COUNT(*) as cnt 
    FROM project_files 
    WHERE is_latest = 1 AND (file_category IN ($placeholders) OR file_category LIKE 'custom_deliverable_%')
    GROUP BY project_id, file_category 
    HAVING cnt > 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute($artifact_categories);
$dups = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found duplicate latest categories: " . count($dups) . PHP_EOL;

foreach ($dups as $d) {
    $pid = $d['project_id'];
    $cat = $d['file_category'];

    // 最新の1件（最大version、同versionなら最大id）を特定
    $stmtFindLatest = $pdo->prepare("
        SELECT id FROM project_files 
        WHERE project_id = :pid AND file_category = :cat AND is_latest = 1 
        ORDER BY version DESC, id DESC 
        LIMIT 1
    ");
    $stmtFindLatest->execute(['pid' => $pid, 'cat' => $cat]);
    $keepId = $stmtFindLatest->fetchColumn();

    if ($keepId) {
        $stmtFix = $pdo->prepare("
            UPDATE project_files 
            SET is_latest = 0 
            WHERE project_id = :pid AND file_category = :cat AND id != :keep_id
        ");
        $stmtFix->execute(['pid' => $pid, 'cat' => $cat, 'keep_id' => $keepId]);
        echo "Normalized project {$pid}, category {$cat}: kept ID {$keepId} as latest" . PHP_EOL;
    }
}
echo "Normalization complete." . PHP_EOL;
