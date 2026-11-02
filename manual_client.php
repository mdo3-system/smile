<?php
// manual_client.php
require_once 'auth.php';
require_once 'functions.php';

// 外部Webサイト公開のため、ログイン制限を解除
// check_auth(['admin', 'client', 'accountant']);

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操作マニュアル（依頼主様向け） | 木造住宅設計サポート・ポータル</title>
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-light: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
        }
        body { 
            font-family: 'Helvetica Neue', Arial, 'Noto Sans JP', sans-serif; 
            background: var(--bg-page); 
            margin: 0; 
            padding: 0; 
            color: var(--text-dark); 
            line-height: 1.7;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 36px 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #ffffff;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 15px;
            color: #e0e7ff;
            font-weight: 500;
        }
        .header .ver-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 8px;
            font-weight: bold;
        }
        .container {
            max-width: 1060px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .nav-back {
            margin-bottom: 24px;
        }
        .nav-back a {
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: white;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.2s;
        }
        .nav-back a:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .card {
            background: var(--bg-card);
            border-radius: 14px;
            padding: 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            margin-bottom: 35px;
            border: 1px solid var(--border-color);
            border-top: 5px solid var(--primary-light);
        }
        .card-purple {
            border-top-color: var(--purple);
        }
        .card-green {
            border-top-color: var(--success);
        }
        .card-orange {
            border-top-color: var(--warning);
        }
        .card-red {
            border-top-color: var(--danger);
        }
        h2 {
            margin-top: 0;
            font-size: 20px;
            color: var(--primary);
            border-bottom: 2px solid #edf2f7;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        h3 {
            font-size: 16px;
            color: #1e293b;
            margin-top: 25px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        p {
            font-size: 14px;
            color: #334155;
            margin-bottom: 16px;
        }
        .timeline {
            position: relative;
            margin: 25px 0;
            padding-left: 24px;
            border-left: 3px solid #cbd5e1;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 28px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -32px;
            top: 3px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #3b82f6;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #3b82f6;
        }
        .timeline-item.active::before {
            background: #10b981;
            box-shadow: 0 0 0 2px #10b981;
        }
        .timeline-title {
            font-weight: bold;
            font-size: 15px;
            color: #1e3a8a;
            margin-bottom: 6px;
        }
        .timeline-desc {
            font-size: 13px;
            color: #475569;
        }
        .img-container {
            margin: 22px 0;
            text-align: center;
            background: #f8fafc;
            padding: 18px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .img-container img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
            border: 1px solid #cbd5e1;
        }
        .img-caption {
            font-size: 12px;
            color: #475569;
            margin-top: 12px;
            font-weight: bold;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            white-space: nowrap;
        }
        .badge-green { background-color: #10b981; }
        .badge-blue { background-color: #3b82f6; }
        .badge-purple { background-color: #8b5cf6; }
        .badge-gray { background-color: #64748b; }
        .badge-red { background-color: #ef4444; }
        
        .box-info {
            background: #eff6ff;
            border-left: 5px solid #3b82f6;
            padding: 16px 20px;
            border-radius: 0 10px 10px 0;
            margin: 20px 0;
            font-size: 13px;
            color: #1e3a8a;
            line-height: 1.6;
        }
        .box-warning {
            background: #fffbeb;
            border-left: 5px solid #f59e0b;
            padding: 16px 20px;
            border-radius: 0 10px 10px 0;
            margin: 20px 0;
            font-size: 13px;
            color: #92400e;
            line-height: 1.6;
        }
        .box-danger {
            background: #fef2f2;
            border-left: 5px solid #ef4444;
            padding: 16px 20px;
            border-radius: 0 10px 10px 0;
            margin: 20px 0;
            font-size: 13px;
            color: #991b1b;
            line-height: 1.6;
        }
        .box-purple {
            background: #f5f3ff;
            border-left: 5px solid #8b5cf6;
            padding: 16px 20px;
            border-radius: 0 10px 10px 0;
            margin: 20px 0;
            font-size: 13px;
            color: #5b21b6;
            line-height: 1.6;
        }
        
        .grid-desc {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            margin-top: 18px;
        }
        @media(max-width:768px) {
            .grid-desc {
                grid-template-columns: 1fr;
            }
        }
        .desc-col {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .desc-col h4 {
            margin-top: 0;
            font-size: 14px;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .desc-col ul {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            color: #334155;
        }
        .desc-col li {
            margin-bottom: 10px;
        }

        /* 比較テーブル用デザイン */
        .compare-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 13px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            border: 1px solid #e2e8f0;
        }
        .compare-table th, .compare-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .compare-table th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: bold;
            text-align: left;
            white-space: nowrap;
        }
        .compare-table tr:last-child td {
            border-bottom: none;
        }
        .btn-mock {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            user-select: none;
        }
        .btn-mock-purple {
            background: #8b5cf6;
        }
        .step-flow {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .step-box {
            flex: 1;
            min-width: 200px;
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            position: relative;
        }
        .step-box.active {
            border-color: #8b5cf6;
            background: #faf5ff;
        }
        .step-num {
            display: inline-block;
            background: #8b5cf6;
            color: white;
            width: 24px;
            height: 24px;
            line-height: 24px;
            text-align: center;
            border-radius: 50%;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .step-title {
            font-weight: bold;
            font-size: 13px;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .step-text {
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>📖 木造住宅設計サポート・ポータル 操作マニュアル</h1>
        <p>〜 依頼主（設計者・施工業者）様向け 〜</p>
        <div class="ver-badge">最新稼働バージョン：<?= defined('SYSTEM_VERSION') ? SYSTEM_VERSION : 'v1.6.74' ?></div>
    </div>

    <div class="container">
        
        <div class="nav-back">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="index.php">➔ ポータルダッシュボードに戻る</a>
            <?php else: ?>
                <a href="https://thanks.work">➔ WEBサイトに戻る</a>
            <?php endif; ?>
        </div>

        <!-- ========================================================
             【最重要セクション】アカウント追加・招待リンクの使い分け
        ======================================================== -->
        <div class="card card-purple" id="section-invitation">
            <h2>👥 アカウント追加と招待リンクの種類（用途と権限の違い）</h2>
            <p>
                本システムでは、関係者の種類に応じて<strong>「① 社内スタッフ招待」</strong>と<strong>「② 特定案件限定招待（施工業者・設計者）」</strong>の2種類の招待リンクを用意しています。<br>
                <strong>用途を間違えて送付すると、機密情報や他物件の情報漏洩につながる恐れがありますので、必ず以下の違いをご確認の上で正しく使い分けてください。</strong>
            </p>

            <table class="compare-table">
                <thead>
                    <tr>
                        <th style="width:20%;">招待の種類</th>
                        <th style="width:25%;">発行場所（画面）</th>
                        <th style="width:25%;">対象者・おすすめの用途</th>
                        <th style="width:30%;">閲覧・操作できる範囲（権限）</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#faf5ff;">
                        <td>
                            <strong style="color:#7c3aed; font-size:14px;">① 社内スタッフ招待</strong><br>
                            <span class="badge badge-purple" style="margin-top:4px;">全案件・会社共有</span>
                        </td>
                        <td>
                            <strong>案件一覧画面（ダッシュボード）</strong><br>
                            右上のボタン：<br>
                            <span class="btn-mock btn-mock-purple" style="margin-top:5px;">👥 社内スタッフを招待</span>
                        </td>
                        <td>
                            <strong>自社の社員・社内メンバー限定</strong><br>
                            <small style="color:#64748b;">（同じ会社内で設計案件を共同管理する同僚・上司・アシスタント等）</small>
                        </td>
                        <td>
                            <strong style="color:#7c3aed;">自社の「すべての案件」を閲覧・操作可能</strong>
                            <ul style="margin:6px 0 0 16px; padding:0; font-size:12px; color:#475569;">
                                <li>現在見積中の案件</li>
                                <li>設計進行中のすべての案件</li>
                                <li>過去の完了案件DB（すべての図書）</li>
                                <li>ご請求書・お見積書・金額の確認</li>
                            </ul>
                        </td>
                    </tr>
                    <tr style="background:#f0fdf4;">
                        <td>
                            <strong style="color:#059669; font-size:14px;">② 施工業者・設計者招待</strong><br>
                            <span class="badge badge-green" style="margin-top:4px;">1案件限定アクセス</span>
                        </td>
                        <td>
                            <strong>各物件の詳細画面</strong><br>
                            画面上部のボタン：<br>
                            <span class="btn-mock btn-mock-purple" style="margin-top:5px; font-size:11px;">🔗 施工業者・設計者を招待</span>
                        </td>
                        <td>
                            <strong>その物件に関係する外部協力者</strong><br>
                            <small style="color:#64748b;">（該当物件の元請工務店、現場監督、外部設計士、確認申請提出者など）</small>
                        </td>
                        <td>
                            <strong style="color:#059669;">招待された「その1案件のみ」に完全限定</strong>
                            <ul style="margin:6px 0 0 16px; padding:0; font-size:12px; color:#475569;">
                                <li>該当物件のチャット（質疑・連絡）</li>
                                <li>該当物件の成果物（計算書・図面）受領</li>
                                <li>該当物件の図書アップロード</li>
                                <li><strong style="color:#dc2626;">※他のお客様の物件や自社の他案件、見積一覧・金額は一切見えません。</strong></li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="box-danger">
                <strong>🚨 【超重要】情報漏洩防止のための注意ルール</strong><br>
                外部の施工業者様や現場監督様、外注の設計事務所様へは、<strong>絶対に「① 社内スタッフ招待」のURLを送らないでください。</strong><br>
                誤って社内スタッフ用リンクを送ると、その外部業者様に<strong>自社が進行している他の全物件や見積金額・ご請求情報まで全て見えてしまいます。</strong><br>
                特定の物件の連絡や図面共有を行いたい場合は、必ず<strong>その物件の詳細画面の上部にある「🔗 施工業者・設計者を招待」ボタンからURLをコピーして送付してください。</strong>
            </div>

            <h3>📨 招待された方（新規登録者）の簡単3ステップ</h3>
            <p>招待リンクを受け取った方は、パスワード設定不要で約1分で安全に登録・ログインできます。</p>

            <div class="step-flow">
                <div class="step-box">
                    <span class="step-num">1</span>
                    <div class="step-title">招待リンクをクリック</div>
                    <div class="step-text">送られてきたURL（register.php）をブラウザで開きます。会社名が自動入力された登録フォームが表示されます。</div>
                </div>
                <div class="step-box">
                    <span class="step-num">2</span>
                    <div class="step-title">氏名・メールアドレスを入力</div>
                    <div class="step-text">担当者名・メールアドレス・電話番号を入力し、「登録してログインリンクを発行」ボタンを押します。</div>
                </div>
                <div class="step-box active">
                    <span class="step-num">3</span>
                    <div class="step-title">メールのリンクから即座にログイン</div>
                    <div class="step-text">入力したアドレスに登録完了メールが届きます。本文中のURLをクリックするだけで即座にポータルへログイン完了します。</div>
                </div>
            </div>

            <div class="box-info">
                💡 **ログインリンクの有効期限**: 送信されたログインリンク（マジックリンク）は30分間有効です。万が一時効になった場合でも、ログイン画面（`login.php`）からメールアドレスを送信すれば、いつでも新しいログインリンクを何度でも再発行できます。
            </div>
        </div>

        <!-- ========================================================
             1. 業務全体の流れ（タイムライン）
        ======================================================== -->
        <div class="card">
            <h2>📅 見積依頼から納品までの全体の流れ</h2>
            <p>本システムでは、見積のご依頼から設計図書の納品、質疑応答（補正対応）までを以下のステップに沿って進行します。</p>
            
            <div class="timeline">
                <div class="timeline-item">
                    <div class="timeline-title">ステップ1: 見積依頼の作成</div>
                    <div class="timeline-desc">ダッシュボードの「➕ 新規見積・計算依頼」から物件情報（面積、階数、ご依頼内容等）を入力し、見積用PDF図面（平面図・立面図等）をUPして見積もりを依頼します。</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">ステップ2: 初期見積書の発行・確認</div>
                    <div class="timeline-desc">サポート担当者より初期見積書が提示されます。メール通知、または案件詳細画面の「御見積書ダウンロード」から確認できます。</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">ステップ3: 正式なご依頼 ＆ 意匠CADデータのUP</div>
                    <div class="timeline-desc">見積内容に問題がなければ、詳細画面の「設計依頼データの送付」パネルから、意匠図CADデータ（アーキトレンド / JWW / DXF / DWG / ZIP）や地盤調査報告書などをUPして正式にご依頼ください。※意匠図面スロットへのPDF提出は不可（CAD形式限定）です。</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">ステップ4: サポート担当による着手 ＆ 一次回答の提示</div>
                    <div class="timeline-desc">スケジュールが確定し、予定日に沿って「一次回答（計算書・図面初回提示）」がUPされます。中央の「成果物」エリアからダウンロードして確認してください。</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">ステップ5: 一次回答へのチェックバック (CB) ＆ 中間金（50％）のご入金</div>
                    <div class="timeline-desc">提示された一次回答に意匠上の変更や要望がある場合は、右側の「チャット」でチェックバック資料をUPします。また、一次請求書（50%分）が発行されますのでお振込み完了後、ダッシュボード基本情報エリアの<b>「💵 中間金（50％）を入金しました」</b>ボタンを押してください。</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">ステップ6: 構造図UP・申請図書一式UP ＆ 補正対応</div>
                    <div class="timeline-desc">ご入金・CB確認後、サポートが構造図を作図・UPします。確認後、申請用の一式（計算書・安全証明書等）が提示されます。審査機関からの質疑はチャット等で連携し、補正対応を行います。</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">ステップ7: 審査合格・最終ご清算 ＆ 業務完了</div>
                    <div class="timeline-desc">確認申請の審査が合格（完了）しましたら、最終請求書（残り50%）を発行します。ご入金完了後、ダッシュボード基本情報エリアの<b>「💮 残金お振込み ＆ 審査完了にする（審査合格）」</b>ボタンを押して完了登録を行ってください。（※残金が発生しない、または事前一括入金済みの場合は、自動的に<b>「💮 審査完了にする（審査合格）」</b>ボタンに切り替わります。）</div>
                </div>
            </div>
            
            <div class="box-info">
                💡 **営業日計算のルール**: スケジュールは「水曜日・日曜日」を除外した営業日で自動計算されます。一次回答CBのご提出や着手金のご入金が遅れると、以降の各予定日も自動的にその日数分順延されますのでご注意ください。
            </div>
        </div>

        <!-- ========================================================
             2. ダッシュボード（案件一覧画面）の見方
        ======================================================== -->
        <div class="card card-green">
            <h2>📱 案件一覧画面（ダッシュボード）の見方</h2>
            <p>ログイン後に最初に表示される「案件一覧画面」では、現在動いているすべての物件の進行状況が一覧で並んでいます。</p>
            
            <div class="img-container">
                <img src="assets/images/manual/dashboard.png" alt="ダッシュボード一覧画面のスクリーンショット">
                <div class="img-caption">【図1】案件一覧画面（最新レイアウト：社内スタッフ招待ボタン・ステータスバッジ・工程予定日表示）</div>
            </div>

            <div class="grid-desc">
                <div class="desc-col">
                    <h4>💡 カードに表示されている内容</h4>
                    <ul>
                        <li><strong>ステータスバッジ（左上）</strong>: 現在の物件ステータス（「見積依頼」「一次回答準備中」「申請図書作成中」「審査・待機」など）です。</li>
                        <li><strong>主導権バッジ（右上）</strong>:
                            <br><span class="badge badge-green">🟩 あなたのターン</span> : あなたからの返信やデータ提出、入金をお待ちしている状態です。
                            <br><span class="badge badge-blue">🟦 相手のターン</span> : サポート担当が作図や計算、回答を準備している状態です。
                        </li>
                        <li><strong>📅 予定日</strong>: <u>現在進行している工程</u>の目標・予定日です。一次回答時だけでなく、構造図UPや補正対応など、その時々の現在地の予定日が表示されます。</li>
                        <li><strong>現在の工程</strong>: 現在どのフェーズにあるかが具体的に表示されます。</li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>📝 すること・できること</h4>
                    <ul>
                        <li><strong style="color:#7c3aed;">👥 社内スタッフを招待</strong>: 画面右上の紫ボタンをクリックすると招待リンクがコピーされ、社内の別メンバーを自社グループに自由に追加できます。</li>
                        <li><strong>➕ 新規見積・計算依頼</strong>: 右上のボタンから新しい案件をいつでも登録できます。</li>
                        <li><strong>詳細を開く</strong>: 各物件の「詳細を開く」ボタンから、チャットの送信、図書のダウンロード、依頼データのUPを行う3カラム詳細画面へ進みます。</li>
                        <li><strong>📂 完了案件DB</strong>: 審査がすべて完了した過去の物件は自動で非表示になり、上部の「📂 完了案件DB」リンクから過去の履歴としていつでも図書の再ダウンロードが可能です。</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ========================================================
             3. 案件詳細画面の説明
        ======================================================== -->
        <div class="card card-orange">
            <h2>📄 案件詳細画面の使い方と各機能（3カラム最新仕様）</h2>
            <p>案件詳細画面は、左・中央・右の3つのカラムに分かれており、必要な操作が直感的に行えるよう設計されています。</p>
            
            <div class="img-container">
                <img src="assets/images/manual/project_detail.png" alt="案件詳細画面のスクリーンショット">
                <div class="img-caption">【図2】案件詳細画面（左:基本情報・請求、中央:スケジュール工程表＆成果物、右:チャット、上部:関係者招待ボタン）</div>
            </div>

            <div class="grid-desc">
                <div class="desc-col">
                    <h4>👈 左カラム：基本情報・ご請求状況 ＆ ご入金報告</h4>
                    <ul>
                        <li><strong>基本情報・ご依頼内容</strong>: 物件名や担当者、要求した計算（許容応力度・外皮など）が表示されます。鉛筆アイコンから自社の案件名や連絡先情報をいつでも編集できます。</li>
                        <li><strong>御見積書・ご請求書</strong>: 発行された見積書（PDF）や一次請求書（50%）、最終請求書がここに提示され、ダウンロード可能です。</li>
                        <li><strong>正式ご依頼（設計依頼データ送付）</strong>: 見積確認後、ここに表示されるアップローダーから意匠CADや地盤データをUPすることで、正式な設計業務が開始されます。</li>
                        <li><strong>💵 中間金（50％）入金後の報告ボタン</strong>:
                            一次回答提示後に中間金（50%）のお振込みが完了しましたら、必ず<b>「💵 中間金（50％）を入金しました」</b>ボタンを押してください。経理担当へ入金報告メールが自動送信され、工程スケジュール上の「中間金（50％）のご入金」の実績日（本日付）が自動登録されます。
                        </li>
                        <li><strong>💮 残金ご精算・審査完了報告ボタン</strong>:
                            確認申請合格後、残金をお振込みいただき<b>「💮 残金お振込み ＆ 審査完了にする（審査合格）」</b>ボタンを押すことで、正式に「完了」ステータスとなり、すべての正式成果物（安全証明書原本等）のダウンロードが可能になります。
                            （※残金が0円、または事前一括入金済みの場合は、自動的に<b>「💮 審査完了にする（審査合格）」</b>ボタンに切り替わります。）
                        </li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>👉 中央カラム：スケジュール工程表 ＆ 成果物受領</h4>
                    <ul>
                        <li><strong>📅 進捗スケジュール工程表</strong>: 
                            各工程の目標予定日が表示され、現在進行中の工程が <span style="border: 1px solid #ef4444; background: #fee2e2; padding: 2px 5px; border-radius:3px; font-weight:bold; color:#ef4444; font-size:10px;">👉 現在地</span> として強調表示されます。
                        </li>
                        <li><strong>★【新機能】ご希望日入力 ＆ 納期短縮確認機能</strong>:
                            未完了の各工程について、依頼主様側から「ご希望日」を入力して保存できます。
                            保存時には確認モーダルが開き、以下の2つから選択可能です：
                            <ul style="margin:4px 0 0 16px;">
                                <li><strong>⚡ 追加料金発生しても依頼する（納期短縮依頼・追加見積依頼）</strong>：@10%/日の短縮を前提として管理者へ追加見積を依頼します。</li>
                                <li><strong>📅 あくまでも希望日とする（追加料金なし）</strong>：日程保証なしの通常善処希望として登録します。</li>
                            </ul>
                        </li>
                        <li><strong>成果物管理（提出・納品図書）</strong>: 
                            サポートが納品した「構造計算書」「安全証明書」「構造図一式」などは、このエリアに格納されます。最新バージョン（V1, V2...）が自動で管理され、過去版もプルダウンからいつでもダウンロードできます。
                        </li>
                        <li><strong>依頼主アップロード図書スロット</strong>: 
                            提出した建築CAD図面などがカテゴリ別に整理されています。差し替え理由を入力して新しい図面をUPすることも可能です。
                        </li>
                    </ul>
                </div>
            </div>

            <h3 style="border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; margin-top:20px;">💬 右カラム：チャットエリア（質疑応答・連絡） ＆ 上部ヘッダー機能</h3>
            <p>サポート担当との全ての連絡は、右側のチャット（LINEスタイル）で行います。</p>
            <div class="grid-desc" style="margin-top:5px;">
                <div class="desc-col">
                    <h4>🙋‍♀️ サポートからの連絡と回答</h4>
                    <ul>
                        <li>見積発行時や一次回答提示時、あるいは設計上の疑義（梁の配置や高さの整合について）が生じた場合、チャットに通知やメッセージが自動・手動で届きます。</li>
                        <li>通知メールも届くため、ポータルを常に開いていなくても見逃す心配はありません。</li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>📁 ファイルの添付と外部関係者の招待</h4>
                    <ul>
                        <li>チャット入力欄の「📎 添付」ボタンから、画像やPDF、CADデータなどを添付してメッセージを送ることができます。一時回答へのチェックバック（朱書き等）はチャットへご投稿ください。</li>
                        <li><strong>🔗 施工業者・設計者を招待</strong>: 画面上部にあるこのボタンからリンクをコピーして現場監督や施工業者に送ることで、この案件限定でチャットや図面受領に招き入れることができます。</li>
                    </ul>
                </div>
            </div>
            
            <div class="box-warning" style="margin-top:20px;">
                ⚠️ **ご注意**: 構造検討中に図面間の不整合（平面図と立面図で窓の位置や高さがズレている等）が見つかった場合、チャットにて確認のご連絡を差し上げます。ご回答があるまで設計作業が一時的に止まりますので、早めのご確認・ご返信をいただけますと幸いです。
            </div>
        </div>

        <!-- ========================================================
             4. メール通知機能について
        ======================================================== -->
        <div class="card">
            <h2>✉️ システムからの自動メール通知について</h2>
            <p>本システムでは、関係者間での迅速な連携や進捗・図書の確認漏れを防ぐため、以下のような「対応担当者（ボール）が切り替わるタイミング」や進捗状況に応じて、自動でメール通知が送信されます。</p>
            
            <div class="grid-desc">
                <div class="desc-col">
                    <h4>💡 通知が送信されるタイミング一覧</h4>
                    <ul>
                        <li><strong>担当権（ボール）切り替わり時・進捗更新時</strong>:
                            <ul>
                                <li><strong>初期見積書の提示時</strong> (ボール: 管理者 ➔ 依頼主)</li>
                                <li><strong>設計・構造計算の着手時</strong> (ボール: 管理者 ➔ 管理者作業中)</li>
                                <li><strong>一次回答（構造計算・初回図面）の提示時</strong> (ボール: 管理者 ➔ 依頼主)</li>
                                <li><strong>構造図のアップロード時</strong> (ボール: 管理者 ➔ 依頼主)</li>
                                <li><strong>申請図書一式（最終成果物）のアップロード時</strong> (ボール: 管理者 ➔ 審査待機)</li>
                                <li><strong>中間金（50％）のご入金報告時</strong> (ボール: 依頼主 ➔ 管理者)</li>
                                <li><strong>審査完了（審査合格）の登録時</strong> (ボール: 依頼主 ➔ 完了)</li>
                                <li><strong>スケジュールご希望日・短縮依頼の登録時</strong> (進捗の共有)</li>
                            </ul>
                        </li>
                        <li><strong>新着チャット受信時</strong>: サポート担当者からメッセージが届いた際、企業の通知設定が有効なスタッフ全員に新着通知メールが配信されます。</li>
                        <li><strong>審査状況リマインダー</strong>: 「補正対応（図面一式UP）」の工程が完了してから3週間（21日）が経過し、まだ「完了」登録がなされていない案件について、審査の合格状況を確認するメールが自動送信されます。</li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>⚙️ 事前にご承知おきいただきたいこと</h4>
                    <ul>
                        <li><strong>社内スタッフ全員への配信</strong>: 連絡の確認漏れを防ぐため、通知メールは代表者だけでなく、ポータルへ招待・登録されている社内スタッフの皆様全員（※メール通知設定を有効にしている方）へ配信されます。</li>
                        <li><strong>通知設定の変更と宛先の追加</strong>: 画面右上のメニュー内にある<b>「🔔 メール通知設定」</b>リンクをクリックすることで、メール通知の受け取りON/OFFをご自身のアカウントごとに切り替えることができます。また、切り替えポップアップ内の「追加の通知メールアドレス」欄に改行区切りでアドレスを追記することで、ポータルアカウントを持たない外部の関係者やメーリングリストなど、<b>複数の送信先アドレスを自由に登録・追加</b>することが可能です。</li>
                        <li><strong>返信不可アドレス</strong>: 通知メールは送信専用アドレスから送信されます。メールに直接返信いただいてもお答えできませんので、必ずポータルのチャット機能をご利用ください。</li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
