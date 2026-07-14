<?php
// api_self_estimate.php
if (!defined('PHPUNIT_RUNNING')) {
    require_once 'db_connect.php';
}
require_once 'functions.php';

/**
 * Handles the self-estimate submission, including user creation, project creation,
 * magic link generation, chat message insertion, and email notifications.
 *
 * @param array $data Input parameters
 * @param PDO $pdo PDO database instance
 * @param bool $isTest If true, skips headers, exits, and actual email sending
 * @return array Response payload and HTTP status code
 */
function handleSelfEstimate(array $data, PDO $pdo, bool $isTest = false) {
    // 1. Verify API Token
    $env_token = getenv('SELF_ESTIMATE_API_TOKEN');
    if (empty($env_token) && file_exists(__DIR__ . '/.env')) {
        $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                if (trim($name) === 'SELF_ESTIMATE_API_TOKEN') {
                    $env_token = trim(trim($value), '"\'');
                    break;
                }
            }
        }
    }

    $api_token = trim($data['api_token'] ?? '');
    if (empty($env_token) || $api_token !== $env_token) {
        return ['success' => false, 'message' => 'Forbidden: Invalid API Token', 'code' => 403];
    }

    // 2. Validate input parameters
    $email = trim($data['email'] ?? '');
    $company_name = trim($data['company_name'] ?? '');
    $contact_name = trim($data['contact_name'] ?? '');
    $phone_number = trim($data['phone_number'] ?? '');
    $project_name = trim($data['project_name'] ?? '');

    if (empty($email) || empty($company_name) || empty($contact_name)) {
        return ['success' => false, 'message' => 'Missing required fields: email, company_name, contact_name are required', 'code' => 400];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address', 'code' => 400];
    }

    // 3. Calculation options
    $req_permit = isset($data['req_permit']) && (intval($data['req_permit']) === 1 || $data['req_permit'] === true) ? 1 : 0;
    $req_wall = isset($data['req_wall']) && (intval($data['req_wall']) === 1 || $data['req_wall'] === true) ? 1 : 0;
    $req_skin = isset($data['req_skin']) && (intval($data['req_skin']) === 1 || $data['req_skin'] === true) ? 1 : 0;
    $req_sky = isset($data['req_sky']) && (intval($data['req_sky']) === 1 || $data['req_sky'] === true) ? 1 : 0;
    $req_opt_kisohari = isset($data['req_opt_kisohari']) && (intval($data['req_opt_kisohari']) === 1 || $data['req_opt_kisohari'] === true) ? 1 : 0;

    $estimate_details = trim($data['estimate_details'] ?? '');

    $pdo->beginTransaction();
    try {
        // A. Existing user check
        $stmtCheck = $pdo->prepare("SELECT id, role, parent_id FROM users WHERE email = :email");
        $stmtCheck->execute(['email' => $email]);
        $existing_user = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $is_new_user = false;
        $user_id = null;
        $client_company_id = null;

        if ($existing_user) {
            $user_id = $existing_user['id'];
            $client_company_id = $existing_user['parent_id'] ?: $user_id;
            
            // Update profile fields if currently empty
            $stmtUpdate = $pdo->prepare("
                UPDATE users 
                SET company_name = CASE WHEN company_name IS NULL OR company_name = '' THEN :company ELSE company_name END,
                    contact_name = CASE WHEN contact_name IS NULL OR contact_name = '' THEN :contact ELSE contact_name END,
                    phone_number = CASE WHEN phone_number IS NULL OR phone_number = '' THEN :phone ELSE phone_number END
                WHERE id = :uid
            ");
            $stmtUpdate->execute([
                'company' => $company_name,
                'contact' => $contact_name,
                'phone'   => $phone_number,
                'uid'     => $user_id
            ]);
        } else {
            $is_new_user = true;
            // Create user (role = 'client')
            $stmtInsert = $pdo->prepare("
                INSERT INTO users (company_name, contact_name, email, phone_number, role, email_notification_enabled) 
                VALUES (:company, :contact, :email, :phone, 'client', 1)
            ");
            $stmtInsert->execute([
                'company' => $company_name,
                'contact' => $contact_name,
                'email'   => $email,
                'phone'   => $phone_number
            ]);
            $user_id = $pdo->lastInsertId();
            $client_company_id = $user_id;
        }

        // B. Create project
        if (empty($project_name)) {
            $project_name = "セルフ見積もり案件_" . date('Ymd_His');
        }

        $stmtProj = $pdo->prepare("
            INSERT INTO projects (client_id, project_name, billing_company_name, billing_phone_number, status, req_permit, req_wall, req_skin, req_sky, req_opt_kisohari) 
            VALUES (:client_id, :name, :billing, :b_phone, 'quote_req', :permit, :wall, :skin, :sky, :kisohari)
        ");
        $stmtProj->execute([
            'client_id' => $client_company_id,
            'name'      => $project_name,
            'billing'   => $company_name,
            'b_phone'   => $phone_number,
            'permit'    => $req_permit,
            'wall'      => $req_wall,
            'skin'      => $req_skin,
            'sky'       => $req_sky,
            'kisohari'  => $req_opt_kisohari
        ]);
        $new_project_id = $pdo->lastInsertId();

        // C. Initialize specs
        $stmtSpecs = $pdo->prepare("INSERT INTO project_specs (project_id) VALUES (:pid)");
        $stmtSpecs->execute(['pid' => $new_project_id]);

        // C-2. Save estimate data to estimates table
        $base_price = (int)($data['base_price'] ?? 0);
        $area = (float)($data['area'] ?? 0.0);
        $grade_price = (int)($data['grade_price'] ?? 0);
        $total_price = (int)($data['total_price'] ?? 0);
        
        $note = $data['note'] ?? '[]';
        if (is_array($note)) {
            $note = json_encode($note, JSON_UNESCAPED_UNICODE);
        }
        $inputs_json_val = $data['inputs_json'] ?? '{}';
        if (is_array($inputs_json_val)) {
            $inputs_json_val = json_encode($inputs_json_val, JSON_UNESCAPED_UNICODE);
        }

        $stmtEst = $pdo->prepare("
            INSERT INTO estimates (project_id, base_price, area, grade_price, total_price, note, pdf_drive_file_id, req_permit, req_wall, req_skin, req_sky, inputs_json)
            VALUES (:pid, :base, :area, :grade, :total, :note, NULL, :permit, :wall, :skin, :sky, :inputs_json)
        ");
        $stmtEst->execute([
            'pid' => $new_project_id,
            'base' => $base_price,
            'area' => $area,
            'grade' => $grade_price,
            'total' => $total_price,
            'note' => $note,
            'permit' => $req_permit,
            'wall' => $req_wall,
            'skin' => $req_skin,
            'sky' => $req_sky,
            'inputs_json' => $inputs_json_val
        ]);

        // C-3. Automatically set initial_est_amount (total + 10% tax) and initial_est_date
        if ($total_price > 0) {
            $tax = round($total_price * 0.1);
            $grandTotal = $total_price + $tax;
            $stmtInit = $pdo->prepare("UPDATE projects SET initial_est_amount = :amt, initial_est_date = :dt WHERE id = :pid");
            $stmtInit->execute(['amt' => $grandTotal, 'dt' => date('Y-m-d'), 'pid' => $new_project_id]);
        }

        // D. Insert chat message
        if (!empty($estimate_details)) {
            $msg_body = "【自動登録】セルフ見積もり内容:\n\n" . $estimate_details;
            $stmtMsg = $pdo->prepare("
                INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                VALUES (:pid, :sid, 'client_admin', :msg)
            ");
            $stmtMsg->execute([
                'pid' => $new_project_id,
                'sid' => $user_id,
                'msg' => $msg_body
            ]);
        }

        // E. Magic link for new user
        $token = '';
        if ($is_new_user) {
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $stmtMagic = $pdo->prepare("
                INSERT INTO magic_links (user_id, token, expires_at) 
                VALUES (:user_id, :token, :expires_at)
            ");
            $stmtMagic->execute([
                'user_id' => $user_id,
                'token' => $token,
                'expires_at' => $expires_at
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage(), 'code' => 500];
    }

    // F. PDF Generation & Upload (skips in unit tests)
    if (!$isTest) {
        require_once __DIR__ . '/google_drive_client.php';
        require_once __DIR__ . '/estimate_pdf_generator.php';
        
        $temp_pdf_path = null;
        try {
            $temp_pdf_path = generate_estimate_pdf($new_project_id, $pdo);
            $pdf_filename = '御見積書_' . $project_name . '.pdf';
            $pdfDriveId = null;
            
            try {
                $project_folder_id = get_or_create_project_drive_folder($pdo, $new_project_id);
                $pdfDriveId = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);
                if ($temp_pdf_path && file_exists($temp_pdf_path)) {
                    unlink($temp_pdf_path);
                }
            } catch (Exception $driveEx) {
                error_log("Google Driveへの自動見積書保存に失敗しました。ローカル保存にフォールバックします: " . $driveEx->getMessage());
                
                $c_folder = '';
                $p_folder = '';
                $sub_dir = '';
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT p.project_name, u.id as client_id, u.company_name, u.contact_name
                        FROM projects p
                        JOIN users u ON p.client_id = u.id
                        WHERE p.id = :pid
                    ");
                    $stmt->execute(['pid' => $new_project_id]);
                    $fallback_data = $stmt->fetch();
                    
                    if ($fallback_data) {
                        $client_folder_name = !empty($fallback_data['company_name']) ? trim($fallback_data['company_name']) : trim($fallback_data['contact_name']);
                        if (empty($client_folder_name)) {
                            $client_folder_name = "依頼主_ID_" . $fallback_data['client_id'];
                        }
                        $project_folder_name = !empty($fallback_data['project_name']) ? trim($fallback_data['project_name']) : "案件_ID_" . $new_project_id;
                        
                        $c_folder = sanitize_local_folder_name($client_folder_name);
                        $p_folder = sanitize_local_folder_name($project_folder_name);
                        
                        if ($c_folder !== '' && $p_folder !== '') {
                            $sub_dir = '/' . $c_folder . '/' . $p_folder;
                        }
                    }
                } catch (Exception $db_ex) {
                    error_log("Failed to fetch project info for estimate local fallback path: " . $db_ex->getMessage());
                }

                $upload_dir = __DIR__ . '/uploads' . $sub_dir;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $unique_name = time() . '_' . uniqid() . '_' . $pdf_filename;
                $dest_path = $upload_dir . '/' . $unique_name;
                $relative_path = 'uploads' . $sub_dir . '/' . $unique_name;
                
                if ($temp_pdf_path && copy($temp_pdf_path, $dest_path)) {
                    $pdfDriveId = $relative_path;
                    try {
                        $stmtFile = $pdo->prepare("
                            INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                            VALUES (:pid, 'pdf_estimate', :name, :drive_id, 1, 1)
                        ");
                        $stmtFile->execute([
                            'pid'      => $new_project_id,
                            'name'     => $pdf_filename,
                            'drive_id' => $pdfDriveId
                        ]);
                    } catch (Exception $fe) {
                        error_log("Failed to insert local estimate file metadata: " . $fe->getMessage());
                    }
                }
                
                if ($temp_pdf_path && file_exists($temp_pdf_path)) {
                    unlink($temp_pdf_path);
                }
            }
            
            // estimates に pdf_drive_file_id を更新
            if ($pdfDriveId) {
                $stmtUpdateEst = $pdo->prepare("UPDATE estimates SET pdf_drive_file_id = :did WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
                $stmtUpdateEst->execute(['did' => $pdfDriveId, 'pid' => $new_project_id]);
            }
            
        } catch (Exception $pdfEx) {
            error_log("PDF generation or sync completely failed: " . $pdfEx->getMessage());
        }
    }

    // 4. Send Emails (skips if in unit test environment)
    if (!$isTest) {
        $app_url = '';
        if (file_exists(__DIR__ . '/.env')) {
            $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, '=') !== false) {
                    list($name, $value) = explode('=', $line, 2);
                    if (trim($name) === 'APP_URL') {
                        $app_url = trim(trim($value), '"\'');
                        break;
                    }
                }
            }
        }
        if (empty($app_url)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $app_url = "{$protocol}://{$host}";
        }
        $app_url = rtrim($app_url, '/');

        if ($is_new_user) {
            $login_url = "{$app_url}/index.php?token={$token}";
            $subject = "【木造住宅設計サポート】アカウント作成および見積り受付完了のお知らせ";
            $body = "{$company_name}\n{$contact_name} 様\n\n";
            $body .= "木造住宅設計サポートをご利用いただきありがとうございます。\n";
            $body .= "ホームページより、セルフ見積もりのご依頼をいただき、お客様用のポータルアカウントを作成いたしました。\n\n";
            $body .= "セルフ見積もり結果と今後の流れについてご案内いたします。\n\n";
            $body .= "■ 1. セルフ見積もりが示す内容について\n";
            $body .= "今回のセルフ見積もりで算出された金額は「概算見積もり」となります。\n";
            $body .= "ポータルにてご提出いただく設計図書を確認した上で、弊社（管理者）にて正式な見積書（本見積もり）を発行させていただきます。\n\n";
            $body .= "■ 2. 次のステップ（正式な設計依頼について）\n";
            $body .= "以下のURLからポータルにログインしていただき、次のステップへお進みください。\n";
            $body .= " 1) ポータル内での構造仕様（木材の種類等）の入力\n";
            $body .= " 2) 必要図書（意匠図CADや確認申請書等）のアップロード\n";
            $body .= "上記を入力の上、ポータル上の「設計開始を依頼する」ボタンを押していただくことで、正式な設計依頼が完了し、弊社での計算作業及びスケジュールが確定します。\n\n";
            $body .= "■ 3. ポータル上での取引について\n";
            $body .= "弊社とのすべてのお取引（図面の受け渡し、チャットでの質疑応答、スケジュール管理、請求書・領収書の発行など）は、この専用ポータルで行います。メールや電話の履歴が混ざることなく、一元管理が可能です。\n\n";
            $body .= "▼ポータル初回ログインURL（送信から24時間有効）\n";
            $body .= "{$login_url}\n\n";
            $body .= "※2回目以降のログインは、ポータルのログイン画面（ {$app_url}/login.php ）よりメールアドレスを入力して再発行いただけます。ブックマークをお勧めいたします。\n\n";
            $body .= "▼ポータル操作マニュアル（ログインなしでご覧いただけます）\n";
            $body .= "{$app_url}/manual_client.php\n\n";
            $body .= "何かご不明な点がございましたら、ポータル内のチャット、または事務局までお気軽にお問い合わせください。\n\n";
            $body .= "------\n";
            $body .= "木造住宅設計サポート 事務局\n";
            $body .= "URL: https://thanks.work\n";
            $body .= "Email: info@thanks.work\n";
            
            sendSystemEmail($email, $subject, $body);
        } else {
            $subject = "【木造住宅設計サポート】見積り受付完了のお知らせ";
            $body = "{$company_name}\n{$contact_name} 様\n\n";
            $body .= "いつもお世話になっております。木造住宅設計サポートです。\n";
            $body .= "セルフ見積もりからの新しいお見積り依頼を受け付けました。\n\n";
            $body .= "セルフ見積もり結果と今後の流れについてご案内いたします。\n\n";
            $body .= "■ 1. セルフ見積もりが示す内容について\n";
            $body .= "今回のセルフ見積もりで算出された金額は「概算見積もり」となります。\n";
            $body .= "ポータル上でご提出いただく設計図書等を確認した上で、弊社（管理者）にて正式な見積書（本見積もり）を発行させていただきます。\n\n";
            $body .= "■ 2. 次のステップ（正式な設計依頼について）\n";
            $body .= "ポータルにログインしていただき、対象案件のページから次のステップへお進みください。\n";
            $body .= " 1) ポータル内での構造仕様（木材の種類等）の入力\n";
            $body .= " 2) 必要図書（意匠図CADや確認申請書等）のアップロード\n";
            $body .= "上記を入力の上、「設計開始を依頼する」ボタンを押していただくことで、正式な設計依頼が完了し、弊社での計算作業及びスケジュールが確定します。\n\n";
            $body .= "■ 3. ポータル上での取引について\n";
            $body .= "図面のアップロード、チャット、進捗スケジュールの確認、請求書等のやり取りは、すべて本ポータル上で一元管理いたします。\n\n";
            $body .= "▼ポータルログイン画面\n";
            $body .= "{$app_url}/login.php\n";
            $body .= "（ログイン期限が切れている場合は、ログイン画面より再発行メールをご請求ください）\n\n";
            $body .= "▼ポータル操作マニュアル\n";
            $body .= "{$app_url}/manual_client.php\n\n";
            $body .= "------\n";
            $body .= "木造住宅設計サポート 事務局\n";
            $body .= "URL: https://thanks.work\n";
            $body .= "Email: info@thanks.work\n";
            
            sendSystemEmail($email, $subject, $body);
        }

        // Notify admin
        $admin_subject = "【木造住宅設計サポート】新規セルフ見積り依頼（自動登録）のお知らせ";
        $admin_body = "木造住宅設計サポート管理者 様\n\n";
        $admin_body .= "ホームページのセルフ見積もりフォームより、新規の依頼が自動登録されました。\n\n";
        $admin_body .= "■ 依頼者情報\n";
        $admin_body .= "会社名: {$company_name}\n";
        $admin_body .= "担当者名: {$contact_name}\n";
        $admin_body .= "メールアドレス: {$email}\n";
        $admin_body .= "電話番号: {$phone_number}\n\n";
        $admin_body .= "■ 案件情報\n";
        $admin_body .= "物件名: {$project_name}\n";
        $admin_body .= "登録ステータス: 見積依頼 (quote_req)\n";
        $admin_body .= "計算種別オプション: \n";
        $admin_body .= ($req_permit ? "  - 許容応力度設計\n" : "");
        $admin_body .= ($req_wall ? "  - 性能表示壁量計算\n" : "");
        $admin_body .= ($req_skin ? "  - 外皮計算\n" : "");
        $admin_body .= ($req_sky ? "  - 天空率計算\n" : "");
        $admin_body .= ($req_opt_kisohari ? "  - 基礎・横架材許容応力度\n" : "");
        $admin_body .= "\n";
        if (!empty($estimate_details)) {
            $admin_body .= "■ セルフ見積り詳細内容\n";
            $admin_body .= "{$estimate_details}\n\n";
        }
        $admin_body .= "▼管理画面で詳細を確認・見積もりを作成してください\n";
        $admin_body .= "{$app_url}/admin_estimates.php\n";

        $admin_emails = getAdminNotificationEmails($pdo);
        if (empty($admin_emails)) {
            $admin_emails = ['info@thanks.work'];
        }
        foreach ($admin_emails as $admin_email) {
            sendSystemEmail($admin_email, $admin_subject, $admin_body);
        }
    }

    return [
        'success' => true,
        'message' => 'Self estimate successfully processed',
        'project_id' => $new_project_id,
        'is_new_user' => $is_new_user,
        'token' => $token,
        'code' => 200
    ];
}

// Normal HTTP Execution flow
if (!defined('PHPUNIT_RUNNING')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
        exit;
    }

    $raw_input = file_get_contents('php://input');
    $json_data = json_decode($raw_input, true);
    $data = !empty($json_data) ? $json_data : $_POST;

    global $pdo;
    $result = handleSelfEstimate($data, $pdo, false);
    
    if (!$result['success']) {
        http_response_code($result['code'] ?? 400);
    }
    
    // Do not leak token in production response
    unset($result['token']);
    unset($result['code']);
    
    echo json_encode($result);
    exit;
}
