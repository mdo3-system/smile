<?php
// components/dashboard_admin.php
// 管理者用ダッシュボード（全機能入り・進捗特化）
?>
<div class="container" style="max-width: 1600px; display:flex; gap:20px; width:100%;">
    <!-- 左パネル：依頼主と案件情報 -->
    <?php require __DIR__ . '/col_left.php'; ?>

    <!-- 中央パネル1：成果物一覧 -->
    <?php require __DIR__ . '/col_center_deliverables.php'; ?>

    <!-- 中央パネル2：依頼主アップロード図書と不足図書 -->
    <?php require __DIR__ . '/col_center_uploads.php'; ?>

    <!-- 右パネル：チャット・管理ツール -->
    <?php require __DIR__ . '/col_right.php'; ?>
</div>
