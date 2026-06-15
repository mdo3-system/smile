<?php
// components/dashboard_accountant.php
// 経理(accountant)専用ダッシュボード（3カラムレイアウト）
?>
<div class="container" style="display:flex; gap:20px; width:100%;">
    <!-- カラム1: 基本情報と経理管理（常時開） -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_left.php'; ?>
    </div>

    <!-- カラム2: 進捗スケジュール ＋ 見積シミュレーター -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_schedule.php'; ?>
        <?php require __DIR__ . '/col_estimator.php'; ?>
    </div>

    <!-- カラム3: チャット・管理ツール -->
    <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
        <?php require __DIR__ . '/col_right.php'; ?>
    </div>
</div>
