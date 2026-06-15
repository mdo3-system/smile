<?php
namespace App\Services;

use App\Repositories\Interfaces\MessageRepositoryInterface;
use App\Domain\Entities\Message;

class ChatService
{
    private MessageRepositoryInterface $messageRepo;

    public function __construct(MessageRepositoryInterface $messageRepo)
    {
        $this->messageRepo = $messageRepo;
    }

    /**
     * @param int $projectId
     * @param string|array $threadType
     * @param int $sinceId
     * @return array
     */
    public function getMessages(int $projectId, $threadType, int $sinceId): array
    {
        return $this->messageRepo->findByProjectIdAndThread($projectId, $threadType, $sinceId);
    }

    public function sendMessage(int $projectId, int $senderId, string $threadType, string $text, ?string $filePath = null, ?string $fileType = null, ?string $targetFile = null): bool
    {
        if ($targetFile) {
            $text = "【" . $targetFile . " について】\n" . $text;
        }

        $message = new Message(null, $projectId, $senderId, $threadType, $text, $filePath, $fileType);
        return $this->messageRepo->save($message);
    }
}
