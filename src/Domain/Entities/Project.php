<?php
namespace App\Domain\Entities;

class Project
{
    public ?int $id;
    public string $projectName;
    public int $clientId;
    public string $status;
    public ?string $primaryDueDate;
    public array $scheduleActuals;
    public string $uploadMode;
    public bool $reqPermit;
    public bool $reqWall;
    public bool $reqSkin;
    public bool $reqSky;
    public bool $reqOptKisohari;
    public ?string $createdAt;

    public function __construct(
        ?int $id = null,
        string $projectName = '',
        int $clientId = 0,
        string $status = 'quote_req',
        ?string $primaryDueDate = null,
        array $scheduleActuals = [],
        string $uploadMode = 'individual',
        bool $reqPermit = false,
        bool $reqWall = false,
        bool $reqSkin = false,
        bool $reqSky = false,
        bool $reqOptKisohari = false,
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->projectName = $projectName;
        $this->clientId = $clientId;
        $this->status = $status;
        $this->primaryDueDate = $primaryDueDate;
        $this->scheduleActuals = $scheduleActuals;
        $this->uploadMode = $uploadMode;
        $this->reqPermit = $reqPermit;
        $this->reqWall = $reqWall;
        $this->reqSkin = $reqSkin;
        $this->reqSky = $reqSky;
        $this->reqOptKisohari = $reqOptKisohari;
        $this->createdAt = $createdAt;
    }

    public static function fromArray(array $data): self
    {
        $scheduleActuals = [];
        if (!empty($data['schedule_actuals'])) {
            $scheduleActuals = json_decode($data['schedule_actuals'], true) ?: [];
        }

        return new self(
            $data['id'] ?? null,
            $data['project_name'] ?? '',
            $data['client_id'] ?? 0,
            $data['status'] ?? 'quote_req',
            $data['primary_due_date'] ?? null,
            $scheduleActuals,
            $data['upload_mode'] ?? 'individual',
            (bool)($data['req_permit'] ?? false),
            (bool)($data['req_wall'] ?? false),
            (bool)($data['req_skin'] ?? false),
            (bool)($data['req_sky'] ?? false),
            (bool)($data['req_opt_kisohari'] ?? false),
            $data['created_at'] ?? null
        );
    }
}
