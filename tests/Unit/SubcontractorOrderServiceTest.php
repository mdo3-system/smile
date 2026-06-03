<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Services\SubcontractorOrderService;

class SubcontractorOrderServiceTest extends TestCase
{
    private PDO $pdo;
    private SubcontractorOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // インメモリのSQLite DBを作成
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // SQLiteにはNOW()が存在しないため、UDFとして追加
        $this->pdo->sqliteCreateFunction('NOW', function() {
            return date('Y-m-d H:i:s');
        });

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE subcontractor_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                subcontractor_id INTEGER NOT NULL,
                task_title TEXT NOT NULL,
                order_amount INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'requested',
                expected_delivery_date TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                thread_type TEXT NOT NULL,
                message_text TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // テスト用データ追加
        $stmt = $this->pdo->prepare("
            INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status) 
            VALUES (10, 3, 'テスト発注', 50000, 'requested')
        ");
        $stmt->execute();

        $this->service = new SubcontractorOrderService($this->pdo);
    }

    public function testAcceptOrderSuccess(): void
    {
        $orderId = 1;
        $subcontractorId = 3;
        $expectedDeliveryDate = '2026-06-15';

        $result = $this->service->acceptOrder($orderId, $subcontractorId, $expectedDeliveryDate);
        $this->assertTrue($result);

        // 状態検証 (subcontractor_orders)
        $stmt = $this->pdo->prepare("SELECT * FROM subcontractor_orders WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('accepted', $order['status']);
        $this->assertEquals($expectedDeliveryDate, $order['expected_delivery_date']);
        $this->assertNotNull($order['updated_at']);

        // チャットメッセージ検証 (messages)
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($msg);
        $this->assertEquals(10, $msg['project_id']);
        $this->assertEquals($subcontractorId, $msg['sender_id']);
        $this->assertEquals('sub_admin', $msg['thread_type']);
        $this->assertStringContainsString('発注を承諾しました', $msg['message_text']);
        $this->assertStringContainsString('2026年06月15日', $msg['message_text']);
    }

    public function testRejectOrderSuccess(): void
    {
        $orderId = 1;
        $subcontractorId = 3;

        $result = $this->service->rejectOrder($orderId, $subcontractorId);
        $this->assertTrue($result);

        // 状態検証 (subcontractor_orders)
        $stmt = $this->pdo->prepare("SELECT * FROM subcontractor_orders WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('rejected', $order['status']);
        $this->assertNotNull($order['updated_at']);

        // チャットメッセージ検証 (messages)
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($msg);
        $this->assertEquals(10, $msg['project_id']);
        $this->assertEquals($subcontractorId, $msg['sender_id']);
        $this->assertEquals('sub_admin', $msg['thread_type']);
        $this->assertStringContainsString('発注を辞退（拒否）しました。', $msg['message_text']);
    }
}
