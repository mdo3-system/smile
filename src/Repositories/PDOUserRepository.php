<?php
namespace App\Repositories;

use PDO;
use App\Domain\Entities\User;
use App\Repositories\Interfaces\UserRepositoryInterface;

class PDOUserRepository implements UserRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return User::fromArray($row);
    }

    public function findAllSubcontractors(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE role = 'subcontractor'");
        $stmt->execute();
        
        $users = [];
        while ($row = $stmt->fetch()) {
            $users[] = User::fromArray($row);
        }
        return $users;
    }
}
