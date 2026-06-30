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

     /**
      * 壁量計算の進捗実績がある案件に基礎横架材を追加した際、進捗実績が正しく許容応力スケジュール(schedule_actuals)へ引き継がれることの検証
      */
     public function testSaveMigratesMilestonesWhenWallAddsKisohari() {
         // 初期化：ID 1 の案件を「壁量計算のみ (req_wall=1)」で、
         // かつ壁量実績 (schedule_actuals_wall) に 0:受領日, 1:期日, 2:初回提示, 3:CB確認, 4:申請UP を設定
         $this->pdo->exec("DELETE FROM projects");
         $stmt = $this->pdo->prepare("
             INSERT INTO projects (id, project_name, status, primary_due_date, req_permit, req_wall, schedule_actuals_wall) 
             VALUES (2, '壁量から基礎追加テスト', 'structural_dwg', '2026-06-10', 0, 1, :act_wall)
         ");
         $stmt->execute([
             'act_wall' => json_encode([
                 0 => '2026-06-01', // 受領日
                 1 => '2026-06-10', // 期日
                 2 => '2026-06-09', // 初回提示
                 3 => '2026-06-12', // CB確認
                 4 => '2026-06-18'  // 申請UP
             ], JSON_FORCE_OBJECT)
         ]);

         $_POST['project_id'] = '2';
         $_POST['req_wall'] = '1';
         $_POST['req_opt_kisohari'] = '1'; // 基礎横架材オプションが追加された！
         $_POST['req_permit'] = '0';
         $_POST['req_skin'] = '0';
         $_POST['req_sky'] = '0';

         $inputs = [
             'est_active_permit' => false,
             'est_active_wall' => true, // 壁量計算もアクティブ
             'est_active_skin' => false,
             'est_active_sky' => false,
             'est_kisohari_wall' => true // 基礎梁オプションオン
         ];
         $_POST['inputs_json'] = json_encode($inputs);

         $controller = new EstimateController();
         ob_start();
         try {
             $controller->save();
         } catch (\Throwable $e) {
             // 外部 require 失敗は無視
         }
         ob_end_clean();

         // DB 状態の確認
         $stmtCheck = $this->pdo->query("SELECT * FROM projects WHERE id = 2");
         $proj = $stmtCheck->fetch(PDO::FETCH_ASSOC);

         // 仕様フラグが正しく更新されていることを確認
         $this->assertEquals(1, $proj['req_wall']);
         $this->assertEquals(1, $proj['req_opt_kisohari']);

         // 新しく有効になった schedule_actuals (許容・基礎横架材用) に、
         // 壁量計算側の実績がマッピングルールに従って完璧に引き継がれていることを検証！
         $actuals_permit = json_decode($proj['schedule_actuals'] ?? '{}', true);
         $this->assertEquals('2026-06-01', $actuals_permit[0]); // 受領
         $this->assertEquals('2026-06-10', $actuals_permit[1]); // 期日
         $this->assertEquals('2026-06-09', $actuals_permit[2]); // 初回提示
         $this->assertEquals('2026-06-12', $actuals_permit[3]); // CB確認
         $this->assertEquals('2026-06-18', $actuals_permit[7]); // 壁量[4] (申請UP) -> 許容[7] (申請UP) へ引き継ぎ！
     }
}
