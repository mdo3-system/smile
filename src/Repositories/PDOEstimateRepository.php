<?php
namespace App\Repositories;

use PDO;
use App\Domain\Entities\Estimate;
use App\Repositories\Interfaces\EstimateRepositoryInterface;

class PDOEstimateRepository implements EstimateRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByProjectId(int $projectId): ?Estimate
    {
        $stmt = $this->pdo->prepare("SELECT * FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
        $stmt->execute(['pid' => $projectId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return Estimate::fromArray($row);
    }

    public function save(Estimate $estimate): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO estimates (project_id, base_price, area, grade_price, total_price, note, pdf_drive_file_id, req_permit, req_wall, req_skin, req_sky)
            VALUES (:pid, :base, :area, :grade, :total, :note, :pdf, :permit, :wall, :skin, :sky)
        ");
        $success = $stmt->execute([
            'pid' => $estimate->projectId,
            'base' => $estimate->basePrice,
            'area' => $estimate->area,
            'grade' => $estimate->gradePrice,
            'total' => $estimate->totalPrice,
            'note' => json_encode($estimate->note, JSON_UNESCAPED_UNICODE),
            'pdf' => $estimate->pdfDriveFileId,
            'permit' => (int)$estimate->reqPermit,
            'wall' => (int)$estimate->reqWall,
            'skin' => (int)$estimate->reqSkin,
            'sky' => (int)$estimate->reqSky
        ]);
        if ($success) {
            $estimate->id = (int)$this->pdo->lastInsertId();
        }
        return $success;
    }
}
