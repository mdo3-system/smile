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

if (!$data['total_price']) {
    die("この案件にはまだ保存された見積もりがありません。");
}

$tax = round($data['total_price'] * 0.1);
$grand_total = $data['total_price'] + $tax;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>御見積書 - <?= htmlspecialchars($data['project_name'], ENT_QUOTES) ?></title>
    <style>
        @page {
            size: A4;
            margin: 20mm;
        }
        body {
            font-family: "MS Mincho", "Noto Serif JP", serif;
            color: #333;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            font-size: 24px;
            letter-spacing: 5px;
            margin-bottom: 40px;
            border-bottom: 1px solid #000;
            padding-bottom: 10px;
        }
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .client-info {
            font-size: 18px;
        }
        .company-info {
            font-size: 14px;
            text-align: right;
        }
        .title-block {
            margin-bottom: 20px;
        }
        .project-name {
            font-size: 16px;
            margin-bottom: 10px;
        }
        .total-block {
            border-bottom: 2px solid #000;
            margin-bottom: 30px;
            padding-bottom: 5px;
        }
        .total-amount {
            font-size: 24px;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        th, td {
            border: 1px solid #000;
            padding: 10px;
            text-align: right;
        }
        th {
            background-color: #f0f0f0;
            text-align: center;
        }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        
        .print-btn {
            display: block;
            width: 200px;
            margin: 20px auto;
            padding: 10px;
            background: #0056b3;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            font-size: 16px;
        }
        @media print {
            .print-btn { display: none; }
            body { background: none; }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">このページを印刷・PDF保存</button>

    <div class="container">
        <div class="header">御見積書</div>
        
        <div class="info-section">
            <div class="client-info">
                <?= htmlspecialchars($data['company_name'], ENT_QUOTES) ?><br>
                <?= htmlspecialchars($data['contact_name'], ENT_QUOTES) ?> 様
            </div>
            <div class="company-info">
                発行日: <?= date('Y年m月d日') ?><br><br>
                <strong>構造設計サポート</strong><br>
                〒000-0000<br>
                東京都○○区○○ 1-2-3<br>
                TEL: 03-0000-0000
            </div>
        </div>

        <div class="title-block">
            <div class="project-name"><strong>件名:</strong> <?= htmlspecialchars($data['project_name'], ENT_QUOTES) ?> 新築工事 構造計算</div>
            <div>下記の通り、御見積申し上げます。</div>
        </div>

        <div class="total-block">
            御見積合計金額: <span class="total-amount">¥<?= number_format($grand_total) ?></span> (税込)
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:50%;">摘要</th>
                    <th style="width:15%;">数量</th>
                    <th style="width:35%;">金額</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-left">構造計算 基本料金（面積含む）</td>
                    <td class="text-center">1 式</td>
                    <td>¥<?= number_format($data['base_price']) ?></td>
                </tr>
                <?php if ($data['grade_price'] > 0): ?>
                <tr>
                    <td class="text-left">目標等級 加算</td>
                    <td class="text-center">1 式</td>
                    <td>¥<?= number_format($data['grade_price']) ?></td>
                </tr>
                <?php endif; ?>
                <?php
                    // 形状等の加算額を逆算
                    $shape_extra = $data['total_price'] - $data['base_price'] - $data['grade_price'];
                ?>
                <?php if ($shape_extra > 0): ?>
                <tr>
                    <td class="text-left">形状・その他 加算等</td>
                    <td class="text-center">1 式</td>
                    <td>¥<?= number_format($shape_extra) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td colspan="2" class="text-center"><strong>小計</strong></td>
                    <td>¥<?= number_format($data['total_price']) ?></td>
                </tr>
                <tr>
                    <td colspan="2" class="text-center">消費税 (10%)</td>
                    <td>¥<?= number_format($tax) ?></td>
                </tr>
                <tr>
                    <td colspan="2" class="text-center"><strong>合計</strong></td>
                    <td><strong>¥<?= number_format($grand_total) ?></strong></td>
                </tr>
            </tbody>
        </table>

        <div style="font-size:12px; color:#555;">
            【備考】<br>
            ・本見積もりは概算です。詳細なモデル作成後に変動する場合があります。<br>
            ・意匠図の変更等に伴う追加計算は別途費用が発生いたします。
        </div>
    </div>
</body>
</html>
