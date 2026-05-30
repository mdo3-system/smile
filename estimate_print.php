<?php
// estimate_print.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'client']);

$project_id = $_GET['id'] ?? null;
if (!$project_id) { die("案件が指定されていません。"); }

// 案件情報と見積もり情報を取得
$stmt = $pdo->prepare("
    SELECT p.project_name, u.company_name, u.contact_name, e.* 
    FROM projects p 
    JOIN users u ON p.client_id = u.id 
    LEFT JOIN estimates e ON p.id = e.project_id
    WHERE p.id = :pid
");
$stmt->execute(['pid' => $project_id]);
$data = $stmt->fetch();

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
        .info-section { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .client-info { width: 50%; }
        .client-name { font-size: 20px; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; display: inline-block; }
        .project-desc { font-size: 14px; margin-top: 15px; }
        .company-info { width: 40%; font-size: 14px; text-align: right; }
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
                    <?php if(!empty($data['company_name'])): ?><?= htmlspecialchars($data['company_name'], ENT_QUOTES) ?><br><?php endif; ?>
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
                発行日: <?= date('Y年m月d日') ?><br><br>
                <div class="company-name">構造設計サポート</div>
                担当：菅原 弘貴<br>
                〒176-0012<br>
                東京都練馬区豊玉北5丁目<br>
                TEL: 070-8305-8480<br>
                Email: info@thanks.work
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
            ・<span style="text-decoration: underline;">一次回答は必要図書がすべて揃ってから7～15営業日</span>、以降の質疑・修正対応は〇日後となります。具体的な日程は図書受領後に決定いたします。<br>
            ・意匠図の大幅な変更等に伴う追加計算は、別途費用が発生する場合がございます。<br>
            ・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>
            ・<span style="font-weight: bold; color: #d32f2f;">本見積もりの設計に関して、私は設計者（建築士法に基づく設計者）にはならないことを明記いたします。</span>
        </div>
    </div>
</body>
</html>
