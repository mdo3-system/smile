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

            // 1. まず見積もりデータをDBに保存（この時点ではDrive IDは無し）
            $service = $this->container->getEstimateCalculatorService();
            $success = $service->saveEstimate((int)$projectId, $_POST, null);

            if (!$success) {
                throw new \Exception("見積もりデータの保存に失敗しました。");
            }

            // 2. Driveフォルダの確保とPDFの生成 (生成処理内部で最新の見積もりレコードを参照する)
            $project_folder_id = get_or_create_project_drive_folder($pdo, $projectId);
            $temp_pdf_path = generate_estimate_pdf($projectId, $pdo);
            
            // 案件名を取得してファイル名を設定
            $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = :pid");
            $stmtProj->execute(['pid' => $projectId]);
            $proj_info = $stmtProj->fetch();
            $proj_name = $proj_info ? $proj_info['project_name'] : $projectId;
            $pdf_filename = '御見積書_' . $proj_name . '.pdf';
            
            // 3. Google Driveにアップロード
            $pdfDriveId = upload_to_google_drive_folder($temp_pdf_path, $pdf_filename, 'application/pdf', $project_folder_id);
            
            // 一時生成したローカルのPDFを削除
            if (file_exists($temp_pdf_path)) {
                unlink($temp_pdf_path);
            }

            // 4. 保存した最新の見積もりレコードにDrive IDを追記
            $stmtUpdate = $pdo->prepare("UPDATE estimates SET pdf_drive_file_id = :did WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
            $stmtUpdate->execute(['did' => $pdfDriveId, 'pid' => $projectId]);

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
