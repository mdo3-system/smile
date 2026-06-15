<?php
namespace App\Repositories\Interfaces;

use App\Domain\Entities\Message;

interface MessageRepositoryInterface
{
    /**
     * @param int $projectId
     * @param string|array $threadType
     * @param int $sinceId
     * @return Message[]
     */
    public function findByProjectIdAndThread(int $projectId, $threadType, int $sinceId = 0): array;
    public function save(Message $message): bool;
}
