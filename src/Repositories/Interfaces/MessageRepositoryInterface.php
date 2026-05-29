<?php
namespace App\Repositories\Interfaces;

use App\Domain\Entities\Message;

interface MessageRepositoryInterface
{
    public function findByProjectIdAndThread(int $projectId, string $threadType, int $sinceId = 0): array;
    public function save(Message $message): bool;
}
