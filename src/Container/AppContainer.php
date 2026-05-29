<?php
namespace App\Container;

use PDO;
use App\Repositories\PDOProjectRepository;
use App\Repositories\PDOUserRepository;
use App\Repositories\PDOMessageRepository;
use App\Repositories\PDOEstimateRepository;
use App\Services\ProjectManagerService;
use App\Services\ChatService;
use App\Services\EstimateCalculatorService;

class AppContainer
{
    private static ?AppContainer $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        // Require the existing db_connect.php which defines $pdo
        require __DIR__ . '/../../db_connect.php';
        /** @var PDO $pdo */
        $this->pdo = $pdo;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    // Repositories
    public function getProjectRepository(): PDOProjectRepository
    {
        return new PDOProjectRepository($this->pdo);
    }

    public function getUserRepository(): PDOUserRepository
    {
        return new PDOUserRepository($this->pdo);
    }

    public function getMessageRepository(): PDOMessageRepository
    {
        return new PDOMessageRepository($this->pdo);
    }

    public function getEstimateRepository(): PDOEstimateRepository
    {
        return new PDOEstimateRepository($this->pdo);
    }

    // Services
    public function getProjectManagerService(): ProjectManagerService
    {
        return new ProjectManagerService(
            $this->getProjectRepository(),
            $this->getMessageRepository()
        );
    }

    public function getChatService(): ChatService
    {
        return new ChatService($this->getMessageRepository());
    }

    public function getEstimateCalculatorService(): EstimateCalculatorService
    {
        return new EstimateCalculatorService($this->getEstimateRepository());
    }
}
