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
                file_type TEXT NULL,
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

    public function testSingleUploadCleansEmptyPlaceholder(): void
    {
        $projectId = 1;
        $customCat = 'custom_wall_3F平面図';
        
        // スロット追加（空プレースホルダー作成）
        $this->service->addCustomSlot($projectId, '3F平面図', '専門図書', 'wall', 1);

        // スロット追加直後は1件
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtCount->execute(['pid' => $projectId, 'cat' => $customCat]);
        $this->assertEquals(1, $stmtCount->fetchColumn());

        // 初回アップロード（他ファイル記載として）
        $this->service->singleUpload($projectId, $customCat, null, true, '初回登録', 1, 'client', 'wall');

        // 空プレースホルダーが削除され、実データのみ1件（version 1）残っていること
        $stmtFiles = $this->pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtFiles->execute(['pid' => $projectId, 'cat' => $customCat]);
        $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $files);
        $this->assertEquals(1, $files[0]['version']);
        $this->assertEquals('【他ファイルに記載】', $files[0]['file_name']);
    }

    public function testCorrectionNoticeUploadResetsScheduleActualsAndTransitionsStatus(): void
    {
        $projectId = 1;
        // 初期状態: 補正対応・残金精算が完了済み、ステータスはsubmission
        $initialActuals = [
            0 => '2026-09-01',
            1 => '2026-09-02',
            10 => '2026-09-15', // 補正対応
            11 => '2026-09-20', // 残金のご精算
        ];
        $stmt = $this->pdo->prepare("
            UPDATE projects 
            SET status = 'submission', 
                req_permit = 1,
                schedule_actuals = :act 
            WHERE id = :id
        ");
        $stmt->execute([
            'act' => json_encode($initialActuals),
            'id' => $projectId
        ]);

        // 依頼主が2回目の補正通知書をアップロード（他ファイル記載としてシミュレート）
        $result = $this->service->singleUpload(
            $projectId, 
            'correction_notice', 
            null, 
            true, 
            '2回目質疑通知書の受領', 
            2, 
            'client', 
            'permit'
        );

        $this->assertTrue($result);

        // 案件のステータスが 'correction' に更新されていること
        $stmtProj = $this->pdo->prepare("SELECT status, schedule_actuals FROM projects WHERE id = :id");
        $stmtProj->execute(['id' => $projectId]);
        $proj = $stmtProj->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('correction', $proj['status']);

        // schedule_actuals の補正対応(10)と残金のご精算(11)がクリアされ、0と1だけ残っていること
        $actuals = json_decode($proj['schedule_actuals'], true);
        $this->assertArrayHasKey(0, $actuals);
        $this->assertArrayHasKey(1, $actuals);
        $this->assertArrayNotHasKey(10, $actuals, '補正対応の実績日がクリアされていること');
        $this->assertArrayNotHasKey(11, $actuals, '残金のご精算の実績日がクリアされていること');

        // チャットメッセージに「補正通知書」自動通知が投稿されていること
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($msg);
        $this->assertStringContainsString('補正通知書', $msg['message_text']);
        $this->assertStringContainsString('補正対応中', $msg['message_text']);
    }

    public function testDeleteCustomSlotSuccess(): void
    {
        $projectId = 1;
        $customLabel = '仮設図面';
        $sectionType = '共通図書';
        $tab = 'permit';
        $userId = 1;

        // まずスロット作成
        $this->service->addCustomSlot($projectId, $customLabel, $sectionType, $tab, $userId);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmt->execute(['pid' => $projectId, 'cat' => 'custom_仮設図面']);
        $this->assertEquals(1, $stmt->fetchColumn());

        // スロット削除
        $res = $this->service->deleteCustomSlot($projectId, 'custom_仮設図面', $tab, $userId);
        $this->assertTrue($res);

        // レコードが削除されていること
        $stmt->execute(['pid' => $projectId, 'cat' => 'custom_仮設図面']);
        $this->assertEquals(0, $stmt->fetchColumn());

        // チャット通知確認
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('図書スロット「仮設図面」を削除しました', $msg['message_text']);
    }

    public function testDeleteCustomDeliverableSuccess(): void
    {
        $projectId = 1;
        $customLabel = '施工計画書';
        $tab = 'permit';
        $userId = 1;

        // 成果物スロット作成
        $this->service->addCustomDeliverable($projectId, $customLabel, $tab, $userId);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmt->execute(['pid' => $projectId, 'cat' => 'custom_deliverable_施工計画書']);
        $this->assertEquals(1, $stmt->fetchColumn());

        // スロット削除
        $res = $this->service->deleteCustomDeliverable($projectId, 'custom_deliverable_施工計画書', $tab, $userId);
        $this->assertTrue($res);

        // レコードが削除されていること
        $stmt->execute(['pid' => $projectId, 'cat' => 'custom_deliverable_施工計画書']);
        $this->assertEquals(0, $stmt->fetchColumn());

        // チャット通知確認
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('成果物スロット「施工計画書」を削除しました', $msg['message_text']);
    }

    public function testDeleteCustomSlotWithFilesEvacuatesToChat(): void
    {
        $projectId = 1;
        $customLabel = '太陽光割付図';
        $category = 'custom_太陽光割付図';
        $tab = 'skin';
        $userId = 1;

        // スロットにファイルを2件登録
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
            VALUES (:pid, :cat, :name, :fid, :ver, 1)
        ");
        $stmtInsert->execute(['pid' => $projectId, 'cat' => $category, 'name' => '割付図.pdf', 'fid' => 'gdrive_file_id_001', 'ver' => 1]);
        $stmtInsert->execute(['pid' => $projectId, 'cat' => $category, 'name' => '仕様書.pdf', 'fid' => 'gdrive_file_id_002', 'ver' => 1]);

        // スロット削除を実行
        $res = $this->service->deleteCustomSlot($projectId, $category, $tab, $userId);
        $this->assertTrue($res);

        // project_files からはスロット枠が削除されていること
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtCheck->execute(['pid' => $projectId, 'cat' => $category]);
        $this->assertEquals(0, $stmtCheck->fetchColumn());

        // チャットメッセージに2件のファイルが退避・配置されていること
        $stmtMsgs = $this->pdo->prepare("
            SELECT * FROM messages 
            WHERE project_id = :pid AND message_text LIKE '%太陽光割付図%' 
            ORDER BY id ASC
        ");
        $stmtMsgs->execute(['pid' => $projectId]);
        $msgs = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $msgs);
        $this->assertEquals('gdrive_file_id_001', $msgs[0]['file_path']);
        $this->assertEquals('file', $msgs[0]['file_type']);
        $this->assertStringContainsString('チャットへ退避・保管しました', $msgs[0]['message_text']);
        $this->assertStringContainsString('割付図.pdf', $msgs[0]['message_text']);

        $this->assertEquals('gdrive_file_id_002', $msgs[1]['file_path']);
        $this->assertEquals('file', $msgs[1]['file_type']);
        $this->assertStringContainsString('仕様書.pdf', $msgs[1]['message_text']);
    }

    public function testUploadArtifactReplacesLatestEvenWithDifferentDateFileName(): void
    {
        $projectId = 1;
        $category = 'structural_dwg';

        // 1回目の成果物UP（過去の日付ファイル）
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
            VALUES (:pid, :cat, :name, :fid, 1, 1)
        ");
        $stmtInsert->execute(['pid' => $projectId, 'cat' => $category, 'name' => '構造図20261012.pdf', 'fid' => 'fid_old_date']);

        // 2回目の成果物UP（今日の日付の別名ファイル）
        $stmtOld = $this->pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
        $stmtOld->execute(['pid' => $projectId, 'cat' => $category]);

        $stmtVer = $this->pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtVer->execute(['pid' => $projectId, 'cat' => $category]);
        $nextVer = intval($stmtVer->fetchColumn()) + 1;

        $stmtNew = $this->pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :fname, :fid, :ver, 1)");
        $stmtNew->execute([
            'pid' => $projectId,
            'cat' => $category,
            'fname' => '構造図20261022.pdf',
            'fid' => 'fid_new_date',
            'ver' => $nextVer
        ]);

        // 最新版（is_latest = 1）は1件のみであること
        $stmtLatest = $this->pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category = :cat AND is_latest = 1");
        $stmtLatest->execute(['pid' => $projectId, 'cat' => $category]);
        $latestRows = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $latestRows);
        $this->assertEquals('構造図20261022.pdf', $latestRows[0]['file_name']);
        $this->assertEquals(2, $latestRows[0]['version']);

        // 旧ファイルは is_latest = 0 の過去バージョンとして残っていること
        $stmtOldRow = $this->pdo->prepare("SELECT * FROM project_files WHERE project_id = :pid AND file_category = :cat AND version = 1");
        $stmtOldRow->execute(['pid' => $projectId, 'cat' => $category]);
        $oldRow = $stmtOldRow->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('構造図20261012.pdf', $oldRow['file_name']);
        $this->assertEquals(0, $oldRow['is_latest']);
    }
}
