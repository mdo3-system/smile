<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Services\DatabaseBackupService;

class DatabaseBackupServiceTest extends TestCase
{
    private PDO $pdo;
    private DatabaseBackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // インメモリのSQLite DBを作成
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE test_table (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                value INTEGER NULL
            );
        ");

        // テストデータ挿入
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('Apple', 100);");
        $this->pdo->exec("INSERT INTO test_table (name, value) VALUES ('Banana', NULL);");

        $this->service = new DatabaseBackupService($this->pdo);
    }

    public function testExportDatabaseToSqlContainsTableStructureAndData(): void
    {
        $sql = $this->service->exportDatabaseToSql();

        // テーブル構造が含まれているか
        $this->assertStringContainsString('CREATE TABLE test_table', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `test_table`', $sql);

        // 挿入データが含まれているか
        $this->assertStringContainsString('INSERT INTO `test_table`', $sql);
        $this->assertStringContainsString("'Apple'", $sql);
        $this->assertStringContainsString('100', $sql);
        $this->assertStringContainsString("'Banana'", $sql);
        $this->assertStringContainsString('NULL', $sql);
    }
}
