<?php
namespace App\Repositories;

use PDO;
use App\Domain\Entities\Message;
use App\Repositories\Interfaces\MessageRepositoryInterface;

class PDOMessageRepository implements MessageRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByProjectIdAndThread(int $projectId, string $threadType, int $sinceId = 0): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE project_id = :pid AND thread_type = :thread AND id > :since ORDER BY id ASC");
        $stmt->execute([
            'pid' => $projectId,
            'thread' => $threadType,
            'since' => $sinceId
        ]);
        
        $messages = [];
        while ($row = $stmt->fetch()) {
            $messages[] = Message::fromArray($row);
        }
        return $messages;
    }

    public function save(Message $message): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO messages (project_id, sender_id, thread_type, message_text, file_path, file_type) 
            VALUES (:pid, :sid, :thread, :msg, :path, :type)
        ");
        $success = $stmt->execute([
            'pid' => $message->projectId,
            'sid' => $message->senderId,
            'thread' => $message->threadType,
            'msg' => $message->messageText,
            'path' => $message->filePath,
            'type' => $message->fileType
        ]);
        if ($success) {
            $message->id = (int)$this->pdo->lastInsertId();
        }
        return $success;
    }
}
