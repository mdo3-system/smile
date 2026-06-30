<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

class SubcontractorAccessTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        // インメモリのSQLite DBを作成
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_name TEXT NOT NULL,
                role TEXT NOT NULL,
                parent_id INTEGER NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE subcontractor_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                subcontractor_id INTEGER NOT NULL,
                task_title TEXT NOT NULL,
                status TEXT NOT NULL
            );
        ");

        // テスト用データ追加
        // 親（ID: 3）と子（ID: 4, parent_id: 3）
        $this->pdo->exec("INSERT INTO users (id, contact_name, role, parent_id) VALUES (3, '代表業者', 'subcontractor', NULL);");
        $this->pdo->exec("INSERT INTO users (id, contact_name, role, parent_id) VALUES (4, 'スタッフ業者', 'subcontractor', 3);");

        // 親（ID: 3）宛ての発注タスク
        $this->pdo->exec("INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, status) VALUES (1, 3, '意匠図作図', 'requested');");
    }

    public function testSubcontractorChildAccessesParentOrder(): void
    {
        // ログインユーザーは子アカウント（スタッフID: 4）
        $userId = 4;

        // 代表者（親）IDを特定する
        $stmtParent = $this->pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
        $stmtParent->execute(['id' => $userId]);
        $p_id = $stmtParent->fetchColumn();
        
        $subCompanyId = $p_id ? (int)$p_id : $userId;

        // 親IDが正しく 3 と特定できているか検証
        $this->assertEquals(3, $subCompanyId);

        // 基準ID（親ID）で発注タスクを取得できるか検証
        $stmt = $this->pdo->prepare("
            SELECT * FROM subcontractor_orders 
            WHERE subcontractor_id = :sub_id AND project_id = :pid
        ");
        $stmt->execute(['sub_id' => $subCompanyId, 'pid' => 1]);
        $orders = $stmt->fetchAll();

        // 正常に1件の発注を取得できること
        $this->assertCount(1, $orders);
        $this->assertEquals('意匠図作図', $orders[0]['task_title']);
    }
}
