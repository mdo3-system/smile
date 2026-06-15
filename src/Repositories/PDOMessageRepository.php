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

    public function findByProjectIdAndThread(int $projectId, $threadType, int $sinceId = 0): array
    {
        $threads = (array)$threadType;
        $placeholders = implode(',', array_fill(0, count($threads), '?'));
        
        $sql = "
            SELECT m.*, u.role as sender_role 
            FROM messages m 
            LEFT JOIN users u ON m.sender_id = u.id 
            WHERE m.project_id = ? AND m.thread_type IN ($placeholders) AND m.id > ? 
            ORDER BY m.id ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $params = array_merge([$projectId], $threads, [$sinceId]);
        $stmt->execute($params);
        
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
