<?php
namespace App\Repositories\Interfaces;

use App\Domain\Entities\Project;

interface ProjectRepositoryInterface
{
    public function findById(int $id): ?Project;
    public function updateStatus(int $id, string $status): bool;
    public function updatePrimaryDueDate(int $id, string $dueDate): bool;
    public function updateScheduleActuals(int $id, array $actuals): bool;
    public function updateUploadMode(int $id, string $mode): bool;
}
