<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Services\SalesFinanceService;

class SalesFinanceServiceTest extends TestCase {

    private PDO $pdo;
    private SalesFinanceService $service;

    protected function setUp(): void {
        parent::setUp();
        
        // インメモリのSQLite DBを作成
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // MySQL依存の関数をSQLite用にUDF（ユーザー定義関数）としてシミュレート
        $this->pdo->sqliteCreateFunction('DATE_FORMAT', function($date, $format) {
            if (empty($date)) return '';
            return date('Y-m', strtotime($date));
        });
        
        $this->pdo->sqliteCreateFunction('ISNULL', function($val) {
            return is_null($val) ? 1 : 0;
        });
        
        $this->pdo->sqliteCreateFunction('FIELD', function() {
            $args = func_get_args();
            $val = array_shift($args);
            $pos = array_search($val, $args);
            return $pos === false ? 99 : $pos + 1;
        });

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT NOT NULL,
                client_id INTEGER NOT NULL,
                deposit_amount INTEGER DEFAULT 0,
                deposit_date TEXT NULL,
                deposit_status TEXT DEFAULT 'unpaid',
                additional_amount INTEGER DEFAULT 0,
                formal_est_amount INTEGER NULL,
                formal_est_date TEXT NULL,
                billing_company_name TEXT NULL,
                last_manual_chat_at TEXT NULL,
                primary_due_date TEXT NULL,
                status TEXT NOT NULL DEFAULT 'quote_req',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->pdo->exec("
            CREATE TABLE estimates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                total_price INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
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
                payment_status TEXT DEFAULT 'unpaid',
                payment_date TEXT NULL,
                completed_at TEXT NULL
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

        // テスト初期データ挿入
        $this->pdo->exec("INSERT INTO users (id, company_name, contact_name, role) VALUES (1, 'テスト建設', '山田太郎', 'client')");
        $this->pdo->exec("INSERT INTO users (id, company_name, contact_name, role) VALUES (2, 'CADサポート', '木村', 'subcontractor')");
        
        $this->pdo->exec("
            INSERT INTO projects (id, project_name, client_id, status, created_at) 
            VALUES (10, 'A様邸新築工事', 1, 'structural_dwg', '2026-06-10 10:00:00')
        ");

        $this->pdo->exec("INSERT INTO estimates (project_id, total_price) VALUES (10, 100000)"); // 税込換算：110,000円
        
        $this->service = new SalesFinanceService($this->pdo);
    }

    /**
     * 25日締め日の算出テスト
     */
    public function testGetClosingPeriod() {
        $period = $this->service->getClosingPeriod('2026-06');
        $this->assertEquals('2026-05-26 00:00:00', $period['start_date']);
        $this->assertEquals('2026-06-25 23:59:59', $period['end_date']);
    }

    /**
     * 経理サマリー集計のテスト
     */
    public function testGetSalesSummary() {
        // 案件の入金額を設定
        $this->pdo->exec("UPDATE projects SET deposit_amount = 50000, deposit_date = '2026-06-20' WHERE id = 10");

        // 協力業者発注（当月支払分）を設定
        $this->pdo->exec("
            INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status, payment_status, payment_date, completed_at)
            VALUES (10, 2, '構造図作成', 30000, 'completed', 'paid', '2026-06-28', '2026-06-15 12:00:00')
        ");

        // 協力業者発注（当月締め・支払予定分）を設定
        $this->pdo->exec("
            INSERT INTO subcontractor_orders (project_id, subcontractor_id, task_title, order_amount, status, payment_status, completed_at)
            VALUES (10, 2, '外皮計算', 15000, 'completed', 'unpaid', '2026-06-24 10:00:00')
        ");

        $summary = $this->service->getSalesSummary('2026-06');

        // 実績集計の検証
        $this->assertEquals(50000, $summary['actual_deposit_total']);
        $this->assertEquals(30000, $summary['actual_payment_total']);
        // 支払予定（当月25日締め：2026-05-26 〜 2026-06-25 完了分）:
        // 完了が 6/15(30,000円) と 6/24(15,000円) なので 合計 45,000円
        $this->assertEquals(45000, $summary['expected_payment_total']);
    }

    /**
     * 依頼主入金更新処理（updateDeposit）のテスト
     */
    public function testUpdateDepositCalculatesStatusAndNotifies() {
        $projectId = 10;
        $depositAmt = 110000; // 見積もり 100,000 * 1.1 = 110,000 (完済)
        $depositDate = '2026-06-28';
        $newStatus = 'submission';
        $senderId = 1;

        $this->service->updateDeposit($projectId, $depositAmt, $depositDate, $newStatus, $senderId);

        // 状態検証
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :id");
        $stmt->execute(['id' => $projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($depositAmt, $project['deposit_amount']);
        $this->assertEquals($depositDate, $project['deposit_date']);
        $this->assertEquals('paid', $project['deposit_status']); // 自動的に完済(paid)になること
        $this->assertEquals('submission', $project['status']);

        // チャット通知（messages）の確認
        $stmtMsg = $this->pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 1");
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($msg);
        $this->assertEquals($projectId, $msg['project_id']);
        $this->assertEquals('client_admin', $msg['thread_type']);
        $this->assertStringContainsString('入金状況: 未入金 ➔ 完済', $msg['message_text']);
        $this->assertStringContainsString('案件ステータス: 申請図書作成中 ➔ 提出済・確認中', $msg['message_text']);
    }
}
