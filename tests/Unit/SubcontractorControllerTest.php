<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Services\SubcontractorOrderService;
use App\Controllers\SubcontractorController;
use App\Container\AppContainer;

// テスト環境用のダミー関数定義（モック）
if (!function_exists('upload_to_google_drive')) {
    function upload_to_google_drive($tmp, $name, $mime, $projectId, $pdo) {
        return 'mock_drive_file_id_' . md5($name);
    }
}
if (!function_exists('sendChatEmailNotification')) {
    function sendChatEmailNotification($projectId, $userId, $role, $thread, $msg, $pdo) {
        return true;
    }
}

// PHPUNIT_RUNNING定数の定義（コントローラーのリダイレクト回避用）
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

class SubcontractorControllerTest extends TestCase
{
    private PDO $pdo;
    private SubcontractorOrderService $service;
    private SubcontractorController $controller;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // NOW() 関数の UDF 登録
        $this->pdo->sqliteCreateFunction('NOW', function() {
            return date('Y-m-d H:i:s');
        });

        // テスト用テーブルの作成
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL,
                company_name TEXT NOT NULL,
                contact_name TEXT NOT NULL,
                role TEXT NOT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE subcontractor_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                subcontractor_id INTEGER NOT NULL,
                task_title TEXT NOT NULL,
                order_amount INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'requested',
                payment_status TEXT NULL DEFAULT 'unpaid',
                expected_delivery_date TEXT NULL,
                completed_at TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT NOT NULL,
                google_drive_folder_id TEXT NULL,
                drive_folder_id TEXT NULL,
                client_id INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE project_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                subcontractor_order_id INTEGER NULL,
                file_category TEXT NOT NULL,
                file_name TEXT NOT NULL,
                drive_file_id TEXT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                is_latest INTEGER NOT NULL DEFAULT 1,
                is_published_to_sub INTEGER NOT NULL DEFAULT 0
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

        // テスト用初期データの追加
        $this->pdo->exec("
            INSERT INTO users (id, parent_id, company_name, contact_name, role) 
            VALUES (3, NULL, 'テスト会社', 'テスト担当者', 'subcontractor')
        ");

        $this->pdo->exec("
            INSERT INTO projects (id, project_name, google_drive_folder_id, status)
            VALUES (10, 'テストプロジェクト', 'folder_123', 'active')
        ");

        $this->pdo->exec("
            INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status) 
            VALUES (10, 3, '構造図作図', 80000, 'accepted')
        ");

        $this->pdo->exec("
            INSERT INTO project_files (project_id, file_category, file_name, version, is_latest, is_published_to_sub)
            VALUES (10, 'sub_structural_pdf', 'test_old.pdf', 1, 1, 0)
        ");

        $this->service = new SubcontractorOrderService($this->pdo);

        // AppContainer のモックを注入
        $container = AppContainer::getInstance();
        $container->setPDO($this->pdo);

        $this->controller = new SubcontractorController($this->service);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testUpdateOrderDetailsSuccess(): void
    {
        $orderId = 1;
        $title = '更新後のタスク';
        $amount = 95000;
        $completedAt = '2026-07-15';

        $res = $this->service->updateOrderDetails($orderId, $title, $amount, $completedAt);
        $this->assertTrue($res);

        // DB確認
        $stmt = $this->pdo->query("SELECT * FROM subcontractor_orders WHERE id = 1");
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($title, $order['task_title']);
        $this->assertEquals($amount, $order['order_amount']);
        $this->assertEquals($completedAt, $order['completed_at']);
    }

    public function testTogglePublishSubSuccess(): void
    {
        $fileId = 1;
        $projectId = 10;
        $publishVal = 1;

        $res = $this->service->togglePublishSub($fileId, $projectId, $publishVal);
        $this->assertTrue($res);

        // DB確認
        $stmt = $this->pdo->query("SELECT is_published_to_sub FROM project_files WHERE id = 1");
        $isPub = $stmt->fetchColumn();

        $this->assertEquals(1, $isPub);
    }

    public function testDeliverTaskSuccess(): void
    {
        $orderId = 1;
        $projectId = 10;
        $userId = 3;
        $subCompanyId = 3;

        // 実在する一時ファイルを作成
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($tmpFile, 'dummy content');
        $this->tempFiles[] = $tmpFile;

        // 疑似 $_FILES
        $files = [
            'structural_pdf' => [
                'name' => 'new_structure.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 1024
            ]
        ];

        $res = $this->service->deliverTask($orderId, $projectId, $userId, $subCompanyId, $files, false, 'struct', 'subcontractor');
        $this->assertTrue($res);

        // DB確認: subcontractor_orders のステータスが delivered に更新されていること
        $stmtOrder = $this->pdo->query("SELECT status FROM subcontractor_orders WHERE id = 1");
        $status = $stmtOrder->fetchColumn();
        $this->assertEquals('delivered', $status);

        // DB確認: 新しいファイルが登録され、古いファイルの is_latest が 0 になっていること
        $stmtOldFile = $this->pdo->query("SELECT is_latest FROM project_files WHERE id = 1");
        $this->assertEquals(0, $stmtOldFile->fetchColumn());

        $stmtNewFile = $this->pdo->query("SELECT * FROM project_files WHERE version = 2");
        $newFile = $stmtNewFile->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($newFile);
        $this->assertEquals('new_structure.pdf', $newFile['file_name']);
        $this->assertEquals(1, $newFile['is_latest']);

        // メッセージ確認
        $stmtMsg = $this->pdo->query("SELECT message_text FROM messages ORDER BY id DESC LIMIT 1");
        $msgText = $stmtMsg->fetchColumn();
        $this->assertStringContainsString('テスト担当者 様より成果物の納品（構造図）（ファイルアップロード）が行われました。', $msgText);
    }

    public function testHandlePostRequestUpdateOrderDetails(): void
    {
        $postData = [
            'action' => 'update_order_details',
            'order_id' => '1',
            'project_id' => '10',
            'task_title' => '更新コントローラーテスト',
            'order_amount' => '120000',
            'completed_at' => '2026-07-20'
        ];

        $this->controller->handlePostRequest(3, true, $postData, []);

        $stmt = $this->pdo->query("SELECT * FROM subcontractor_orders WHERE id = 1");
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('更新コントローラーテスト', $order['task_title']);
        $this->assertEquals(120000, $order['order_amount']);
        $this->assertEquals('2026-07-20', $order['completed_at']);
    }
}
