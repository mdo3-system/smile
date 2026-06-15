<?php
namespace App\Controllers\Api;

use App\Container\AppContainer;

class ChatController
{
    private AppContainer $container;

    public function __construct()
    {
        $this->container = AppContainer::getInstance();
    }

    public function getMessages(): void
    {
        $projectId = $_GET['project_id'] ?? null;
        $sinceId = $_GET['since_id'] ?? 0;
        
        // タブに基づく threadType の決定
        $tab = $_GET['tab'] ?? '';
        if ($tab === 'permit' || $tab === '') {
            $threadType = ['client_admin', 'client_admin_permit'];
        } else {
            $threadType = 'client_admin_' . $tab;
        }

        if (!$projectId) {
            echo json_encode([]);
            return;
        }

        $service = $this->container->getChatService();
        $messages = $service->getMessages((int)$projectId, $threadType, (int)$sinceId);

        $result = [];
        foreach ($messages as $m) {
            $result[] = [
                'id' => $m->id,
                'sender_id' => $m->senderId,
                'message_text' => $m->messageText,
                'file_path' => $m->filePath,
                'file_type' => $m->fileType,
                'created_at' => $m->createdAt,
                'sender_role' => $m->senderRole
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
