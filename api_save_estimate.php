<?php
// api_save_estimate.php
require_once 'auth.php';
require_once 'functions.php';
require_once 'google_drive_client.php';
require_once 'estimate_pdf_generator.php';

check_auth(['admin']); // 管理者のみアクセス可能

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $base_price = intval($_POST['base_price'] ?? 0);
    $area = floatval($_POST['area'] ?? 0);
    $grade_price = intval($_POST['grade_price'] ?? 0);
    $total_price = intval($_POST['total_price'] ?? 0);
    $note = $_POST['note'] ?? ''; // 明細JSON文字列

    if ($project_id <= 0) {
        echo json_encode(['success' => false, 'error' => '無効な案件IDです。']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. estimatesテーブルに保存または更新
        $stmt = $pdo->prepare("SELECT id FROM estimates WHERE project_id = :pid");
        $stmt->execute(['pid' => $project_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmtUpdate = $pdo->prepare("
                UPDATE estimates 
                SET base_price = :base, area = :area, grade_price = :grade, total_price = :total, note = :note, updated_at = NOW() 
                WHERE project_id = :pid
            ");
            $stmtUpdate->execute([
                'base' => $base_price,
                'area' => $area,
                'grade' => $grade_price,
                'total' => $total_price,
                'note' => $note,
                'pid' => $project_id
            ]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO estimates (project_id, base_price, area, grade_price, total_price, note) 
                VALUES (:pid, :base, :area, :grade, :total, :note)
            ");
            $stmtInsert->execute([
                'pid' => $project_id,
                'base' => $base_price,
                'area' => $area,
                'grade' => $grade_price,
                'total' => $total_price,
                'note' => $note
            ]);
        }

        // 2. Google Driveのフォルダを取得または作成
        $project_folder_id = get_or_create_project_drive_folder($pdo, $project_id);

        // 3. PDFファイルを一時生成
        $temp_pdf_path = generate_estimate_pdf($project_id, $pdo);

        // 4. Google Driveの案件フォルダにアップロード
        // 案件名と依頼内容を取得
        $stmtProj = $pdo->prepare("SELECT project_name, req_permit, req_wall, req_skin, req_sky FROM projects WHERE id = :pid");
        $stmtProj->execute(['pid' => $project_id]);
        $proj_info = $stmtProj->fetch();
        $proj_name = $proj_info ? $proj_info['project_name'] : $project_id;
        $pdf_filename = '御見積書_' . $proj_name . '.pdf';

        $drive_file_id = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);

        // 一時生成したローカルのPDFを削除
        if (file_exists($temp_pdf_path)) {
            unlink($temp_pdf_path);
        }

        // 5. 生成されたPDFのGoogle DriveファイルIDをestimatesに書き込み
        $stmtUpdatePdfId = $pdo->prepare("UPDATE estimates SET pdf_drive_file_id = :drive_id WHERE project_id = :pid");
        $stmtUpdatePdfId->execute([
            'drive_id' => $drive_file_id,
            'pid' => $project_id
        ]);

        // 6. チャット（messagesテーブル）に見積書共有メッセージを自動投稿
        $tax = round($total_price * 0.1);
        $grand_total = $total_price + $tax;
        
        $chat_message = "お世話になっております。システムより初期見積書を発行いたしました。\n";
        $chat_message .= "添付のファイルより御見積書PDFをご確認いただけます。\n\n";
        $chat_message .= "件名: " . ($proj_name ? $proj_name : 'ご指定案件') . " 新築工事 設計等業務\n";
        $chat_message .= "税抜金額: " . number_format($total_price) . "円\n";
        $chat_message .= "消費税: " . number_format($tax) . "円\n";
        $chat_message .= "税込合計: " . number_format($grand_total) . "円\n\n";
        
        $chat_message .= "【今後の流れについて】\n";
        $chat_message .= "内容に問題がなければ、左パネルより構造仕様の指定や必要図書のアップロードをお願いいたします。\n";
        
        // 依頼別の案内を追加
        if ($proj_info) {
            if ($proj_info['req_permit'] == 1 || $proj_info['req_wall'] == 1) {
                $chat_message .= "・構造計算: 意匠CADデータ（平面・立面・配置・矩計）および確認申請書、地盤調査報告書などが必要です。\n";
            }
            if ($proj_info['req_skin'] == 1) {
                $chat_message .= "・外皮計算: 断熱材やサッシの仕様書、設備仕様書（換気・エアコン等）をアップロードしてください。\n";
            }
            if ($proj_info['req_sky'] == 1) {
                $chat_message .= "・天空率計算: 道路の資料（座標、測量図、道路台帳等）や真北の資料をアップロードしてください。\n";
            }
        }
        
        $chat_message .= "\nご不明な点がございましたら、このチャットでお気軽にご連絡ください。\nよろしくお願いいたします。";

        // 管理者(ID=1)からのメッセージとして送信
        $stmtSendMsg = $pdo->prepare("
            INSERT INTO messages (project_id, sender_id, thread_type, message_text, file_path, file_type, created_at) 
            VALUES (:pid, 1, 'client_admin', :msg, :fpath, 'pdf', NOW())
        ");
        $stmtSendMsg->execute([
            'pid' => $project_id,
            'msg' => $chat_message,
            'fpath' => $drive_file_id
        ]);

        $pdo->commit();
        echo json_encode(['success' => true, 'drive_file_id' => $drive_file_id]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}

