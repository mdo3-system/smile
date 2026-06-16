<?php
namespace App\Domain\Entities;

class Estimate
{
    public ?int $id;
    public int $projectId;
    public int $basePrice;
    public float $area;
    public int $gradePrice;
    public int $totalPrice;
    public array $note;
    public ?string $pdfDriveFileId;
    public bool $reqPermit;
    public bool $reqWall;
    public bool $reqSkin;
    public bool $reqSky;
    public ?string $inputsJson;
    public ?string $createdAt;

    public function __construct(
        ?int $id = null,
        int $projectId = 0,
        int $basePrice = 0,
        float $area = 0.0,
        int $gradePrice = 0,
        int $totalPrice = 0,
        array $note = [],
        ?string $pdfDriveFileId = null,
        bool $reqPermit = false,
        bool $reqWall = false,
        bool $reqSkin = false,
        bool $reqSky = false,
        ?string $inputsJson = null,
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->projectId = $projectId;
        $this->basePrice = $basePrice;
        $this->area = $area;
        $this->gradePrice = $gradePrice;
        $this->totalPrice = $totalPrice;
        $this->note = $note;
        $this->pdfDriveFileId = $pdfDriveFileId;
        $this->reqPermit = $reqPermit;
        $this->reqWall = $reqWall;
        $this->reqSkin = $reqSkin;
        $this->reqSky = $reqSky;
        $this->inputsJson = $inputsJson;
        $this->createdAt = $createdAt;
    }

    public static function fromArray(array $data): self
    {
        $note = [];
        if (!empty($data['note'])) {
            $note = json_decode($data['note'], true) ?: [];
        }

        return new self(
            $data['id'] ?? null,
            $data['project_id'] ?? 0,
            (int)($data['base_price'] ?? 0),
            (float)($data['area'] ?? 0.0),
            (int)($data['grade_price'] ?? 0),
            (int)($data['total_price'] ?? 0),
            $note,
            $data['pdf_drive_file_id'] ?? null,
            (bool)($data['req_permit'] ?? false),
            (bool)($data['req_wall'] ?? false),
            (bool)($data['req_skin'] ?? false),
            (bool)($data['req_sky'] ?? false),
            $data['inputs_json'] ?? null,
            $data['created_at'] ?? null
        );
    }
}
