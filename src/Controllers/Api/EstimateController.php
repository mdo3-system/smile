<?php
namespace App\Controllers\Api;

use App\Container\AppContainer;

class EstimateController
{
    private AppContainer $container;

    public function __construct()
    {
        $this->container = AppContainer::getInstance();
    }

    public function save(): void
    {
        $projectId = $_POST['project_id'] ?? null;
        if (!$projectId) {
            echo json_encode(['success' => false, 'error' => 'No project ID']);
            return;
        }

        try {
            $pdo = $this->container->getPDO();
            require_once __DIR__ . '/../../../google_drive_client.php';
            require_once __DIR__ . '/../../../estimate_pdf_generator.php';

            // Ensure DB schema has the correct columns
            try {
                $pdo->exec("ALTER TABLE projects ADD COLUMN drive_folder_id VARCHAR(255) NULL");
            } catch (\Exception $e) { /* ignore */ }
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN drive_folder_id VARCHAR(255) NULL");
            } catch (\Exception $e) { /* ignore */ }
            try {
                $pdo->exec("ALTER TABLE estimates ADD COLUMN pdf_drive_file_id VARCHAR(255) NULL");
            } catch (\Exception $e) { /* ignore */ }
            try {
                $pdo->exec("ALTER TABLE estimates ADD COLUMN inputs_json TEXT NULL");
            } catch (\Exception $e) { /* ignore */ }

            // 0. シミュレーターの入力値 (inputs_json) に基づき、案件の仕様フラグを同期
            $inputs_json = $_POST['inputs_json'] ?? '{}';
            $inputs = json_decode($inputs_json, true) ?: [];

            $_POST['req_permit'] = !empty($inputs['est_active_permit']) ? 1 : 0;
            $_POST['req_wall']   = !empty($inputs['est_active_wall']) ? 1 : 0;
            $_POST['req_skin']   = !empty($inputs['est_active_skin']) ? 1 : 0;
            $_POST['req_sky']    = !empty($inputs['est_active_sky']) ? 1 : 0;
            
            $req_opt_kisohari = 0;
            if (!empty($inputs['est_active_permit'])) {
                $req_opt_kisohari = 1;
            } elseif (!empty($inputs['est_kisohari_wall'])) {
                $req_opt_kisohari = 1;
            } elseif (!empty($inputs['est_opt_kisohari_calc'])) {
                $req_opt_kisohari = 1;
            }
            $_POST['req_opt_kisohari'] = $req_opt_kisohari;

            // projects テーブルの仕様フラグを更新
            $stmtProjUpdate = $pdo->prepare("
                UPDATE projects 
                SET req_permit = :permit, 
                    req_wall = :wall, 
                    req_skin = :skin, 
                    req_sky = :sky, 
                    req_opt_kisohari = :kisohari 
                WHERE id = :pid
            ");
            $stmtProjUpdate->execute([
                'permit'   => $_POST['req_permit'],
                'wall'     => $_POST['req_wall'],
                'skin'     => $_POST['req_skin'],
                'sky'      => $_POST['req_sky'],
                'kisohari' => $_POST['req_opt_kisohari'],
                'pid'      => $projectId
            ]);

            // 新しく追加された仕様に対応するスケジュールの実績同期
            $stmtAct = $pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky, primary_due_date FROM projects WHERE id = :id");
            $stmtAct->execute(['id' => $projectId]);
            $act_row = $stmtAct->fetch(\PDO::FETCH_ASSOC);

            if ($act_row) {
                $base_actuals = json_decode($act_row['schedule_actuals'] ?? '{}', true) ?: [];
                $received_date = $base_actuals[0] ?? date('Y-m-d');
                $due_date = $act_row['primary_due_date'] ?? null;

                $colsToSync = [
                    'req_permit' => ['schedule_actuals'],
                    'req_wall' => ['schedule_actuals_wall'],
                    'req_skin' => ['schedule_actuals_skin'],
                    'req_sky' => ['schedule_actuals_sky']
                ];

                foreach ($colsToSync as $req_key => $cols) {
                    if ($_POST[$req_key] == 1) {
                        foreach ($cols as $col) {
                            $actuals = json_decode($act_row[$col] ?? '{}', true) ?: [];
                            $updated = false;
                            if (empty($actuals[0])) {
                                $actuals[0] = $received_date;
                                $updated = true;
                            }
                            if ($due_date && empty($actuals[1])) {
                                $actuals[1] = $due_date;
                                $updated = true;
                            }
                            if ($updated) {
                                $stmtUpdateAct = $pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                                $stmtUpdateAct->execute(['act' => json_encode($actuals, JSON_FORCE_OBJECT), 'pid' => $projectId]);
                            }
                        }
                    }
                }
            }

            // 1. まず見積もりデータをDBに保存（この時点ではDrive IDは無し）
            $service = $this->container->getEstimateCalculatorService();
            $success = $service->saveEstimate((int)$projectId, $_POST, null);

            if (!$success) {
                throw new \Exception("見積もりデータの保存に失敗しました。");
            }

            // 2. Driveフォルダの確保とPDFの生成 (生成処理内部で最新の見積もりレコードを参照する)
            $temp_pdf_path = generate_estimate_pdf($projectId, $pdo);
            
            // 案件名を取得してファイル名を設定
            $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = :pid");
            $stmtProj->execute(['pid' => $projectId]);
            $proj_info = $stmtProj->fetch();
            $proj_name = $proj_info ? $proj_info['project_name'] : $projectId;
            $pdf_filename = '御見積書_' . $proj_name . '.pdf';
            
            $pdfDriveId = null;
            try {
                $project_folder_id = get_or_create_project_drive_folder($pdo, $projectId);
                // 3. Google Driveにアップロード
                $pdfDriveId = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);
                
                // 一時生成したローカルのPDFを削除
                if (file_exists($temp_pdf_path)) {
                    unlink($temp_pdf_path);
                }
            } catch (\Exception $driveEx) {
                error_log("Google Driveへの見積書保存に失敗しました。ローカル保存にフォールバックします: " . $driveEx->getMessage());
                
                $c_folder = '';
                $p_folder = '';
                $sub_dir = '';
                
                try {
                    // 案件情報と依頼主情報を取得
                    $stmt = $pdo->prepare("
                        SELECT p.project_name, u.id as client_id, u.company_name, u.contact_name
                        FROM projects p
                        JOIN users u ON p.client_id = u.id
                        WHERE p.id = :pid
                    ");
                    $stmt->execute(['pid' => $projectId]);
                    $data = $stmt->fetch();
                    
                    if ($data) {
                        $client_folder_name = !empty($data['company_name']) ? trim($data['company_name']) : trim($data['contact_name']);
                        if (empty($client_folder_name)) {
                            $client_folder_name = "依頼主_ID_" . $data['client_id'];
                        }
                        $project_folder_name = !empty($data['project_name']) ? trim($data['project_name']) : "案件_ID_" . $projectId;
                        
                        $c_folder = sanitize_local_folder_name($client_folder_name);
                        $p_folder = sanitize_local_folder_name($project_folder_name);
                        
                        if ($c_folder !== '' && $p_folder !== '') {
                            $sub_dir = '/' . $c_folder . '/' . $p_folder;
                        }
                    }
                } catch (\Exception $db_ex) {
                    error_log("Failed to fetch project info for estimate local fallback path: " . $db_ex->getMessage());
                }

                $upload_dir = __DIR__ . '/../../../uploads' . $sub_dir;
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $unique_name = time() . '_' . uniqid() . '_' . $pdf_filename;
                $dest_path = $upload_dir . '/' . $unique_name;
                
                $relative_path = 'uploads' . $sub_dir . '/' . $unique_name;
                
                if (copy($temp_pdf_path, $dest_path)) {
                    $pdfDriveId = $relative_path;
                    // 保存したファイルを project_files テーブルにもローカルパスでインサートして、
                    // 依頼主詳細画面等で「見積時の受領図面」の枠などでリンク可能にする
                    // ※ estimates.pdf_drive_file_id に登録すれば印刷用リンクが機能するが、
                    // project_files にも登録しておくことで、後から自動同期機能が uploads/% のパターンで Drive へ吸い上げる対象になります。
                    try {
                        $stmtFile = $pdo->prepare("
                            INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                            VALUES (:pid, 'pdf_estimate', :name, :drive_id, 1, 1)
                        ");
                        $stmtFile->execute([
                            'pid'      => $projectId,
                            'name'     => $pdf_filename,
                            'drive_id' => $pdfDriveId
                        ]);
                    } catch (\Exception $fe) {
                        error_log("Failed to insert local estimate file metadata: " . $fe->getMessage());
                    }
                } else {
                    throw new \Exception("Google Drive保存に失敗し、さらにローカルフォールバック保存にも失敗しました: " . $driveEx->getMessage());
                }
                
                if (file_exists($temp_pdf_path)) {
                    unlink($temp_pdf_path);
                }
            }


            // 4. 保存した最新の見積もりレコードにDrive IDを追記
            $stmtUpdate = $pdo->prepare("UPDATE estimates SET pdf_drive_file_id = :did WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
            $stmtUpdate->execute(['did' => $pdfDriveId, 'pid' => $projectId]);

            // 5. projects.initial_est_amount が未設定(0 or NULL)の場合のみ初期見積額を自動設定
            $stmtCheckInit = $pdo->prepare("SELECT initial_est_amount FROM projects WHERE id = :pid");
            $stmtCheckInit->execute(['pid' => $projectId]);
            $currentInitAmt = $stmtCheckInit->fetchColumn();
            if (empty($currentInitAmt) || (int)$currentInitAmt === 0) {
                $totalPrice = (int)($_POST['total_price'] ?? 0);
                if ($totalPrice > 0) {
                    $tax = round($totalPrice * 0.1);
                    $grandTotal = $totalPrice + $tax;
                    $stmtInit = $pdo->prepare("UPDATE projects SET initial_est_amount = :amt, initial_est_date = :dt WHERE id = :pid");
                    $stmtInit->execute(['amt' => $grandTotal, 'dt' => date('Y-m-d'), 'pid' => $projectId]);
                }
            }

            // is_formal = 1 の場合、本見積額 (formal_est_amount) と本見積日 (formal_est_date) を更新
            $isFormal = isset($_POST['is_formal']) && $_POST['is_formal'] === '1';
            if ($isFormal) {
                $totalPrice = (int)($_POST['total_price'] ?? 0);
                if ($totalPrice > 0) {
                    $tax = round($totalPrice * 0.1);
                    $grandTotal = $totalPrice + $tax;
                    $stmtFormal = $pdo->prepare("UPDATE projects SET formal_est_amount = :amt, formal_est_date = :dt WHERE id = :pid");
                    $stmtFormal->execute(['amt' => $grandTotal, 'dt' => date('Y-m-d'), 'pid' => $projectId]);
                }
            }

            // is_additional = 1 の場合、追加見積額を projects.additional_estimates JSON に追記更新
            $isAdditional = isset($_POST['is_additional']) && $_POST['is_additional'] === '1';
            if ($isAdditional) {
                $totalPrice = (int)($_POST['total_price'] ?? 0);
                if ($totalPrice > 0) {
                    $tax = round($totalPrice * 0.1);
                    $grandTotal = $totalPrice + $tax;
                    
                    // 現在の projects.additional_estimates を取得
                    $stmtGetAdd = $pdo->prepare("SELECT additional_estimates FROM projects WHERE id = :pid");
                    $stmtGetAdd->execute(['pid' => $projectId]);
                    $currentAddJson = $stmtGetAdd->fetchColumn();
                    $addEstimates = json_decode($currentAddJson ?? '[]', true) ?: [];
                    
                    // 新しい追加見積データを追加
                    $addEstimates[] = [
                        'amount' => $grandTotal,
                        'date' => date('Y-m-d'),
                        'note' => 'シミュレーター発行追加見積'
                    ];
                    
                    $newAddJson = json_encode($addEstimates, JSON_UNESCAPED_UNICODE);
                    $stmtUpdateAdd = $pdo->prepare("UPDATE projects SET additional_estimates = :add_json WHERE id = :pid");
                    $stmtUpdateAdd->execute(['add_json' => $newAddJson, 'pid' => $projectId]);
                }
            }

            $debug = ob_get_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $success,
                'drive_file_id' => $pdfDriveId,
                'debug' => $debug
            ]);
        } catch (\Exception $e) {
            $debug = ob_get_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage(), 'debug' => $debug]);
        }
    }
}
