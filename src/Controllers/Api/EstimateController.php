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
            // PDF Generation and Drive upload logic
            require_once __DIR__ . '/../../../../google_drive_client.php';
            require_once __DIR__ . '/../../../../estimate_pdf_generator.php';
            require_once __DIR__ . '/../../../../db_connect.php'; // get $pdo

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

            $project_folder_id = get_or_create_project_drive_folder($pdo, $projectId);
            
            // PDFを一時生成
            $temp_pdf_path = generate_estimate_pdf($projectId, $pdo);
            
            // 案件名を取得してファイル名を設定
            $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = :pid");
            $stmtProj->execute(['pid' => $projectId]);
            $proj_info = $stmtProj->fetch();
            $proj_name = $proj_info ? $proj_info['project_name'] : $projectId;
            $pdf_filename = '御見積書_' . $proj_name . '.pdf';
            
            // Google Driveにアップロード
            $pdfDriveId = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);
            
            // 一時生成したローカルのPDFを削除
            if (file_exists($temp_pdf_path)) {
                unlink($temp_pdf_path);
            }

            $service = $this->container->getEstimateCalculatorService();
            $success = $service->saveEstimate((int)$projectId, $_POST, $pdfDriveId);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => $success,
                'drive_file_id' => $pdfDriveId
            ]);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
