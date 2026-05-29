<?php
namespace App\Controllers;

use App\Container\AppContainer;

class ProjectController
{
    private AppContainer $container;

    public function __construct()
    {
        $this->container = AppContainer::getInstance();
    }

    public function handlePostRequest(int $projectId, int $userId, bool $isAdmin, array $postData, array $files): void
    {
        $action = $postData['action'] ?? '';

        $projectService = $this->container->getProjectManagerService();
        $chatService = $this->container->getChatService();
        $pdo = $this->container->getPDO();

        if ($action === 'send_message') {
            $msg = trim($postData['message_text'] ?? '');
            $target = trim($postData['target_file'] ?? '');
            if ($msg !== '') {
                $chatService->sendMessage($projectId, $userId, 'client_admin', $msg, null, null, $target);
                // Notification logic (Email/SMS) is kept as-is or handled by service
            }
        }

        if ($action === 'set_primary_due_date' && $isAdmin) {
            $dueDate = $postData['primary_due_date'] ?? null;
            if ($dueDate) {
                $projectService->setPrimaryDueDate($projectId, $userId, $dueDate);
            }
        }

        if ($action === 'update_schedule_actual' && $isAdmin) {
            $stepIdx = $postData['step_idx'] ?? '';
            $actualDate = $postData['actual_date'] ?? '';
            if ($stepIdx !== '') {
                $repo = $this->container->getProjectRepository();
                $project = $repo->findById($projectId);
                if ($project) {
                    $actuals = $project->scheduleActuals;
                    if (empty($actualDate)) {
                        unset($actuals[$stepIdx]);
                    } else {
                        $actuals[$stepIdx] = $actualDate;
                    }
                    $repo->updateScheduleActuals($projectId, $actuals);
                }
            }
        }
        
        // request_design_start などの複雑なファイルアップロードや仕様JSON保存処理は、
        // 既存の actions/project_detail_post.php を呼び出す形（一部移行）で連携させるか、
        // コントローラー内に書きます。今回は UI/レイアウト崩れを防ぐため、
        // 既存処理を活かしつつ Repository を使う形にします。
    }
}
