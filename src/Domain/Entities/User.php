<?php
namespace App\Domain\Entities;

class User
{
    public ?int $id;
    public string $companyName;
    public string $contactName;
    public string $role;
    public ?string $phoneNumber;
    public ?string $email;
    public ?string $createdAt;

    public function __construct(
        ?int $id = null,
        string $companyName = '',
        string $contactName = '',
        string $role = 'client',
        ?string $phoneNumber = null,
        ?string $email = null,
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->companyName = $companyName;
        $this->contactName = $contactName;
        $this->role = $role;
        $this->phoneNumber = $phoneNumber;
        $this->email = $email;
        $this->createdAt = $createdAt;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            $data['company_name'] ?? '',
            $data['contact_name'] ?? '',
            $data['role'] ?? 'client',
            $data['phone_number'] ?? null,
            $data['email'] ?? null,
            $data['created_at'] ?? null
        );
    }
}
