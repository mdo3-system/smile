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
        $threadType = $_GET['thread_type'] ?? 'client_admin';

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
                'created_at' => $m->createdAt
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
