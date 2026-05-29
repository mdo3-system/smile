<?php
// estimate_pdf_generator.php
require_once __DIR__ . '/vendor/autoload.php';

/**
 * 案件の見積もり情報から見積書PDFを一時的に生成し、そのローカルパスを返す
 * @param int $project_id 案件ID
 * @param PDO $pdo データベース接続インスタンス
 * @return string 生成されたPDFファイルの一時ローカル絶対パス
 */
function generate_estimate_pdf($project_id, $pdo) {
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
        throw new Exception("案件情報の取得に失敗しました。");
    }
    
    if (empty($data['total_price'])) {
        throw new Exception("この案件にはまだ保存された見積もりがありません。");
    }
    
    $total_price = intval($data['total_price']);
    $tax = round($total_price * 0.1);
    $grand_total = $total_price + $tax;
    
    $items = [];
    if (!empty($data['note'])) {
        $items = json_decode($data['note'], true);
    }
    
    // TCPDFの初期化
    // P: 縦、mm: ミリメートル、A4サイズ
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // ドキュメント情報のメタデータ設定
    $pdf->SetCreator('構造設計サポート・ポータル');
    $pdf->SetAuthor('構造設計サポート');
    $pdf->SetTitle('御見積書_' . $data['project_name']);
    $pdf->SetSubject('御見積書');
    
    // ヘッダーとフッターを表示しない設定
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // 左右と上の余白を設定 (左右 15mm, 上 20mm)
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetAutoPageBreak(true, 15);
    
    // ページを追加
    $pdf->AddPage();
    
    // 日本語フォント (小塚ゴシック/明朝) の設定
    // kozminproregular = 小塚明朝 Regular
    $pdf->SetFont('kozminproregular', '', 10);
    
    $company_name = htmlspecialchars($data['company_name'] ?? '', ENT_QUOTES);
    $contact_name = htmlspecialchars($data['contact_name'] ?? '', ENT_QUOTES);
    $project_name = htmlspecialchars($data['project_name'] ?? '', ENT_QUOTES);
    $date_str = date('Y年m月d日');
    
    // 内訳テーブルの行を作成
    $table_rows = '';
    if (!empty($items) && is_array($items)) {
        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'] ?? '', ENT_QUOTES);
            $qty = htmlspecialchars($item['qty'] ?? '1', ENT_QUOTES);
            $unit = htmlspecialchars($item['unit'] ?? '式', ENT_QUOTES);
            $price = intval($item['price'] ?? 0);
            $amount = intval($item['amount'] ?? 0);
            
            $qty_disp = $qty . ' ' . $unit;
            
            $table_rows .= '
                <tr>
                    <td style="border: 1px solid #000000; text-align: left; padding: 6px;"> ' . $name . '</td>
                    <td style="border: 1px solid #000000; text-align: center; padding: 6px; width: 15%;">' . $qty_disp . '</td>
                    <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($price) . '</td>
                    <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($amount) . '</td>
                </tr>
            ';
        }
    } else {
        // フォールバック（既存の古い見積もりデータ用）
        $table_rows .= '
            <tr>
                <td style="border: 1px solid #000000; text-align: left; padding: 6px;"> 構造計算 基本料金（面積含む）</td>
                <td style="border: 1px solid #000000; text-align: center; padding: 6px; width: 15%;">1 式</td>
                <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($data['base_price']) . '</td>
                <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($data['base_price']) . '</td>
            </tr>
        ';
        if ($data['grade_price'] > 0) {
            $table_rows .= '
                <tr>
                    <td style="border: 1px solid #000000; text-align: left; padding: 6px;"> 目標等級 加算</td>
                    <td style="border: 1px solid #000000; text-align: center; padding: 6px; width: 15%;">1 式</td>
                    <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($data['grade_price']) . '</td>
                    <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($data['grade_price']) . '</td>
                </tr>
            ';
        }
        $shape_extra = $total_price - $data['base_price'] - $data['grade_price'];
        if ($shape_extra > 0) {
            $table_rows .= '
                <tr>
                    <td style="border: 1px solid #000000; text-align: left; padding: 6px;"> 形状・その他 加算等</td>
                    <td style="border: 1px solid #000000; text-align: center; padding: 6px; width: 15%;">1 式</td>
                    <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($shape_extra) . '</td>
                    <td style="border: 1px solid #000000; text-align: right; padding: 6px; width: 20%;">¥' . number_format($shape_extra) . '</td>
                </tr>
            ';
        }
    }
    
    // HTMLテンプレートの構築
    $html = '
    <div style="font-family: kozminproregular; color: #333333;">
        <table cellpadding="0" cellspacing="0" style="width: 100%; border: none;">
            <tr>
                <td style="width: 50%;"></td>
                <td style="width: 50%; text-align: right; font-size: 9pt; color: #555555;">発行日: ' . $date_str . '</td>
            </tr>
        </table>
        
        <h1 style="text-align: center; font-size: 22pt; font-weight: normal; letter-spacing: 6px; margin-top: 10px; margin-bottom: 25px; border-bottom: 1px solid #000000; padding-bottom: 10px;">御見積書</h1>
        
        <table cellpadding="0" cellspacing="0" style="width: 100%; margin-top: 15px; margin-bottom: 20px;">
            <tr>
                <td style="width: 55%; vertical-align: top; font-size: 12pt; line-height: 1.6;">
                    <span style="font-size: 14pt; font-weight: bold; border-bottom: 1px solid #000000; display: inline-block; padding-bottom: 2px;">
                        ' . ($company_name ? $company_name . '<br>' : '') . '
                        ' . $contact_name . ' 様
                    </span>
                    <p style="font-size: 9.5pt; margin-top: 12px; color: #555555;">
                        下記の通り、御見積申し上げます。
                    </p>
                </td>
                <td style="width: 45%; text-align: right; font-size: 9.5pt; line-height: 1.6; vertical-align: top;">
                    <strong>構造設計サポート</strong><br>
                    担当：菅原 弘貴<br>
                    〒176-0012<br>
                    東京都練馬区豊玉北5丁目<br>
                    TEL: 070-8305-8480<br>
                    Email: info@thanks.work
                </td>
            </tr>
        </table>
        
        <div style="margin-top: 25px; margin-bottom: 20px; border-bottom: 2px solid #000000; padding-bottom: 8px;">
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                    <td style="width: 55%; font-size: 11pt; font-weight: bold; vertical-align: bottom;">件名: ' . $project_name . ' 新築工事 設計等業務</td>
                    <td style="width: 45%; text-align: right; font-size: 14pt; font-weight: bold; vertical-align: bottom;">御見積合計金額: ¥' . number_format($grand_total) . ' <span style="font-size: 10pt; font-weight: normal;">(税込)</span></td>
                </tr>
            </table>
        </div>
        
        <table cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 9.5pt;">
            <thead>
                <tr style="background-color: #f2f2f2; font-weight: bold;">
                    <th style="border: 1px solid #000000; text-align: center; width: 45%;">摘要・内訳項目</th>
                    <th style="border: 1px solid #000000; text-align: center; width: 15%;">数量</th>
                    <th style="border: 1px solid #000000; text-align: center; width: 20%;">単価</th>
                    <th style="border: 1px solid #000000; text-align: center; width: 20%;">金額</th>
                </tr>
            </thead>
            <tbody>
                ' . $table_rows . '
                <tr>
                    <td colspan="3" style="border: 1px solid #000000; text-align: center; font-weight: bold; background-color: #fafafa;">小計 (税抜)</td>
                    <td style="border: 1px solid #000000; text-align: right; font-weight: bold; background-color: #fafafa;">¥' . number_format($total_price) . '</td>
                </tr>
                <tr>
                    <td colspan="3" style="border: 1px solid #000000; text-align: center; background-color: #fafafa;">消費税 (10%)</td>
                    <td style="border: 1px solid #000000; text-align: right; background-color: #fafafa;">¥' . number_format($tax) . '</td>
                </tr>
                <tr style="background-color: #f5f5f5; font-weight: bold;">
                    <td colspan="3" style="border: 1px solid #000000; text-align: center; font-size: 10.5pt;">合計 (税込)</td>
                    <td style="border: 1px solid #000000; text-align: right; color: #d32f2f; font-size: 10.5pt;">¥' . number_format($grand_total) . '</td>
                </tr>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; font-size: 8.5pt; color: #555555; line-height: 1.7; border: 1px solid #cccccc; padding: 12px; background-color: #fafafa; border-radius: 4px;">
            <strong>【備考】</strong><br>
            ・本見積もりは意匠図を元に算出した概算です。詳細なモデル作成後に仕様変更等があった場合は変動する場合があります。<br>
            ・ご依頼の際は、意匠図CADデータ（JWW/DXF等）、確認申請書2面〜5面、地盤調査報告書等をご提供ください。<br>
            ・意匠図の大幅な変更等に伴う追加計算は、別途費用が発生する場合がございます。<br>
            ・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。
        </div>
    </div>
    ';
    
    // HTMLを描画
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // 保存先ディレクトリの設定
    $temp_dir = __DIR__ . '/uploads/temp';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    $temp_file_path = $temp_dir . '/estimate_' . $project_id . '_' . time() . '.pdf';
    
    // ファイルに保存
    $pdf->Output($temp_file_path, 'F');
    
    return $temp_file_path;
}
