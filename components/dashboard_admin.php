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

        <!-- カラム2: 進捗スケジュール ＋ 見積シミュレーター ＋ 一次回答後アップロード図書 -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_schedule.php'; ?>
            <?php require __DIR__ . '/col_estimator.php'; ?>
            <?php require __DIR__ . '/col_center_post_uploads.php'; ?>
        </div>

        <!-- カラム3: チャット・管理ツール -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_right.php'; ?>
        </div>
    </div>
<?php else: ?>
    <!-- 管理者(admin)用4カラムレイアウト -->
    <?php
    // 今後の微調整用のフレックス幅変数 (合計5.0に対する比率)
    $col1_flex = 1.25; // 25% (基本情報等)
    $col2_flex = 1.0;  // 20% (スケジュール等)
    $col3_flex = 0.75; // 15% (成果物等)
    $col4_flex = 2.0;  // 40% (チャット等)
    ?>
    <div class="container" style="display:flex; gap:20px; width:100%;">
        <!-- カラム1: 基本情報と経理管理（常時開） -->
        <div style="flex:<?= $col1_flex ?>; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_left.php'; ?>
        </div>

        <!-- カラム2: 進捗スケジュール ＋ 見積シミュレーター ＋ 一次回答後アップロード図書 -->
        <div style="flex:<?= $col2_flex ?>; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_schedule.php'; ?>
            <?php require __DIR__ . '/col_estimator.php'; ?>
            <?php require __DIR__ . '/col_center_post_uploads.php'; ?>
        </div>

        <!-- カラム3: 成果物一覧 ＋ 構造仕様 ＋ 依頼主アップロード図書 -->
        <div style="flex:<?= $col3_flex ?>; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_center_deliverables.php'; ?>
            <?php require __DIR__ . '/col_specs.php'; ?>
            <?php require __DIR__ . '/col_center_uploads.php'; ?>
        </div>

        <!-- カラム4: チャット・管理ツール -->
        <div style="flex:<?= $col4_flex ?>; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_right.php'; ?>
        </div>
    </div>
<?php endif; ?>
