<?php
namespace App\Repositories;

use PDO;
use App\Domain\Entities\Project;
use App\Repositories\Interfaces\ProjectRepositoryInterface;

class PDOProjectRepository implements ProjectRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?Project
    {
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return Project::fromArray($row);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->pdo->prepare("UPDATE projects SET status = :status WHERE id = :id");
        return $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function updatePrimaryDueDate(int $id, string $dueDate): bool
    {
        $stmt = $this->pdo->prepare("UPDATE projects SET primary_due_date = :due WHERE id = :id");
        return $stmt->execute(['due' => $dueDate, 'id' => $id]);
    }

    public function updateScheduleActuals(int $id, array $actuals): bool
    {
        $stmt = $this->pdo->prepare("UPDATE projects SET schedule_actuals = :act WHERE id = :id");
        return $stmt->execute(['act' => json_encode($actuals), 'id' => $id]);
    }

    public function updateUploadMode(int $id, string $mode): bool
    {
        $stmt = $this->pdo->prepare("UPDATE projects SET upload_mode = :mode WHERE id = :id");
        return $stmt->execute(['mode' => $mode, 'id' => $id]);
    }
}
