<?php
// estimate_print.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'client']);

$project_id = $_GET['id'] ?? null;
if (!$project_id) { die("案件が指定されていません。"); }

// 案件情報を取得
$stmt = $pdo->prepare("
    SELECT p.project_name, p.billing_company_name, u.company_name, u.contact_name 
    FROM projects p 
    JOIN users u ON p.client_id = u.id 
    WHERE p.id = :pid
");
$stmt->execute(['pid' => $project_id]);
$project_data = $stmt->fetch();

// 最新または特定の見積もり情報を取得
$est_id = $_GET['est_id'] ?? null;
if ($est_id) {
    $stmtEst = $pdo->prepare("SELECT * FROM estimates WHERE id = :est_id AND project_id = :pid");
    $stmtEst->execute(['est_id' => $est_id, 'pid' => $project_id]);
} else {
    $stmtEst = $pdo->prepare("SELECT * FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
    $stmtEst->execute(['pid' => $project_id]);
}
$estimate_data = $stmtEst->fetch();

if ($project_data && $estimate_data) {
    $data = array_merge($project_data, $estimate_data);
} else {
    $data = $project_data;
}

if (!$data) {
    die("案件情報の取得に失敗しました。");
}

if (!empty($data['pdf_drive_file_id'])) {
    // Google Drive上のPDFプレビュー画面へリダイレクト
    $drive_url = "https://drive.google.com/file/d/" . $data['pdf_drive_file_id'] . "/view?usp=drivesdk";
    header("Location: " . $drive_url);
    exit;
}

if (!$data['total_price']) {
    die("この案件にはまだ保存された見積もりがありません。");
}

        $tax = round($data['total_price'] * 0.1);
        $grand_total = $data['total_price'] + $tax;

        $items = [];
        if (!empty($data['note'])) {
            $items = json_decode($data['note'], true);
        }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>御見積書 - <?= htmlspecialchars($data['project_name'], ENT_QUOTES) ?></title>
    <style>
        @page { size: A4; margin: 15mm; }
        body { font-family: "MS Mincho", "Noto Serif JP", serif; color: #333; line-height: 1.6; margin: 0; padding: 0; background: #fff; }
        .container { width: 100%; max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; font-size: 28px; letter-spacing: 8px; margin-bottom: 40px; }
        .info-section { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .client-info { flex: 1; }
        .client-name { font-size: 20px; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; display: inline-block; }
        .project-desc { font-size: 14px; margin-top: 15px; }
        .company-info { width: 42%; font-size: 13px; text-align: right; border-left: 1px solid #ddd; padding-left: 20px; }
        .company-name { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .total-block { margin-top: 30px; margin-bottom: 20px; width: 80%; border-bottom: 3px solid #000; padding-bottom: 5px; }
        .total-label { font-size: 16px; }
        .total-amount { font-size: 24px; font-weight: bold; margin-left: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 30px; font-size: 14px; }
        th, td { padding: 10px; }
        th { background-color: #f0f0f0; border-top: 2px solid #000; border-bottom: 1px solid #000; text-align: center; }
        td { border-bottom: 1px solid #ddd; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .subtotal-row td { border-bottom: 1px solid #ddd; }
        .total-row td { border-bottom: 2px solid #000; font-size: 16px; font-weight: bold; }
        .disclaimer { margin-top: 40px; font-size: 13px; color: #333; line-height: 1.6; }
        .inactive { color: #999; }
        
        .print-btn { display: block; width: 200px; margin: 20px auto; padding: 10px; background: #0056b3; color: white; text-align: center; text-decoration: none; border-radius: 5px; cursor: pointer; border: none; font-size: 16px; }
        @media print { .print-btn { display: none; } body { background: none; } .container { padding: 0; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">このページを印刷</button>

    <div class="container">
        <div class="header">御 見 積 書</div>
        
        <div class="info-section">
            <div class="client-info">
                <div class="client-name">
                    <?php 
                        $billing_name = !empty($data['billing_company_name']) ? $data['billing_company_name'] : ($data['company_name'] ?? '');
                        if(!empty($billing_name)): 
                    ?>
                        <?= htmlspecialchars($billing_name, ENT_QUOTES) ?><br>
                    <?php endif; ?>
                    <?= htmlspecialchars($data['contact_name'], ENT_QUOTES) ?> 様
                </div>
                <div class="project-desc">
                    下記の通り、御見積申し上げます。<br><br>
                    <strong>件名:</strong> <?= htmlspecialchars($data['project_name'], ENT_QUOTES) ?> 新築工事 設計等業務
                </div>
                <div class="total-block">
                    <span class="total-label">御見積金額</span>
                    <span class="total-amount">¥<?= number_format($grand_total) ?></span>
                    <span style="font-size:12px;">(税込)</span>
                </div>
            </div>
            <div class="company-info">
                <div style="margin-bottom: 10px; font-size:13px;">発行日: <?= date('Y年m月d日') ?></div>
                <div style="font-size:18px; font-weight:bold; margin-bottom:4px;">株式会社住ま居る</div>
                <div style="font-size:14px; margin-bottom:4px;">代表取締役 菅原 功樹</div>
                <div style="font-size:12px; line-height:1.8; color:#444;">
                    〒350-2224<br>
                    埼玉県鶴ヶ島市町屋176-5<br>
                    TEL : 049-271-2350<br>
                    登録番号 : T6030001070141<br>
                    消費税 税率10%
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:45%;">品目名</th>
                    <th style="width:15%;">数量</th>
                    <th style="width:20%;">単価</th>
                    <th style="width:20%;">金額</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items) && is_array($items)): ?>
                    <?php foreach($items as $item): 
                        $is_active = isset($item['is_active']) ? $item['is_active'] : (intval($item['amount']) > 0);
                        $class = $is_active ? '' : 'inactive';
                        $qty_disp = $is_active ? htmlspecialchars($item['qty'] . ' ' . $item['unit']) : '-';
                        $price_disp = '¥' . number_format(intval($item['price']));
                        $amount_disp = $is_active ? '¥' . number_format(intval($item['amount'])) : '対象外';
                    ?>
                    <tr class="<?= $class ?>">
                        <td class="text-left"><?= htmlspecialchars($item['name']) ?></td>
                        <td class="text-center"><?= $qty_disp ?></td>
                        <td class="text-right"><?= $price_disp ?></td>
                        <td class="text-right"><?= $amount_disp ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td class="text-left">構造計算 基本料金（面積含む）</td>
                        <td class="text-center">1 式</td>
                        <td class="text-right">¥<?= number_format($data['base_price']) ?></td>
                        <td class="text-right">¥<?= number_format($data['base_price']) ?></td>
                    </tr>
                    <?php if ($data['grade_price'] > 0): ?>
                    <tr>
                        <td class="text-left">目標等級 加算</td>
                        <td class="text-center">1 式</td>
                        <td class="text-right">¥<?= number_format($data['grade_price']) ?></td>
                        <td class="text-right">¥<?= number_format($data['grade_price']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php $shape_extra = $data['total_price'] - $data['base_price'] - $data['grade_price']; if ($shape_extra > 0): ?>
                    <tr>
                        <td class="text-left">形状・その他 加算等</td>
                        <td class="text-center">1 式</td>
                        <td class="text-right">¥<?= number_format($shape_extra) ?></td>
                        <td class="text-right">¥<?= number_format($shape_extra) ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <tr>
                    <td colspan="4" style="border-top: 1px solid #000; padding:0;"></td>
                </tr>
                <tr class="subtotal-row">
                    <td colspan="2" style="border:none;"></td>
                    <td class="text-right">小計</td>
                    <td class="text-right">¥<?= number_format($data['total_price']) ?></td>
                </tr>
                <tr class="subtotal-row">
                    <td colspan="2" style="border:none;"></td>
                    <td class="text-right">消費税 (10%)</td>
                    <td class="text-right">¥<?= number_format($tax) ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="2" style="border:none;"></td>
                    <td class="text-right" style="border-bottom: 2px solid #000;">合計</td>
                    <td class="text-right" style="border-bottom: 2px solid #000;">¥<?= number_format($grand_total) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="disclaimer">
            <strong>【備考・スケジュールについて】</strong><br>
            ・本見積もりは意匠図を元に算出した概算です。詳細なモデル作成後に仕様変更等があった場合は変動する場合があります。<br>
            ・<span style="text-decoration: underline;">一次回答は必要図書がすべて揃ってから7～15営業日</span>、以降の質疑・修正対応は4営業日後となります。具体的な日程は図書受領後に決定いたします。<br>
            ・意匠図の大幅な変更等に伴う追加計算は、別途費用が発生する場合がございます。<br>
            ・業務の流れとして、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算がお取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。<br>
            ・<span style="font-weight: bold; color: #d32f2f;">私は設計者にはなりません。</span>
        </div>
    </div>
</body>
</html>
