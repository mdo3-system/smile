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

        $this->pdo->exec("
            CREATE TABLE user_notification_emails (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                created_at TEXT
            )
        ");

        require_once __DIR__ . '/../../functions.php';
    }

    public function testGetCompanyNotificationEmails() {
        // テストユーザーの挿入
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (10, 'parent@client.com', 'client', NULL, 1)");
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (11, 'child1@client.com', 'client', 10, 1)");
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (12, 'child2@client.com', 'client', 10, 0)"); // 通知オフ

        // 追加メールアドレスの挿入
        $this->pdo->exec("INSERT INTO user_notification_emails (user_id, email) VALUES (10, 'parent_add1@client.com')");
        $this->pdo->exec("INSERT INTO user_notification_emails (user_id, email) VALUES (11, 'child1_add1@client.com')");
        $this->pdo->exec("INSERT INTO user_notification_emails (user_id, email) VALUES (12, 'child2_add1@client.com')"); // 通知OFFユーザーの追加アドレスは抽出されないはず

        $emails = getCompanyNotificationEmails(11, $this->pdo);

        $this->assertCount(4, $emails);
        $this->assertContains('parent@client.com', $emails);
        $this->assertContains('child1@client.com', $emails);
        $this->assertContains('parent_add1@client.com', $emails);
        $this->assertContains('child1_add1@client.com', $emails);
        $this->assertNotContains('child2@client.com', $emails);
        $this->assertNotContains('child2_add1@client.com', $emails);
    }

    public function testGetAdminNotificationEmails() {
        $this->pdo->exec("INSERT INTO users (email, role, email_notification_enabled) VALUES ('admin1@test.com', 'admin', 1)");
        $this->pdo->exec("INSERT INTO users (email, role, email_notification_enabled) VALUES ('admin2@test.com', 'admin', 0)");

        $emails = getAdminNotificationEmails($this->pdo);

        $this->assertCount(1, $emails);
        $this->assertContains('admin1@test.com', $emails);
    }

    public function testSendChatEmailNotificationSmoke() {
        // プロジェクトとユーザーをダミーデータベースに準備
        $this->pdo->exec("INSERT INTO projects (id, project_name, client_id) VALUES (100, 'Test House', 10)");
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (10, 'parent@client.com', 'client', NULL, 1)");
        $this->pdo->exec("INSERT INTO users (id, email, role, parent_id, email_notification_enabled) VALUES (1, 'admin@test.com', 'admin', NULL, 1)");

        // 依頼主 ➔ 管理者スレッド
        $exceptionThrown = false;
        try {
            sendChatEmailNotification(100, 10, 'client', 'client_admin', 'Hello admin!', $this->pdo);
        } catch (\Exception $e) {
            $exceptionThrown = true;
        }
        $this->assertFalse($exceptionThrown, "Notification function should execute without throwing exceptions.");

        // 管理者 ➔ 依頼主スレッド
        try {
            sendChatEmailNotification(100, 1, 'admin', 'client_admin', 'Hello client!', $this->pdo);
        } catch (\Exception $e) {
            $exceptionThrown = true;
        }
        $this->assertFalse($exceptionThrown, "Notification function should execute without throwing exceptions.");
    }
}
