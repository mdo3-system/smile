<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use App\Container\AppContainer;
use App\Controllers\Api\EstimateController;

class EstimateControllerTest extends TestCase {

    private PDO $pdo;

    protected function setUp(): void {
        parent::setUp();
        
        // インメモリのSQLite DBを作成
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL,
                primary_due_date VARCHAR(50) NULL,
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

        // テストデータ
        $stmt = $this->pdo->prepare("
            INSERT INTO projects (project_name, status, primary_due_date, req_permit, req_wall, schedule_actuals) 
            VALUES (:name, 'primary_prep', '2026-06-25', 1, 0, :act)
        ");
        $stmt->execute([
            'name' => 'テストA様邸',
            'act' => json_encode([0 => '2026-06-20', 1 => '2026-06-25'])
        ]);

        // コンテナへのインジェクション
        $container = AppContainer::getInstance();
        $container->setPDO($this->pdo);
    }

    protected function tearDown(): void {
        // コンテナを元に戻す
        AppContainer::setInstance(null);
        parent::tearDown();
    }

    /**
     * 見積確定時の追加された仕様へのスケジュール実績同期テスト
     */
     public function testSaveSyncsScheduleActualsWhenOptionAdded() {
         $_POST['project_id'] = '1';
         
         // 性能表示壁量計算 (est_active_wall) を後から追加チェックした想定
         $inputs = [
             'est_active_permit' => true,
             'est_active_wall' => true, // 後から追加
             'est_active_skin' => false,
             'est_active_sky' => false,
             'est_kisohari_wall' => true // 基礎梁オプションもオン
         ];
         $_POST['inputs_json'] = json_encode($inputs);

         // コントローラー内の generate_estimate_pdf などの外部 require で
         // テスト環境下でファイルが存在しない等による例外（エラー）が発生することを期待します。
         // しかし、その例外が発生する前に、目的の projects の同期 UPDATE クエリが走っていることを確認します。
         $controller = new EstimateController();
         ob_start();
         try {
             $controller->save();
         } catch (\Throwable $e) {
             // 外部 require 失敗の例外は無視する
         } finally {
             if (ob_get_level() > 0) {
                 ob_end_clean();
             }
         }

         // DB の状態を確認する
         $stmt = $this->pdo->query("SELECT * FROM projects WHERE id = 1");
         $proj = $stmt->fetch(PDO::FETCH_ASSOC);

         $this->assertEquals(1, $proj['req_wall']);
         $this->assertEquals(1, $proj['req_opt_kisohari']);

         // 壁量計算の実績 JSON (schedule_actuals_wall) に
         // 基本実績 (schedule_actuals) から 0 番目の受領日 (2026-06-20)
         // および 1 番目の一次回答期日 (2026-06-25) が同期コピーされていることを検証！
         $actuals_wall = json_decode($proj['schedule_actuals_wall'] ?? '{}', true);
         $this->assertEquals('2026-06-20', $actuals_wall[0]);
         $this->assertEquals('2026-06-25', $actuals_wall[1]);
     }
}
