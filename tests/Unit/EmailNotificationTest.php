<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

class EmailNotificationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_name TEXT NOT NULL,
                contact_name TEXT NOT NULL,
                email TEXT NOT NULL,
                role TEXT NOT NULL,
                email_notifications INTEGER DEFAULT 1
            );
        ");

        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                project_name TEXT NOT NULL,
                status TEXT NOT NULL
            );
        ");

        // テスト用データ追加
        $this->pdo->exec("
            INSERT INTO users (company_name, contact_name, email, role, email_notifications) 
            VALUES ('テストクライアント', '山田太郎', 'yamada@example.com', 'client', 1);
        ");

        $this->pdo->exec("
            INSERT INTO users (company_name, contact_name, email, role, email_notifications) 
            VALUES ('通知オフクライアント', '鈴木二郎', 'suzuki@example.com', 'client', 0);
        ");

        $this->pdo->exec("
            INSERT INTO projects (client_id, project_name, status) 
            VALUES (1, '通知ON案件', 'primary_prep');
        ");

        $this->pdo->exec("
            INSERT INTO projects (client_id, project_name, status) 
            VALUES (2, '通知OFF案件', 'primary_prep');
        ");
    }

    public function testEmailNotificationPreferenceSaving(): void
    {
        // 更新処理のシミュレーション
        $targetUid = 1;
        $emailNotifications = 0; // 通知をオフにする

        $stmtUser = $this->pdo->prepare("
            UPDATE users SET 
                email_notifications = :email_notifications
            WHERE id = :uid
        ");
        $stmtUser->execute([
            'email_notifications' => $emailNotifications,
            'uid' => $targetUid
        ]);

        // 確認
        $stmtCheck = $this->pdo->prepare("SELECT email_notifications FROM users WHERE id = :uid");
        $stmtCheck->execute(['uid' => $targetUid]);
        $val = $stmtCheck->fetchColumn();

        $this->assertEquals(0, $val);
    }

    public function testChatNotificationConditionOn(): void
    {
        // 案件1（通知ON）について、チャット時の通知先メールアドレス取得を検証
        $projectId = 1;

        $stmtEmail = $this->pdo->prepare("
            SELECT u.email, u.email_notifications FROM projects p JOIN users u ON p.client_id = u.id WHERE p.id = :pid
        ");
        $stmtEmail->execute(['pid' => $projectId]);
        $user_info = $stmtEmail->fetch();

        $to_email = $user_info['email'] ?? '';
        $notifications_enabled = (int)($user_info['email_notifications'] ?? 1);

        $this->assertEquals('yamada@example.com', $to_email);
        $this->assertEquals(1, $notifications_enabled);
    }

    public function testChatNotificationConditionOff(): void
    {
        // 案件2（通知OFF）について、チャット時の通知先メールアドレス取得を検証
        $projectId = 2;

        $stmtEmail = $this->pdo->prepare("
            SELECT u.email, u.email_notifications FROM projects p JOIN users u ON p.client_id = u.id WHERE p.id = :pid
        ");
        $stmtEmail->execute(['pid' => $projectId]);
        $user_info = $stmtEmail->fetch();

        $to_email = $user_info['email'] ?? '';
        $notifications_enabled = (int)($user_info['email_notifications'] ?? 1);

        $this->assertEquals('suzuki@example.com', $to_email);
        $this->assertEquals(0, $notifications_enabled);
    }
}
