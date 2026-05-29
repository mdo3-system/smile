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

        // PDF Generation and Drive upload logic would typically be injected via a service.
        // For now, we will assume it's done elsewhere or returned here.
        $pdfDriveId = null; 
        
        $service = $this->container->getEstimateCalculatorService();
        $success = $service->saveEstimate((int)$projectId, $_POST, $pdfDriveId);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'drive_file_id' => $pdfDriveId
        ]);
    }
}
