<?php
/**
 * fix_deliverable_versions.php
 * 
 * 成果物スロット（custom_deliverable_% および標準成果物）において、
 * ファイル名に含まれる8桁西暦日付（YYYYMMDD）や作成日時に基づいて
 * バージョン番号（version）と最新フラグ（is_latest）を時系列順に正しく再設定するスクリプト。
 */

require_once __DIR__ . '/db_connect.php';

echo "=== Deliverable Version Normalization Started ===\n";

// 対象となる成果物スロットのリストを取得
$sqlSlots = "
    SELECT DISTINCT project_id, file_category 
    FROM project_files 
    WHERE (file_category LIKE 'custom_deliverable_%' OR file_category IN (
        'structural_dwg', 'standard_dwg', 'calc_doc', 'safety_cert', 'inv_primary', 'inv_primary_rev',
        'wall_calc_doc', 'wall_kiso_dwg', 'wall_perf_doc', 'wall_spreadsheet',
        'skin_calc_doc', 'skin_energy_doc', 'skin_desc_doc', 'skin_web_prog', 'skin_doc',
        'sky_calc_doc', 'sky_dwg', 'other_artifact', 'kiso_hari_calc_doc'
    ))
      AND drive_file_id IS NOT NULL 
      AND drive_file_id != ''
    ORDER BY project_id, file_category
";
$slots = $pdo->query($sqlSlots)->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($slots) . " deliverable slots with actual files.\n";

$updatedCount = 0;

foreach ($slots as $slot) {
    $pid = $slot['project_id'];
    $cat = $slot['file_category'];

    $stmtFiles = $pdo->prepare("
        SELECT id, file_name, version, is_latest, created_at, drive_file_id 
        FROM project_files 
        WHERE project_id = :pid AND file_category = :cat 
          AND drive_file_id IS NOT NULL AND drive_file_id != ''
    ");
    $stmtFiles->execute(['pid' => $pid, 'cat' => $cat]);
    $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

    if (count($files) <= 1) {
        // 1件以下の場合は、それが latest=1, version=1 であることを確認
        if (count($files) === 1) {
            $f = $files[0];
            if ($f['is_latest'] != 1 || $f['version'] < 1) {
                $stmtFixSingle = $pdo->prepare("UPDATE project_files SET is_latest = 1, version = 1 WHERE id = :id");
                $stmtFixSingle->execute(['id' => $f['id']]);
                $updatedCount++;
            }
        }
        continue;
    }

    // 複数件ある場合：ファイル名の日付および作成日時でソート
    // ソートキー生成クロージャ
    usort($files, function($a, $b) {
        $keyA = getFileSortKey($a['file_name'], $a['created_at'], $a['id']);
        $keyB = getFileSortKey($b['file_name'], $b['created_at'], $b['id']);
        return strcmp($keyA, $keyB);
    });

    // $files は古い順（昇順）にソートされている
    $total = count($files);
    $needsUpdate = false;

    for ($i = 0; $i < $total; $i++) {
        $expectedVer = $i + 1;
        $expectedLatest = ($i === $total - 1) ? 1 : 0; // 最後（最新）が 1、それ以外は 0

        if ($files[$i]['version'] != $expectedVer || $files[$i]['is_latest'] != $expectedLatest) {
            $needsUpdate = true;
            break;
        }
    }

    if ($needsUpdate) {
        echo "Updating Project {$pid} - Category '{$cat}':\n";
        for ($i = 0; $i < $total; $i++) {
            $f = $files[$i];
            $expectedVer = $i + 1;
            $expectedLatest = ($i === $total - 1) ? 1 : 0;

            echo "  -> ID: {$f['id']} | '{$f['file_name']}' | Old(Ver:{$f['version']}, Lat:{$f['is_latest']}) => New(Ver:{$expectedVer}, Lat:{$expectedLatest})\n";

            $stmtUpd = $pdo->prepare("
                UPDATE project_files 
                SET version = :ver, is_latest = :lat 
                WHERE id = :id
            ");
            $stmtUpd->execute([
                'ver' => $expectedVer,
                'lat' => $expectedLatest,
                'id' => $f['id']
            ]);
            $updatedCount++;
        }
    }
}

echo "=== Normalization Complete. Total records updated: {$updatedCount} ===\n";

function getFileSortKey($fileName, $createdAt, $id) {
    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    // ファイル名内の8桁西暦（2000年代〜2090年代）を検索
    if (preg_match('/(20\d{6})(?!\d)/', $nameWithoutExt, $m)) {
        $dateStr = $m[1]; // 例: 20261022
        return sprintf('%s_%s_%010d', $dateStr, $createdAt, $id);
    }
    // 日付が見つからない場合は created_at と id でソート
    return sprintf('00000000_%s_%010d', $createdAt, $id);
}
