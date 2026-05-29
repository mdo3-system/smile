<?php
namespace App\Repositories\Interfaces;

use App\Domain\Entities\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;
    public function findAllSubcontractors(): array;
}
