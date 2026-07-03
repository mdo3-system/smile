<?php
// tests/Unit/FinanceScheduleSyncTest.php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

class FinanceScheduleSyncTest extends TestCase {
    private $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テスト用のテーブルを作成
        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT,
                status TEXT,
                is_archived INTEGER DEFAULT 0,
                is_client_archived INTEGER DEFAULT 0,
                req_permit INTEGER DEFAULT 0,
                req_wall INTEGER DEFAULT 0,
                req_skin INTEGER DEFAULT 0,
                req_sky INTEGER DEFAULT 0,
                req_opt_kisohari INTEGER DEFAULT 0,
                deposit_amount_50 INTEGER DEFAULT 0,
                deposit_amount_rem INTEGER DEFAULT 0,
                deposit_date_50 TEXT,
                deposit_date_rem TEXT,
                schedule_actuals TEXT,
                schedule_actuals_wall TEXT,
                schedule_actuals_skin TEXT,
                schedule_actuals_sky TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                role TEXT,
                email_notification_enabled INTEGER DEFAULT 1
            )
        ");

        // functions.php 内の関数が未定義の場合はダミー定義（テストでは手動でロードするため不要）
        require_once __DIR__ . '/../../functions.php';
    }

    public function testSyncFinanceDatesToSchedule() {
        // テスト案件の挿入
        $stmt = $this->pdo->prepare("
            INSERT INTO projects (project_name, status, req_permit, deposit_date_50, deposit_date_rem)
            VALUES ('テスト案件1', 'quote_req', 1, '2026-07-03', '2026-07-31')
        ");
        $stmt->execute();
        $pid = $this->pdo->lastInsertId();

        // 同期関数の実行
        syncFinanceDatesToSchedule($pid, $this->pdo);

        // 結果検証
        $stmtRes = $this->pdo->prepare("SELECT schedule_actuals FROM projects WHERE id = :id");
        $stmtRes->execute(['id' => $pid]);
        $res = $stmtRes->fetch(PDO::FETCH_ASSOC);

        $actuals = json_decode($res['schedule_actuals'] ?? '{}', true);
        $this->assertEquals('2026-07-03', $actuals[4]); // 中間金実績
        $this->assertEquals('2026-07-31', $actuals[11]); // 残金実績
    }

    public function testSyncScheduleDatesToFinance() {
        // 実績JSONデータを構築
        $actuals = [4 => '2026-07-05', 11 => '2026-08-01'];
        $actuals_json = json_encode($actuals, JSON_FORCE_OBJECT);

        $stmt = $this->pdo->prepare("
            INSERT INTO projects (project_name, status, req_permit, schedule_actuals)
            VALUES ('テスト案件2', 'quote_req', 1, :act)
        ");
        $stmt->execute(['act' => $actuals_json]);
        $pid = $this->pdo->lastInsertId();

        // 同期関数の実行
        syncScheduleDatesToFinance($pid, $this->pdo);

        // 結果検証
        $stmtRes = $this->pdo->prepare("SELECT deposit_date_50, deposit_date_rem FROM projects WHERE id = :id");
        $stmtRes->execute(['id' => $pid]);
        $res = $stmtRes->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('2026-07-05', $res['deposit_date_50']);
        $this->assertEquals('2026-08-01', $res['deposit_date_rem']);
    }
}
