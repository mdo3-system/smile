<?php
// components/dashboard_admin.php
// 管理者用ダッシュボード（全機能入り・進捗特化）
?>
<div class="container" style="display:flex; gap:20px; width:100%;">
    <!-- カラム1: 基本情報 + 依頼主アップロード図書 -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_left.php'; ?>
        <?php require __DIR__ . '/col_center_uploads.php'; ?>
    </div>

    <!-- カラム2: スケジュール -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_schedule.php'; ?>
    </div>

    <!-- カラム3: 成果物一覧 + 見積シミュレーター -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_center_deliverables.php'; ?>
        <?php require __DIR__ . '/col_estimator.php'; ?>
    </div>

    <!-- カラム4: チャット・管理ツール -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_right.php'; ?>
    </div>
</div>
