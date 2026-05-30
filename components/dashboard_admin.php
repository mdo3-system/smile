<?php
// components/dashboard_admin.php
// 管理者用ダッシュボード（全機能入り・進捗特化）
?>
<div class="container" style="max-width: 1600px;">
    <!-- 左パネル：依頼主と案件情報 -->
    <?php require __DIR__ . '/col_left.php'; ?>

    <!-- 中央パネル：成果物一覧 -->
    <?php require __DIR__ . '/col_center.php'; ?>

    <!-- 右パネル：チャット・管理ツール -->
    <?php require __DIR__ . '/col_right.php'; ?>
</div>
