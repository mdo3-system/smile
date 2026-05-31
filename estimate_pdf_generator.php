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
    // 案件情報を取得
    $stmt = $pdo->prepare("
        SELECT p.project_name, p.billing_company_name, u.company_name, u.contact_name 
        FROM projects p 
        JOIN users u ON p.client_id = u.id 
        WHERE p.id = :pid
    ");
    $stmt->execute(['pid' => $project_id]);
    $project_data = $stmt->fetch();
    
    // 最新の見積もり情報を取得
    $stmtEst = $pdo->prepare("SELECT * FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
    $stmtEst->execute(['pid' => $project_id]);
    $estimate_data = $stmtEst->fetch();
    
    if ($project_data && $estimate_data) {
        $data = array_merge($project_data, $estimate_data);
    } else {
        $data = $project_data;
    }
    
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
    
    $billing_name = !empty($data['billing_company_name']) ? $data['billing_company_name'] : ($data['company_name'] ?? '');
    $company_name = htmlspecialchars($billing_name, ENT_QUOTES);
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
            $is_active = isset($item['is_active']) ? $item['is_active'] : ($amount > 0);
            
            $qty_disp = $is_active ? ($qty . ' ' . $unit) : '-';
            $price_disp = '¥' . number_format($price);
            $amount_disp = $is_active ? ('¥' . number_format($amount)) : '対象外';
            
            // 対象外の場合はグレーアウト
            $color = $is_active ? '#333333' : '#999999';
            
            $table_rows .= '
                <tr style="color: ' . $color . ';">
                    <td style="border-bottom: 1px solid #dddddd; text-align: left; padding: 8px;"> ' . $name . '</td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: center; padding: 8px; width: 15%;">' . $qty_disp . '</td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: right; padding: 8px; width: 20%;">' . $price_disp . '</td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: right; padding: 8px; width: 20%;">' . $amount_disp . '</td>
                </tr>
            ';
        }
    }
    
    // HTMLテンプレートの構築 (BillVector風)
    $html = '
    <div style="font-family: kozminproregular; color: #333333;">
        <h1 style="text-align: center; font-size: 24pt; font-weight: normal; letter-spacing: 8px; margin-top: 10px; margin-bottom: 30px;">御 見 積 書</h1>
        
        <table cellpadding="0" cellspacing="0" style="width: 100%; margin-bottom: 20px;">
            <tr>
                <td style="width: 50%; vertical-align: top; font-size: 11pt; line-height: 1.6;">
                    <div style="border-bottom: 1px solid #000000; padding-bottom: 5px; margin-bottom: 10px;">
                        <span style="font-size: 16pt;">' . ($company_name ? $company_name . '<br>' : '') . '</span>
                        <span style="font-size: 16pt;">' . $contact_name . ' 様</span>
                    </div>
                    <p style="font-size: 10pt; margin-top: 10px;">
                        下記の通り、御見積申し上げます。<br>
                        <br>
                        <strong>件名:</strong> ' . $project_name . ' 新築工事 設計等業務
                    </p>
                    
                    <div style="margin-top: 20px;">
                        <table cellpadding="0" cellspacing="0" style="width: 90%;">
                            <tr>
                                <td style="font-size: 11pt; border-bottom: 3px solid #000000; padding-bottom: 5px; width: 100%;">
                                    御見積金額 <span style="font-size: 18pt; font-weight: bold; margin-left: 10px;">¥' . number_format($grand_total) . '</span> <span style="font-size: 10pt;">(税込)</span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
                
                <td style="width: 40%; text-align: right;">
                    <div style="margin-bottom: 10px; text-align: right; font-size: 11pt;">発行日: ' . date('Y年m月d日') . '</div>
                    <div style="line-height: 1.6; font-size: 10pt; text-align: left; display: inline-block;">
                        <span style="font-size: 14pt; font-weight: bold;">株式会社住ま居る</span><br>
                        <span style="font-size: 12pt;">代表取締役 菅原 功樹</span><br>
                        〒350-2224<br>
                        埼玉県鶴ヶ島市町屋176-5<br>
                        TEL : 049-271-2350<br>
                        登録番号 : T6030001070141<br>
                        消費税 税率10%
                    </div>
                </td>
            </tr>
        </table>
        
        <table cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10pt;">
            <thead>
                <tr style="background-color: #f0f0f0; border-top: 2px solid #000000; border-bottom: 1px solid #000000;">
                    <th style="text-align: center; width: 45%; padding: 8px;">品目名</th>
                    <th style="text-align: center; width: 15%; padding: 8px;">数量</th>
                    <th style="text-align: center; width: 20%; padding: 8px;">単価</th>
                    <th style="text-align: center; width: 20%; padding: 8px;">金額</th>
                </tr>
            </thead>
            <tbody>
                ' . $table_rows . '
                <tr>
                    <td colspan="4" style="border-top: 1px solid #000000;"></td>
                </tr>
                <tr>
                    <td colspan="2" style="border: none;"></td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: right; padding: 8px;">小計</td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: right; padding: 8px;">¥' . number_format($total_price) . '</td>
                </tr>
                <tr>
                    <td colspan="2" style="border: none;"></td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: right; padding: 8px;">消費税 (10%)</td>
                    <td style="border-bottom: 1px solid #dddddd; text-align: right; padding: 8px;">¥' . number_format($tax) . '</td>
                </tr>
                <tr style="font-weight: bold;">
                    <td colspan="2" style="border: none;"></td>
                    <td style="border-bottom: 2px solid #000000; text-align: right; padding: 8px; font-size: 11pt;">合計</td>
                    <td style="border-bottom: 2px solid #000000; text-align: right; padding: 8px; font-size: 11pt;">¥' . number_format($grand_total) . '</td>
                </tr>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; font-size: 9pt; color: #333333; line-height: 1.6;">
            <strong>【備考・スケジュールについて】</strong><br>
            ・本見積もりは意匠図を元に算出した概算です。詳細なモデル作成後に仕様変更等があった場合は変動する場合があります。<br>
            ・<span style="text-decoration: underline;">一次回答は必要図書がすべて揃ってから7～15営業日</span>、以降の質疑・修正対応は〇日後となります。具体的な日程は図書受領後に決定いたします。<br>
            ・意匠図の大幅な変更等に伴う追加計算は、別途費用が発生する場合がございます。<br>
            ・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>
            ・<span style="font-weight: bold; color: #d32f2f;">本見積もりの設計に関して、私は設計者（建築士法に基づく設計者）にはならないことを明記いたします。</span>
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
