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
    <title>操作マニュアル（依頼主様向け） | 木造住宅設計サポート・ポータル</title>
    <style>
        body { 
            font-family: 'Helvetica Neue', Arial, 'Noto Sans JP', sans-serif; 
            background: #f4f6f9; 
            margin: 0; 
            padding: 0; 
            color: #333; 
            line-height: 1.6;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 0.5px;
            color: #ffffff;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 14px;
            color: #ffffff;
            opacity: 0.9;
        }
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .nav-back {
            margin-bottom: 20px;
        }
        .nav-back a {
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .nav-back a:hover {
            text-decoration: underline;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border-top: 4px solid #3b82f6;
        }
        .card-green {
            border-top-color: #10b981;
        }
        .card-orange {
            border-top-color: #f97316;
        }
        h2 {
            margin-top: 0;
            font-size: 18px;
            color: #1e3a8a;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        h3 {
            font-size: 15px;
            color: #2d3748;
            margin-top: 25px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        p {
            font-size: 13px;
            color: #4a5568;
            margin-bottom: 15px;
        }
        .timeline {
            position: relative;
            margin: 20px 0;
            padding-left: 20px;
            border-left: 2px solid #e2e8f0;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -27px;
            top: 2px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #3b82f6;
            border: 2px solid white;
            box-shadow: 0 0 0 2px #3b82f6;
        }
        .timeline-item.active::before {
            background: #10b981;
            box-shadow: 0 0 0 2px #10b981;
        }
        .timeline-title {
            font-weight: bold;
            font-size: 14px;
            color: #1e3a8a;
            margin-bottom: 5px;
        }
        .timeline-desc {
            font-size: 12px;
            color: #64748b;
        }
        .img-container {
            margin: 20px 0;
            text-align: center;
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .img-container img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .img-caption {
            font-size: 11px;
            color: #64748b;
            margin-top: 10px;
            font-weight: bold;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            color: white;
        }
        .badge-green { background-color: #10b981; }
        .badge-blue { background-color: #3b82f6; }
        .badge-gray { background-color: #64748b; }
        
        .box-info {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            border-radius: 0 8px 8px 0;
            margin: 15px 0;
            font-size: 12px;
            color: #1e3a8a;
        }
        .box-warning {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 0 8px 8px 0;
            margin: 15px 0;
            font-size: 12px;
            color: #78350f;
        }
        
        .grid-desc {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }
        @media(max-width:768px) {
            .grid-desc {
                grid-template-columns: 1fr;
            }
        }
        .desc-col {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .desc-col h4 {
            margin-top: 0;
            font-size: 13px;
            color: #1e293b;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .desc-col ul {
            margin: 0;
            padding-left: 20px;
            font-size: 12px;
            color: #475569;
        }
        .desc-col li {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>📖 木造住宅設計サポート・ポータル 操作マニュアル</h1>
        <p>〜 依頼主（設計者・施工業者）様向け 〜</p>
    </div>

    <div class="container">
        
        <div class="nav-back">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="index.php">➔ ポータルダッシュボードに戻る</a>
            <?php else: ?>
                <a href="../">➔ WEBサイトに戻る</a>
            <?php endif; ?>
        </div>

        <!-- 1. 業務全体の流れ（タイムライン） -->
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
                    <div class="timeline-title">ステップ3: 正式なご依頼 ＆ 設計CADデータのUP</div>
                    <div class="timeline-desc">見積内容に問題がなければ、詳細画面の「設計依頼データの送付」パネルから、意匠図CADデータ（JWW/DXF等）や地盤調査報告書などをUPして正式にご依頼ください。</div>
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
                💡 **ワンポイントアドバイス**: スケジュールは「水曜日・日曜日」を除外した営業日で自動計算されます。一次回答CBのご提出や着手金のご入金が遅れると、以降の各予定日も自動的にその日数分順延されますのでご注意ください。
            </div>
        </div>

        <!-- 2. ダッシュボード（一覧画面）の説明 -->
        <div class="card card-green">
            <h2>📱 案件一覧画面（ダッシュボード）の見方</h2>
            <p>ログイン後に最初に表示される「案件一覧画面」では、現在動いているすべての物件の進行状況が一覧で並んでいます。</p>
            
            <div class="img-container">
                <img src="assets/images/manual/dashboard.png" alt="ダッシュボード一覧画面のスクリーンショット">
                <div class="img-caption">【図1】案件一覧画面（各物件カードのステータスと予定日表示）</div>
            </div>

            <div class="grid-desc">
                <div class="desc-col">
                    <h4>💡 カードに表示されている内容</h4>
                    <ul>
                        <li><strong>ステータスバッジ（左上）</strong>: 現在の物件ステータス（「見積依頼」「一次回答準備中」「申請図書作成中」「審査・待機」など）です。</li>
                        <li><strong>主導権バッジ（右上）</strong>:
                            <span class="badge badge-green">🟩 あなたのターン</span> : あなたからの返信やデータ提出、入金をお待ちしている状態です。<br>
                            <span class="badge badge-blue">🟦 相手のターン</span> : サポート担当が作図や計算、回答を準備している状態です。
                        </li>
                        <li><strong>📅 予定日</strong>: <u>現在進行している工程</u>の目標・予定日です。一次回答時だけでなく、構造図UPや補正対応など、その時々の現在地の予定日が表示されます。</li>
                        <li><strong>現在の工程</strong>: 現在どこのフェーズにあるかが具体的に表示されます。</li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>📝 すること・できること</h4>
                    <ul>
                        <li><strong>新規ご依頼</strong>: 右上の「➕ 新規見積・計算依頼」から新しい案件をいつでも登録できます。</li>
                        <li><strong>詳細を開く</strong>: 各物件の「詳細を開く」ボタンから、チャットの送信、図書のダウンロード、依頼データのUPを行う詳細画面へ進みます。</li>
                        <li><strong>📂 完了案件DB</strong>: 審査がすべて完了した過去の物件は自動で非表示になり、上部の「📂 完了案件DB」リンクから過去の履歴として検索・ダウンロード可能です。</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 3. 案件詳細画面の説明 -->
        <div class="card card-orange">
            <h2>📄 案件詳細画面の使い方と各機能</h2>
            <p>案件詳細画面は、左・中央・右の3つのカラムに分かれており、必要な操作が直感的に行えるよう設計されています。</p>
            
            <div class="img-container">
                <img src="assets/images/manual/project_detail.png" alt="案件詳細画面のスクリーンショット">
                <div class="img-caption">【図2】案件詳細画面（左・中央・右の機能配置とスケジュール強調）</div>
            </div>

            <div class="grid-desc">
                <div class="desc-col">
                    <h4>👈 左カラム：基本情報・ご請求状況 ＆ ご入金報告</h4>
                    <ul>
                        <li><strong>基本情報・ご依頼内容</strong>: 物件名や担当者、要求した計算（許容応力度・外皮など）が表示されます。</li>
                        <li><strong>御見積書</strong>: サポートより見積書が発行されると、緑色の「📄 見積書を表示・ダウンロード」ボタンが出現します。</li>
                        <li><strong>ご請求書</strong>: 一次回答後に「一次請求書（50%分）」、審査完了後に「最終請求書」がここに提示され、PDFで確認・保存できます。</li>
                        <li><strong>正式ご依頼（設計依頼データ送付）</strong>: 見積確認後、ここに表示されるアップローダーから意匠CADや地盤データをUPすることで、正式な設計業務が開始されます。</li>
                        <li><strong>💵 中間金（50％）入金後の報告ボタン</strong>:
                            一次回答提示後に中間金（50%）のお振込みが完了しましたら、必ず<b>「💵 中間金（50％）を入金しました」</b>ボタンを押してください。このボタンを押すことで、自動的に経理担当へ入金報告メールが送信され、また工程スケジュール上の「中間金（50％）のご入金」の実績日（実施日）が本日付で自動登録されます。
                        </li>
                        <li><strong>💮 残金ご精算・審査完了報告ボタン</strong>:
                            確認申請合格後、残金をお振込みいただき<b>「💮 残金お振込み ＆ 審査完了にする（審査合格）」</b>ボタンを押すことで、正式に案件が「完了」ステータスとなり、すべての正式図書データ（安全証明書原本等）の受領が可能になります。
                            なお、事前一括入金されている場合など<b>「残金が0円」のときは、自動的に「💮 審査完了にする（審査合格）」ボタンに切り替わり</b>、振込案内や赤字注意書きも自動で非表示になります。
                        </li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>👉 中央カラム：スケジュールと成果物受領</h4>
                    <ul>
                        <li><strong>予定日/実績日テーブル</strong>: 
                            実施日が入っていない最初の工程（現在地）が <span style="border: 1px solid #ef4444; background: #fee2e2; padding: 2px 5px; border-radius:3px; font-weight:bold; color:#ef4444; font-size:10px;">👉 現在地</span> として赤く囲まれて強調表示されます。目標予定日が明記されており、サポートが完了を登録すると実績日が入り、次の工程へハイライトが移ります。
                        </li>
                        <li><strong>成果物管理（提出・納品図書）</strong>: 
                            サポートが納品した「構造計算書」「安全証明書」「構造図一式」などは、このエリアに格納されます。最新バージョン（V1, V2...）が自動で管理され、いつでもダウンロードできます。
                        </li>
                        <li><strong>依頼図書（提出物）スロット</strong>: 
                            あなたが提出した建築図面などがカテゴリ別に整理されています。追加資料のUPもここから行えます。
                        </li>
                    </ul>
                </div>
            </div>

            <h3 style="border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; margin-top:20px;">💬 右カラム：チャットエリア（質疑応答・連絡）</h3>
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
                    <h4>📁 ファイルの添付と送信</h4>
                    <ul>
                        <li>チャット入力欄の左側にある「📎 添付」ボタンから、画像やPDF、CADデータなどを添付してメッセージを送ることができます。</li>
                        <li>一時回答へのチェックバックや指摘事項は、朱書きPDFなどをチャットにUPして伝えることで、スムーズに設計へ反映されます。</li>
                    </ul>
                </div>
            </div>
            
            <div class="box-warning" style="margin-top:20px;">
                ⚠️ **注意点**: 構造検討中に図面間の不整合（平面図と立面図で窓の位置や高さがズレている等）が見つかった場合、チャットにて確認のご連絡を差し上げます。ご回答があるまで設計作業が一時的に止まりますので、早めのご確認・ご返信をいただけますと幸いです。
            </div>
        </div>

        <!-- 4. メール通知機能について -->
        <div class="card">
            <h2>✉️ システムからの自動メール通知について</h2>
            <p>本システムでは、関係者間でのやり取りや進捗の確認漏れを防ぐため、以下のタイミングで自動メール通知が送信されます。</p>
            
            <div class="grid-desc">
                <div class="desc-col">
                    <h4>💡 通知が送信される主なタイミング</h4>
                    <ul>
                        <li><strong>新着チャット受信時</strong>: サポート担当者からメッセージが届いた際、企業の通知設定が有効なスタッフ全員に新着通知メールが配信されます。</li>
                        <li><strong>成果物・御見積書の提示時</strong>: 初期見積書の発行、一時回答のアップロード、各種請求書の発行などのタイミングで通知メールが届きます。</li>
                        <li><strong>審査状況リマインダー</strong>: 「補正対応（図面一式UP）」の工程が完了してから3週間（21日）が経過し、まだ「完了」登録がなされていない案件について、審査の合格状況を確認するメールが自動送信されます。</li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>⚙️ 事前にご承知おきいただきたいこと</h4>
                    <ul>
                        <li><strong>社内スタッフ全員への配信</strong>: 連絡の確認漏れを防ぐため、通知メールは代表者だけでなく、ポータルへ招待・登録されている社内スタッフの皆様全員（※メール通知設定を有効にしている方）へ配信されます。</li>
                        <li><strong>通知のON/OFF切り替え</strong>: ポータル画面右上の「ユーザー設定（人型マーク）」メニューから、メール通知を受け取るかどうかのON/OFFをいつでも個別に切り替えることができます。</li>
                        <li><strong>返信不可アドレス</strong>: 通知メールは送信専用アドレスから送信されます。メールに直接返信いただいてもお答えできませんので、必ずポータルのチャット機能をご利用ください。</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 5. アカウント追加・チームメンバー招待について -->
        <div class="card card-green">
            <h2>👥 アカウント追加と招待リンクの種類（用途の違い）</h2>
            <p>本システムでは、用途に合わせて以下の2種類の招待リンクを使い分け、社内メンバーや物件関係者をポータルに招き入れることができます。</p>

            <div class="grid-desc">
                <div class="desc-col">
                    <h4>🏢 ① 企業全体・社内スタッフ招待（ダッシュボード招待）</h4>
                    <ul>
                        <li><strong>用途</strong>: 同じ会社・チーム内の別メンバーを登録し、全ての情報を共有する場合。</li>
                        <li><strong>見え方</strong>: 登録されたスタッフは、企業のアカウントグループに紐づき、**見積依頼中の物件、設計進行中のすべての案件、および過去の完了案件DBすべて**を代表者と全く同様に閲覧・操作できます。</li>
                        <li><strong>方法</strong>: ダッシュボード（案件一覧画面）の上部にある「👥 社内スタッフを招待」ボタンからリンクをコピーして相手に送ります。</li>
                    </ul>
                </div>
                <div class="desc-col">
                    <h4>🏠 ② 特定の案件限定の招待（個別案件ダッシュボード招待）</h4>
                    <ul>
                        <li><strong>用途</strong>: その物件を設計する設計事務所の担当者や、施工会社の担当者など、物件ごとの社外関係者を招き入れたい場合。</li>
                        <li><strong>見え方</strong>: 登録されたユーザーは、**招待された特定の1案件のダッシュボード（チャット、成果物、図書UP）のみにアクセスが限定**されます。企業全体の他の案件や、見積一覧などは一切閲覧できません。</li>
                        <li><strong>方法</strong>: 各案件の詳細画面の上部にある「🔗 施工業者・設計者を招待」ボタンからリンクをコピーして相手に送ります。</li>
                    </ul>
                </div>
            </div>
            
            <div class="box-info" style="margin-top:15px;">
                💡 **招待の猶予時間**: 招待リンクの有効期限は発行から **24時間** です。もし24時間が経過して期限切れになった場合は、再度同じボタンから最新のURLを発行していただくか、ログイン画面（login.php）より登録済みのメールアドレスを送信することでログインリンクを再発行できます。
            </div>
        </div>

    </div>

</body>
</html>
