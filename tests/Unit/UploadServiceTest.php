<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Services\UploadService;

class UploadServiceTest extends TestCase
{
    private PDO $pdo;
    private UploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // インメモリのSQLite DBを作成
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQLiteにはNOW()やJSON型が特殊なため、UDFとして追加
        $this->pdo->sqliteCreateFunction('NOW', function() {
            return date('Y-m-d H:i:s');
        });

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT NOT NULL,
                status TEXT NOT NULL,
                req_permit INTEGER DEFAULT 0,
                req_wall INTEGER DEFAULT 0,
                req_skin INTEGER DEFAULT 0,
                req_sky INTEGER DEFAULT 0,
                req_opt_kisohari INTEGER DEFAULT 0,
                schedule_actuals TEXT NULL,
                schedule_actuals_wall TEXT NULL,
                schedule_actuals_skin TEXT NULL,
                schedule_actuals_sky TEXT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE project_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                file_category TEXT NOT NULL,
                file_name TEXT NOT NULL,
                drive_file_id TEXT NULL,
                version INTEGER NOT NULL DEFAULT 1,
                is_latest INTEGER NOT NULL DEFAULT 1,
                update_reason TEXT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                thread_type TEXT NOT NULL,
                message_text TEXT NOT NULL,
                file_path TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // テスト用データ追加
        $stmt = $this->pdo->prepare("
            INSERT INTO projects (project_name, status, req_permit) 
            VALUES ('テスト案件1', 'primary_prep', 1)
        ");
        $stmt->execute();

        $this->service = new UploadService($this->pdo);
    }

    public function testGetFileCategoryLabel(): void
    {
        $label = $this->service->getFileCategoryLabel('calc_doc');
        $this->assertEquals('構造計算書', $label);

        $labelCustom = $this->service->getFileCategoryLabel('custom_3F平面図');
        $this->assertEquals('3F平面図', $labelCustom);
    }

    public function testAddCustomSlotSuccess(): void
    {
        $projectId = 1;
        $customLabel = '3F平面図';
        $sectionType = '専門図書';
        $tab = 'wall';
        $userId = 1;

        $result = $this->service->addCustomSlot($projectId, $customLabel, $sectionType, $tab, $userId);
        $this->assertTrue($result);

        // 重複追加の防止検証
        $resultDuplicate = $this->service->addCustomSlot($projectId, $customLabel, $sectionType, $tab, $userId);
        $this->assertFalse($resultDuplicate);

        // DB確認
        $stmt = $this->pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmt->execute(['pid' => $projectId, 'cat' => 'custom_wall_3F平面図']);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($file);
        $this->assertEquals(1, $file['version']);
        $this->assertEquals(1, $file['is_latest']);

        // チャット通知確認
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($msg);
        $this->assertStringContainsString('新しいカスタムスロット「3F平面図」を追加しました', $msg['message_text']);
    }

    public function testAddCustomDeliverableSuccess(): void
    {
        $projectId = 1;
        $customLabel = '特記仕様書';
        $tab = 'permit';
        $userId = 1;

        $result = $this->service->addCustomDeliverable($projectId, $customLabel, $tab, $userId);
        $this->assertTrue($result);

        // 重複追加の防止検証
        $resultDuplicate = $this->service->addCustomDeliverable($projectId, $customLabel, $tab, $userId);
        $this->assertFalse($resultDuplicate);

        // DB確認
        $stmt = $this->pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmt->execute(['pid' => $projectId, 'cat' => 'custom_deliverable_特記仕様書']);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($file);
        $this->assertEquals(1, $file['version']);
        $this->assertEquals(1, $file['is_latest']);

        // チャット通知確認
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($msg);
        $this->assertStringContainsString('新しい成果物スロット「特記仕様書」を追加しました', $msg['message_text']);
    }

    public function testRenameCustomDeliverableSuccess(): void
    {
        $projectId = 1;
        $oldCategory = 'custom_deliverable_特記仕様書';
        $newLabel = '特記仕様書最新版';
        $tab = 'permit';
        $userId = 1;

        // まず追加する
        $this->service->addCustomDeliverable($projectId, '特記仕様書', $tab, $userId);

        // 名前を変更する
        $result = $this->service->renameCustomDeliverable($projectId, $oldCategory, $newLabel, $tab, $userId);
        $this->assertTrue($result);

        // 旧カテゴリが残っていないか、新カテゴリが登録されているか確認
        $stmtOld = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtOld->execute(['pid' => $projectId, 'cat' => $oldCategory]);
        $this->assertEquals(0, $stmtOld->fetchColumn());

        $stmtNew = $this->pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtNew->execute(['pid' => $projectId, 'cat' => 'custom_deliverable_特記仕様書最新版']);
        $file = $stmtNew->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($file);

        // チャット通知確認
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($msg);
        $this->assertStringContainsString('成果物スロット「特記仕様書」の名称を「特記仕様書最新版」に変更しました', $msg['message_text']);
    }
}
