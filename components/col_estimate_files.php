<?php
// components/col_estimate_files.php
// 見積時の受領図面を表示する共通コンポーネント
// 必要な変数: $files_by_cat (配列)
?>
<div class="box" style="background:#f1f5f9; border-color:#cbd5e1; margin-top:15px;">
    <h3 style="margin-top:0; font-size:14px; color:#334155; border-bottom:1px solid #cbd5e1; padding-bottom:5px;">📋 見積時の受領図面</h3>
    <div style="font-size:11px; color:#64748b; margin-bottom:10px;">※見積依頼時にご提示いただいた参考図面です。</div>
    <div style="display:flex; flex-direction:column; gap:8px;">
        <?php
        $est_pdf_cats = getEstimatePdfCategories(); // functions.php の共通定義を使用
        $has_est_files = false;
        foreach ($est_pdf_cats as $cat => $label) {
            if (!empty($files_by_cat[$cat])) {
                $has_est_files = true;
                echo "<div style='margin-bottom:8px;'><strong style='color:#1e40af; font-size:12px;'>{$label}:</strong><br>";
                foreach ($files_by_cat[$cat] as $f) {
                    $url = (strpos($f['drive_file_id'], 'uploads/') !== 0 && !empty($f['drive_file_id']))
                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                        : htmlspecialchars($f['drive_file_id'], ENT_QUOTES);
                    echo "<div style='margin-bottom:3px;'><a href='{$url}' target='_blank' class='file-link' style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:90%;'>📄 " . htmlspecialchars($f['file_name'], ENT_QUOTES) . "</a></div>";
                }
                echo "</div>";
            }
        }
        if (!$has_est_files) {
            echo "<div style='color:#999; font-size:12px;'>提出された図面はありません。</div>";
        }
        ?>
    </div>
</div>
