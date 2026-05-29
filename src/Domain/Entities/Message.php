<?php
namespace App\Domain\Entities;

class Message
{
    public ?int $id;
    public int $projectId;
    public int $senderId;
    public string $threadType;
    public string $messageText;
    public ?string $filePath;
    public ?string $fileType;
    public ?string $createdAt;

    public function __construct(
        ?int $id = null,
        int $projectId = 0,
        int $senderId = 0,
        string $threadType = 'client_admin',
        string $messageText = '',
        ?string $filePath = null,
        ?string $fileType = null,
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->projectId = $projectId;
        $this->senderId = $senderId;
        $this->threadType = $threadType;
        $this->messageText = $messageText;
        $this->filePath = $filePath;
        $this->fileType = $fileType;
        $this->createdAt = $createdAt;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            $data['project_id'] ?? 0,
            $data['sender_id'] ?? 0,
            $data['thread_type'] ?? 'client_admin',
            $data['message_text'] ?? '',
            $data['file_path'] ?? null,
            $data['file_type'] ?? null,
            $data['created_at'] ?? null
        );
    }
}
