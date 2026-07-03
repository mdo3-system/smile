<?php
// tests/Unit/ChatNotificationTest.php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

class ChatNotificationTest extends TestCase {
    private $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT,
                client_id INTEGER
            )
        ");

        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                role TEXT,
                parent_id INTEGER,
                email_notification_enabled INTEGER DEFAULT 1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE subcontractor_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER,
                subcontractor_id INTEGER
            )
        ");

        require_once __DIR__ . '/../../functions.php';
    }

    public function testGetCompanyNotificationEmails() {
        // テストユーザーの挿入
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (10, 'parent@client.com', 'client', NULL, 1)");
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (11, 'child1@client.com', 'client', 10, 1)");
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (12, 'child2@client.com', 'client', 10, 0)"); // 通知オフ

        $emails = getCompanyNotificationEmails(11, $this->pdo);

        $this->assertCount(2, $emails);
        $this->assertContains('parent@client.com', $emails);
        $this->assertContains('child1@client.com', $emails);
        $this->assertNotContains('child2@client.com', $emails);
    }

    public function testGetAdminNotificationEmails() {
        $this->pdo->exec("INSERT INTO users (email, role, email_notification_enabled) VALUES ('admin1@test.com', 'admin', 1)");
        $this->pdo->exec("INSERT INTO users (email, role, email_notification_enabled) VALUES ('admin2@test.com', 'admin', 0)");

        $emails = getAdminNotificationEmails($this->pdo);

        $this->assertCount(1, $emails);
        $this->assertContains('admin1@test.com', $emails);
    }
}
