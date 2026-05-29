<?php
namespace App\Repositories\Interfaces;

use App\Domain\Entities\Estimate;

interface EstimateRepositoryInterface
{
    public function findByProjectId(int $projectId): ?Estimate;
    public function save(Estimate $estimate): bool;
}
