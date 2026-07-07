<?php
// manual_subcontractor.php
require_once 'auth.php';
require_once 'functions.php';

check_auth(['admin', 'accountant', 'subcontractor']); // 協力業者、経理、管理者が閲覧可能

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操作マニュアル（協力業者様向け） | 木造住宅設計サポート・ポータル</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --dark: #0f172a;
            --light: #f8fafc;
            --slate-300: #cbd5e1;
            --slate-600: #475569;
            --border: #e2e8f0;
            --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --hover-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.08);
        }

        body {
            font-family: 'Noto Sans JP', 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            margin: 0;
            padding: 0;
            line-height: 1.7;
        }

        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .header p {
            margin: 10px 0 0 0;
            font-size: 15px;
            opacity: 0.9;
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .nav-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 30px;
            transition: transform 0.2s;
        }

        .nav-back:hover {
            transform: translateX(-4px);
        }

        .section-card {
            background: white;
            border-radius: 16px;
            padding: 35px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border);
            transition: box-shadow 0.3s;
        }

        .section-card:hover {
            box-shadow: var(--hover-shadow);
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3a8a;
            margin-top: 0;
            margin-bottom: 25px;
            border-bottom: 2px solid #eff6ff;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .step-timeline {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
            position: relative;
        }

        .step-node {
            background: var(--light);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border);
            text-align: center;
            position: relative;
        }

        .step-num {
            background: var(--primary);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            margin: 0 auto 12px auto;
        }

        .step-name {
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .step-desc {
            font-size: 11px;
            color: var(--slate-600);
            line-height: 1.5;
        }

        /* ぼかし・影つきの画像スタイル */
        .image-wrapper {
            margin: 25px 0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
            background: #f8fafc;
            text-align: center;
        }

        .image-wrapper img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        .image-caption {
            font-size: 12px;
            color: var(--slate-600);
            padding: 10px;
            background: #f1f5f9;
            border-top: 1px solid var(--border);
            font-weight: 500;
        }

        .info-box {
            background: #eff6ff;
            border-left: 4px solid var(--primary);
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            font-size: 14px;
        }

        .info-box h4 {
            margin: 0 0 8px 0;
            color: #1e3a8a;
            font-weight: 700;
        }

        .grid-two-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        @media (max-width: 768px) {
            .grid-two-cols {
                grid-template-columns: 1fr;
            }
            .section-card {
                padding: 20px;
            }
        }

        ul, ol {
            padding-left: 20px;
            margin: 10px 0;
        }

        li {
            margin-bottom: 8px;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: bold;
            border-radius: 4px;
            margin-left: 6px;
        }
        .badge-primary { background: #dcfce7; color: #166534; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
    </style>
</head>
<body>

    <div class="header">
        <h1>👷 協力業者様向け 操作マニュアル</h1>
        <p>木造住宅設計サポート・ポータルでの「作図発注の承諾」「納品」「報酬状況」の確認手順</p>
    </div>

    <div class="container">
        <a href="index.php" class="nav-back">➔ ダッシュボード一覧に戻る</a>

        <!-- セクション1: 全体の業務フロー -->
        <div class="section-card">
            <h2 class="section-title">⏱️ 作図発注から納品完了までの流れ</h2>
            <p>設計担当者から作図の依頼が発生すると、ダッシュボード上に案件カードとして表示されます。以下のフローに沿って納品を行ってください。</p>
            
            <div class="step-timeline">
                <div class="step-node">
                    <div class="step-num">1</div>
                    <div class="step-name">発注依頼の受領</div>
                    <div class="step-desc">案件カードから、希望納期や依頼額を確認します。</div>
                </div>
                <div class="step-node">
                    <div class="step-num">2</div>
                    <div class="step-name">発注の承諾</div>
                    <div class="step-desc">受託可能な場合、承諾ボタンを押し「完了予定日」を入力します。</div>
                </div>
                <div class="step-node">
                    <div class="step-num">3</div>
                    <div class="step-name">CADデータのダウンロード</div>
                    <div class="step-desc">承諾完了後、設計用意匠図等のCADデータが公開されます。</div>
                </div>
                <div class="step-node">
                    <div class="step-num">4</div>
                    <div class="step-name">作図とデータの納品</div>
                    <div class="step-desc">作図が完了したら、チャットスロットを通じてデータをアップロードします。</div>
                </div>
                <div class="step-node">
                    <div class="step-num">5</div>
                    <div class="step-name">納品承認・完了</div>
                    <div class="step-desc">設計担当者が検収・承認すると、月次の報酬・支払予定に加算されます。</div>
                </div>
            </div>
        </div>

        <!-- セクション2: 案件一覧（ダッシュボード）の説明 -->
        <div class="section-card">
            <h2 class="section-title">📂 案件一覧（ダッシュボード）の使いかた</h2>
            <p>ログイン直後のメインダッシュボード画面です。</p>

            <div class="image-wrapper">
                <img src="assets/images/manual/subcontractor_dashboard.png" alt="協力業者ダッシュボード">
                <div class="image-caption">【ダッシュボード】受託中のタスクカード、メッセージ通知、および月次報酬サマリー</div>
            </div>

            <h3>各部の説明</h3>
            <ul>
                <li><strong>案件カード（グリッド）:</strong> 現在ご自身が関わっている、または発注依頼中のアクティブな案件がカード形式で表示されます。</li>
                <li><strong>ステータスバッジ:</strong>
                    <ul>
                        <li><span style="color:#d97706; font-weight:bold;">依頼中 (未承諾):</span> 作図依頼が届いている状態です。内容を確認して承諾を行ってください。</li>
                        <li><span style="color:#2563eb; font-weight:bold;">進行中 (受託済):</span> 承諾し、現在作図を進めている状態です。</li>
                        <li><span style="color:#059669; font-weight:bold;">完了:</span> 納品物が承認され、タスクが完了した状態です。</li>
                    </ul>
                </li>
                <li><strong>案件以外のチャット（共通チャット）:</strong> 左側のメニューから、特定の案件に紐づかない「全体的な事務連絡・支払相談」を行うためのチャット画面へアクセスできます。</li>
            </ul>
        </div>

        <!-- セクション3: 月次報酬・お受け取り状況について -->
        <div class="section-card">
            <h2 class="section-title">💵 月次報酬とお受け取り（お支払い）状況の確認</h2>
            <p>ダッシュボード上部または専用の確認画面から、完了したタスクの「当月締めのお受け取り予定報酬額」をリアルタイムで確認できます。</p>
            
            <div class="info-box">
                <h4>📌 お支払いの締め基準と予定</h4>
                <p>当システムの標準支払い基準は「<strong>前月26日〜当月25日締め</strong>」です。この期間内に設計担当者が<strong>納品を承認（completed）</strong>したタスクの合計額が、翌月のお支払い対象となります。</p>
            </div>
            <ul>
                <li><strong>未払額（今月受取予定分）:</strong> 締め期間中に完了した、まだ振り込まれていない今期受取予定額です。</li>
                <li><strong>支払済（当月実績）:</strong> すでに経理担当よりお振込の手続きが完了し、`支払済` となった金額です。お振込の完了時にはチャットへ自動的に通知が届きます。</li>
            </ul>
        </div>

        <!-- セクション4: 案件別（詳細）ダッシュボード -->
        <div class="section-card">
            <h2 class="section-title">👷 案件別の作図プロセス・納品方法</h2>
            <p>案件名をクリックすると、その案件専用の作業画面（案件別ダッシュボード）が開きます。</p>

            <div class="grid-two-cols">
                <div>
                    <h3>1. 意匠図・構造図の依頼と承諾の流れ</h3>
                    <p>設計担当者から「意匠図（または構造図）の作図依頼」が来ると、カード内の承諾ボタンがアクティブになります。</p>
                    <ol>
                        <li>「希望納期」と「提示金額」を確認します。</li>
                        <li><strong>承諾ボタン</strong>を押すとダイアログが表示されますので、ご自身の「完了予定日」を入力して確定します。</li>
                        <li>承諾が完了するとステータスが「進行中」へ切り替わり、設計側がUPしたCADデータ（下図）がダウンロード可能になります。</li>
                    </ol>
                    
                    <h3>2. CADデータのダウンロード</h3>
                    <p>「CADデータ」スロットに設計担当者がアップロードした基準用データ（DXF/DWG等）が表示されます。ダウンロードして作図を開始してください。</p>
                </div>
                <div>
                    <h3>3. 設計チャットでのコミュニケーション</h3>
                    <p>画面右側には、その案件専用のチャットスペースがあります。仕様についての不明点、不整合の相談、質問などはすべてこのチャットを通じてリアルタイムにやり取りを行います。</p>
                    
                    <h3>4. 成果物の納品方法</h3>
                    <p>作図が完了したら、以下の手順でデータを納品します。</p>
                    <ol>
                        <li>「協力業者 成果物UP」スロットにある「UP/更新」ボタンを押します。</li>
                        <li>作成したCADデータ（dxf / dwg 等）を選択しアップロードします。</li>
                        <li>設計担当者へチャットで「作図が完了しましたので、納品いたしました。ご確認ください」と連絡します。</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- 3. 自動メール通知と通知先設定について -->
        <div class="card" style="margin-top:20px;">
            <h2>✉️ システムからの自動メール通知と宛先設定について</h2>
            <p>本システムでは、作図依頼や納品物の確認漏れなどを防ぐため、以下のタイミングで自動メール通知が送信されます。</p>
            
            <div class="grid-desc">
                <div>
                    <h3>💡 通知が送信される主なタイミング</h3>
                    <ul>
                        <li><strong>新規作図依頼の受信時</strong>: 設計側から新規の意匠図・構造図の作図依頼が届いた際、通知メールが一斉に届きます。</li>
                        <li><strong>修正指示（チェックバック）の受信時</strong>: 設計担当者から簡易修正やチェックバック指示書のアップロードが行われた際、通知メールが届きます。</li>
                        <li><strong>お振込み完了時</strong>: 月末納品分の報酬お支払いの手続きが完了した際、完了通知メールが届きます。</li>
                    </ul>
                </div>
                <div>
                    <h3>⚙️ 通知設定の変更と追加宛先の設定方法</h3>
                    <ul>
                        <li><strong>通知設定（ON/OFF）</strong>: 協力業者ポータル画面の右上にある<b>「🔔 メール通知設定」</b>リンクをクリックすることで、メール通知のON/OFFをご自身のアカウントごとに切り替えることができます。</li>
                        <li><strong>追加の送信先アドレスの登録</strong>: ポップアップトグルの下にある「追加の通知メールアドレス」入力欄に改行区切りでアドレスを入力し、「設定を保存」ボタンを押すことで、主アドレス以外の宛先（社内の共有メーリングリストなど）にも<b>同時にメール通知を配信するよう設定</b>できます。</li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
