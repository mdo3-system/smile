<?php
// new_request.php
require_once 'auth.php';
require_once 'functions.php';
require_once 'google_drive_client.php';

check_auth(['client']);
$current_user_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_name = trim($_POST['project_name'] ?? '');
    
    if (empty($project_name)) {
        $message = "案件名を入力してください。";
    } else {
        $pdo->beginTransaction();
        try {
            // 1. 案件作成
            $stmt = $pdo->prepare("
                INSERT INTO projects (client_id, project_name, status, req_permit, req_wall, req_skin, req_sky, req_opt_kisohari) 
                VALUES (:client_id, :name, 'quote_req', :permit, :wall, :skin, :sky, :kisohari)
            ");
            $stmt->execute([
                'client_id' => $current_user_id,
                'name'      => $project_name,
                'permit'    => isset($_POST['req_permit']) ? 1 : 0,
                'wall'      => isset($_POST['req_wall']) ? 1 : 0,
                'skin'      => isset($_POST['req_skin']) ? 1 : 0,
                'sky'       => isset($_POST['req_sky']) ? 1 : 0,
                'kisohari'  => isset($_POST['req_opt_kisohari']) ? 1 : 0,
            ]);
            $new_project_id = $pdo->lastInsertId();

            // 2. 仕様データの初期登録 (空枠を作成)
            $stmtSpecs = $pdo->prepare("
                INSERT INTO project_specs (project_id) VALUES (:pid)
            ");
            $stmtSpecs->execute([
                'pid'  => $new_project_id
            ]);

            // 3. メモ・特記事項があればメッセージとして登録
            $memo = trim($_POST['memo'] ?? '');
            if (!empty($memo)) {
                $stmtMsg = $pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'client_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $new_project_id,
                    'sid' => $current_user_id,
                    'msg' => "【初回ご要望・特記事項】\n" . $memo
                ]);
            }

            // 4. ファイルアップロード処理 (Google Drive API)
            $upload_fields = [
                'file_pdf_plan'      => 'pdf_plan',
                'file_pdf_elevation' => 'pdf_elevation',
                'file_pdf_layout'    => 'pdf_layout',
                'file_pdf_section'   => 'pdf_section'
            ];

            foreach ($upload_fields as $input_name => $cat_key) {
                if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES[$input_name]['name'];
                    $tmp_name  = $_FILES[$input_name]['tmp_name'];
                    $mime_type = $_FILES[$input_name]['type'];

                    try {
                        $drive_file_id = upload_to_google_drive($tmp_name, $file_name, $mime_type);

                        $stmtFile = $pdo->prepare("
                            INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                            VALUES (:pid, :cat, :name, :drive_id, 1, 1)
                        ");
                        $stmtFile->execute([
                            'pid'      => $new_project_id,
                            'cat'      => $cat_key,
                            'name'     => $file_name,
                            'drive_id' => $drive_file_id
                        ]);
                    } catch (Exception $e) {
                        error_log("ファイルアップロードエラー ($cat_key): " . $e->getMessage());
                    }
                }
            }

            $pdo->commit();
            header("Location: index.php");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "案件の登録に失敗しました: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規見積・計算依頼 | 構造設計サポート・ポータル</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&family=Noto+Sans+JP:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            --card-bg: rgba(255, 255, 255, 0.9);
            --card-hover: rgba(255, 255, 255, 1);
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --accent-color: #10b981;
            --accent-hover: #059669;
            --text-color: #1e293b;
            --text-muted: #64748b;
            --border-color: rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Outfit', 'Noto Sans JP', sans-serif;
            background: var(--bg-gradient);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header-nav {
            margin-bottom: 20px;
        }
        .header-nav a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .header-nav a:hover {
            color: var(--primary-hover);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 30px;
            background: linear-gradient(to right, #3b82f6, #60a5fa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 20px;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-col {
            flex: 1;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        input[type="text"], select {
            width: 100%;
            padding: 12px 16px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-color);
            font-size: 15px;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        input[type="text"]:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }

        select option {
            background: #ffffff;
            color: var(--text-color);
        }

        .file-upload-box {
            border: 2px dashed rgba(0, 0, 0, 0.2);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            background: rgba(0, 0, 0, 0.02);
            transition: all 0.3s ease;
            position: relative;
        }
        .file-upload-box:hover {
            border-color: var(--primary-color);
            background: rgba(59, 130, 246, 0.05);
        }

        input[type="file"] {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        .file-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        .btn-submit {
            display: block;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent-color) 0%, var(--accent-hover) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        .message-box {
            padding: 15px;
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header-nav">
            <a href="index.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                ダッシュボードへ戻る
            </a>
        </div>

        <h1 class="page-title">新規見積・計算依頼</h1>

        <?php if (!empty($message)): ?>
            <div class="message-box"><?= htmlspecialchars($message, ENT_QUOTES) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <!-- 1. 案件基本情報 -->
            <div class="card">
                <h2 class="section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    基本情報
                </h2>
                <div class="form-group">
                    <label for="project_name">物件名（必須）</label>
                    <input type="text" id="project_name" name="project_name" placeholder="例: ○○様邸 新築工事" required>
                </div>
                
                <div class="form-group">
                    <label>📋 見積を依頼する計算・設計内容（複数選択可・最低1つ必須）</label>
                    <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 10px; background: rgba(0,0,0,0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="req_permit" value="1" id="req_permit" class="calc-type-chk"> 許容応力度設計
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="req_wall" value="1" id="req_wall" class="calc-type-chk"> （性能表示）壁量計算（※基準法の計算もカバーされます）
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="req_skin" value="1" id="req_skin" class="calc-type-chk"> 外皮計算（一次エネ計算セット）
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="req_sky" value="1" id="req_sky" class="calc-type-chk"> 天空率（道路斜線・北側斜線）
                        </label>
                        <div style="margin-top: 5px; padding-top: 10px; border-top: 1px dashed var(--border-color);">
                            <strong style="font-size: 12px; color: var(--text-muted);">追加オプション：</strong><br>
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; margin-top: 5px; font-weight: 600;">
                                <input type="checkbox" name="req_opt_kisohari" value="1" id="req_opt_kisohari"> 基礎・横架材の許容応力度計算
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="memo">メモ・特記事項（ご要望事項など）</label>
                    <textarea id="memo" name="memo" rows="4" placeholder="特記事項やご要望などがあればご記入ください。" style="width: 100%; padding: 12px 16px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-color); font-size: 15px; box-sizing: border-box; resize: vertical;"></textarea>
                </div>
            </div>

            <!-- 3. 見積用図面アップロード -->
            <div class="card">
                <h2 class="section-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                    見積用図面（PDF等）
                </h2>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="file-upload-box">
                            <input type="file" name="file_pdf_plan" accept=".pdf,.zip">
                            <div class="file-label">平面図 を選択</div>
                            <div class="file-hint">クリックまたはドラッグ＆ドロップ</div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="file-upload-box">
                            <input type="file" name="file_pdf_elevation" accept=".pdf,.zip">
                            <div class="file-label">立面図 を選択</div>
                            <div class="file-hint">クリックまたはドラッグ＆ドロップ</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="file-upload-box">
                            <input type="file" name="file_pdf_layout" accept=".pdf,.zip">
                            <div class="file-label">配置図 を選択</div>
                            <div class="file-hint">クリックまたはドラッグ＆ドロップ</div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="file-upload-box">
                            <input type="file" name="file_pdf_section" accept=".pdf,.zip">
                            <div class="file-label">矩計図（必要時）</div>
                            <div class="file-hint">クリックまたはドラッグ＆ドロップ</div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                この内容で見積を依頼する
            </button>
        </form>
    </div>

    <script>
        // 送信時のバリデーション（計算タイプを最低1つ選択させる）
        document.querySelector('form').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('.calc-type-chk');
            let checkedOne = false;
            checkboxes.forEach(chk => {
                if (chk.checked) checkedOne = true;
            });
            if (!checkedOne) {
                e.preventDefault();
                alert('見積を依頼する計算・設計内容を最低1つ選択してください。');
            }
        });

        // ファイル選択時のUI更新
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function(e) {
                const box = this.closest('.file-upload-box');
                const label = box.querySelector('.file-label');
                if (this.files.length > 0) {
                    label.textContent = this.files[0].name;
                    label.style.color = '#10b981';
                    box.style.borderColor = '#10b981';
                    box.style.background = 'rgba(16, 185, 129, 0.05)';
                } else {
                    label.textContent = label.getAttribute('data-default') || 'ファイルを選択';
                    label.style.color = '';
                    box.style.borderColor = '';
                    box.style.background = '';
                }
            });
            // 初期ラベルの保存
            const label = input.closest('.file-upload-box').querySelector('.file-label');
            label.setAttribute('data-default', label.textContent);
        });
    </script>
</body>
</html>
