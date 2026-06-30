<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ScheduleService;

class ScheduleServiceTest extends TestCase {

    /**
     * @var ScheduleService
     */
    private $service;

    protected function setUp(): void {
        parent::setUp();
        $this->service = new ScheduleService();
    }

    /**
     * 営業日加算（水曜・日曜スキップ）のテスト
     */
    public function testAddBusinessDaysSkipsWedAndSun() {
        // 2026-06-25 (木曜日) から 2営業日後:
        // 木曜(0) -> 金曜(1日目) -> 土曜(2日目) -> 2026-06-27 (土曜日)
        $this->assertEquals('2026-06-27', $this->service->addBusinessDays('2026-06-25', 2));

        // 2026-06-25 (木曜日) から 4営業日後:
        // 金曜(1) -> 土曜(2) -> 日曜(スキップ) -> 月曜(3) -> 火曜(4) -> 2026-06-30 (火曜日)
        // ※水曜日はスキップ
        $this->assertEquals('2026-06-30', $this->service->addBusinessDays('2026-06-25', 4));

        // 2026-06-27 (土曜日) から 2営業日後:
        // 日曜(スキップ) -> 月曜(1) -> 火曜(2) -> 2026-06-30 (火曜日)
        $this->assertEquals('2026-06-30', $this->service->addBusinessDays('2026-06-27', 2));
    }

    /**
     * スケジュールベース日数の判定テスト
     */
    public function testGetScheduleBaseDays() {
        // 許容応力計算要求あり
        $proj1 = ['req_permit' => 1, 'req_wall' => 0, 'req_skin' => 0, 'req_sky' => 0];
        $this->assertEquals(12, $this->service->getScheduleBaseDays($proj1));

        // 壁量計算のみ
        $proj2 = ['req_permit' => 0, 'req_wall' => 1, 'req_skin' => 0, 'req_sky' => 0];
        $this->assertEquals(7, $this->service->getScheduleBaseDays($proj2));

        // 外皮計算のみ
        $proj3 = ['req_permit' => 0, 'req_wall' => 0, 'req_skin' => 1, 'req_sky' => 0];
        $this->assertEquals(10, $this->service->getScheduleBaseDays($proj3));

        // 天空率のみ
        $proj4 = ['req_permit' => 0, 'req_wall' => 0, 'req_skin' => 0, 'req_sky' => 1];
        $this->assertEquals(10, $this->service->getScheduleBaseDays($proj4));
    }

    /**
     * 現在進行中の工程およびその予定日算出テスト (getCurrentStepInfo)
     */
    public function testGetCurrentStepInfo() {
        // 1. 予定日未設定のケース
        $project = [
            'req_permit' => 1,
            'req_wall' => 0,
            'req_skin' => 0,
            'req_sky' => 0,
            'primary_due_date' => null,
            'schedule_actuals' => '[]',
            'schedule_overrides' => '[]'
        ];
        $info = $this->service->getCurrentStepInfo($project);
        $this->assertEquals('設計図書の受領', $info['step_name']);
        $this->assertEquals('', $info['plan_date']);
        $this->assertFalse($info['is_completed']);

        // 2. 基準日設定済みで、実績が全て空のケース
        $project['primary_due_date'] = '2026-06-25'; // 木曜日
        $info2 = $this->service->getCurrentStepInfo($project);
        $this->assertEquals('設計図書の受領', $info2['step_name']);
        $this->assertEquals('2026-06-25', $info2['plan_date']);

        // 3. 実績が進行しているケース (ステップ0:受領完了, ステップ1:基準日完了, ステップ2:一次回答中)
        // 許容応力のステップリスト:
        // 0:受領, 1:基準日, 2:一次回答提示, 3:一次回答CB, ...
        $project['schedule_actuals'] = json_encode([
            0 => '2026-06-25',
            1 => '2026-06-26'
        ]);
        $info3 = $this->service->getCurrentStepInfo($project);
        $this->assertEquals('一次回答（構造計算・図面初回提示）', $info3['step_name']);
        // 一次回答提示のdaysは10。起算日はステップ1の完了日 2026-06-26。
        // 2026-06-26 から 10営業日後は：
        // 27(土:1), 28(日:休), 29(月:2), 30(火:3), 7/1(水:休), 7/2(木:4), 7/3(金:5), 7/4(土:6), 7/5(日:休), 7/6(月:7), 7/7(火:8), 7/8(水:休), 7/9(木:9), 7/10(金:10) -> 2026-07-10
        $this->assertEquals('2026-07-10', $info3['plan_date']);
    }
}
