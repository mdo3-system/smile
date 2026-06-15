<?php
// components/dashboard_admin.php
// 管理者用ダッシュボード（全機能入り・進捗特化）
?>
<?php if (($_SESSION['role'] ?? '') === 'accountant'): ?>
    <!-- 経理(accountant)用3カラムレイアウト -->
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
<?php else: ?>
    <!-- 管理者(admin)用4カラムレイアウト -->
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

        <!-- カラム3: 依頼主アップロード図書 ＋ 成果物一覧 -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_center_uploads.php'; ?>
            <?php require __DIR__ . '/col_specs.php'; ?>
            <?php require __DIR__ . '/col_center_deliverables.php'; ?>
        </div>

        <!-- カラム4: チャット・管理ツール -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_right.php'; ?>
        </div>
    </div>
<?php endif; ?>
