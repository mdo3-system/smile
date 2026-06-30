<?php
namespace App\Services;

use DateTime;
use PDO;

/**
 * スケジュールの営業日・各ステップの予定日・現在地判定を行うドメインサービス
 */
class ScheduleService {
    
    private $pdo;

    public function __construct(PDO $pdo = null) {
        $this->pdo = $pdo;
    }

    /**
     * 営業日加算（水曜・日曜除外）
     */
    public function addBusinessDays($dateStr, $days) {
        if (!$dateStr) return '';
        $date = new DateTime($dateStr);
        $added = 0;
        while ($added < $days) {
            $date->modify('+1 day');
            $dayOfWeek = (int)$date->format('N'); // 1:月 ～ 7:日
            if ($dayOfWeek !== 3 && $dayOfWeek !== 7) { 
                $added++; 
            }
        }
        return $date->format('Y-m-d');
    }

    /**
     * 依頼種別に応じた一次回答までの営業日数を返す
     */
    public function getScheduleBaseDays(array $project_info): int {
        $req_permit      = (int)($project_info['req_permit']      ?? 0);
        $req_wall        = (int)($project_info['req_wall']        ?? 0);
        $req_skin        = (int)($project_info['req_skin']        ?? 0);
        $req_sky         = (int)($project_info['req_sky']         ?? 0);
        $req_opt_kisohari = (int)($project_info['req_opt_kisohari'] ?? 0);

        if ($req_permit || $req_opt_kisohari) return 12;
        if ($req_wall)                         return 7;
        if ($req_skin || $req_sky)             return 10;
        return 12; // デフォルト
    }

    /**
     * 許容応力スケジュールステップリスト
     */
    public function getScheduleSteps(int $base_days, bool $is_koyou_or_kisohari = false): array {
        $dwg_days = $is_koyou_or_kisohari ? 7 : 4;
        return [
            ['name' => '設計図書の受領',                 'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
            ['name' => '着手基準日 (一次回答)',           'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
            ['name' => '一次回答（構造計算・図面初回提示）', 'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
            ['name' => '一次回答CB',                     'actor' => 'client',   'desc' => '初回提示から4営業日',        'days' => 4,         'type' => 'biz'],
            ['name' => '構造図UP',                       'actor' => 'designer', 'desc' => "一次回答CBから{$dwg_days}営業日",  'days' => $dwg_days, 'type' => 'biz'],
            ['name' => '構造図CB',                       'actor' => 'client',   'desc' => "構造図UPから{$dwg_days}営業日",    'days' => $dwg_days, 'type' => 'biz'],
            ['name' => '修正図面UP',                      'actor' => 'designer', 'desc' => 'CB確認から3営業日',          'days' => 3,         'type' => 'biz'],
            ['name' => '申請図書一式UP',                  'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
            ['name' => '質疑・審査待機',                  'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
            ['name' => '補正対応',                        'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
            ['name' => '残金のご精算',                    'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
        ];
    }

    /**
     * 壁量スケジュール
     */
    public function getScheduleStepsWall(int $base_days): array {
        return [
            ['name' => '設計図書の受領',         'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
            ['name' => '着手基準日 (一次回答)',   'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
            ['name' => '壁量計算・図面 初回提示', 'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
            ['name' => '壁量計算図CB (内容確認)', 'actor' => 'client',   'desc' => '初回提示から4営業日',        'days' => 4,         'type' => 'biz'],
            ['name' => '申請図書一式UP',          'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
            ['name' => '質疑・審査待機',          'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
            ['name' => '補正対応',                'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
            ['name' => '残金のご精算',            'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
        ];
    }

    /**
     * 外皮スケジュール
     */
    public function getScheduleStepsSkin(int $base_days): array {
        return [
            ['name' => '設計図書の受領',         'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
            ['name' => '着手基準日 (一次回答)',   'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
            ['name' => '外皮計算初回提示',        'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
            ['name' => '外皮計算図CB (内容確認)', 'actor' => 'client',   'desc' => '初回提示から4営業日',        'days' => 4,         'type' => 'biz'],
            ['name' => '申請図書一式UP',          'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
            ['name' => '質疑・審査待機',          'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
            ['name' => '補正対応',                'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
            ['name' => '残金のご精算',            'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
        ];
    }

    /**
     * 天空率スケジュール
     */
    public function getScheduleStepsSky(int $base_days): array {
        return [
            ['name' => '設計図書の受領',         'actor' => 'client',   'desc' => '開始時',                    'days' => 0,         'type' => 'base'],
            ['name' => '着手基準日 (一次回答)',   'actor' => 'designer', 'desc' => "{$base_days}営業日程度",    'days' => $base_days,'type' => 'biz'],
            ['name' => '天空率初回提示',          'actor' => 'designer', 'desc' => '着手から7〜10営業日',       'days' => 10,        'type' => 'biz'],
            ['name' => '申請図書一式UP',          'actor' => 'designer', 'desc' => '修正UPから3営業日',          'days' => 3,         'type' => 'biz'],
            ['name' => '質疑・審査待機',          'actor' => 'wait',     'desc' => '確認機関の審査',             'days' => 30,        'type' => 'cal'],
            ['name' => '補正対応',                'actor' => 'designer', 'desc' => '質疑受領から7営業日',        'days' => 7,         'type' => 'biz'],
            ['name' => '残金のご精算',            'actor' => 'client',   'desc' => '完了後7日以内',              'days' => 7,         'type' => 'cal'],
        ];
    }

    /**
     * 各プロジェクトの「現在進行中工程名」と「その工程の予定日」を取得する
     * @param array $project
     * @return array ['step_name' => string, 'plan_date' => string, 'is_completed' => bool]
     */
    public function getCurrentStepInfo(array $project): array {
        $req_permit = (int)($project['req_permit'] ?? 0);
        $req_wall = (int)($project['req_wall'] ?? 0);
        $req_skin = (int)($project['req_skin'] ?? 0);
        $req_sky = (int)($project['req_sky'] ?? 0);
        $req_opt_kisohari = (int)($project['req_opt_kisohari'] ?? 0);
        $primary_due_date = $project['primary_due_date'] ?? null;

        // スケジュールタイプと工程リストの選定
        if ($req_permit == 1 || $req_opt_kisohari == 1) {
            $base_days = $this->getScheduleBaseDays($project);
            $steps = $this->getScheduleSteps($base_days, true);
            $actuals = json_decode($project['schedule_actuals'] ?? '[]', true) ?: [];
            $overrides = json_decode($project['schedule_overrides'] ?? '[]', true) ?: [];
        } elseif ($req_wall == 1) {
            $base_days = $this->getScheduleBaseDays($project);
            $steps = $this->getScheduleStepsWall($base_days);
            $actuals = json_decode($project['schedule_actuals_wall'] ?? '[]', true) ?: [];
            $overrides = json_decode($project['schedule_overrides_wall'] ?? '[]', true) ?: [];
        } elseif ($req_skin == 1) {
            $base_days = $this->getScheduleBaseDays($project);
            $steps = $this->getScheduleStepsSkin($base_days);
            $actuals = json_decode($project['schedule_actuals_skin'] ?? '[]', true) ?: [];
            $overrides = json_decode($project['schedule_overrides_skin'] ?? '[]', true) ?: [];
        } elseif ($req_sky == 1) {
            $base_days = $this->getScheduleBaseDays($project);
            $steps = $this->getScheduleStepsSky($base_days);
            $actuals = json_decode($project['schedule_actuals_sky'] ?? '[]', true) ?: [];
            $overrides = json_decode($project['schedule_overrides_sky'] ?? '[]', true) ?: [];
        } else {
            $base_days = $this->getScheduleBaseDays($project);
            $steps = $this->getScheduleSteps($base_days, false);
            $actuals = json_decode($project['schedule_actuals'] ?? '[]', true) ?: [];
            $overrides = json_decode($project['schedule_overrides'] ?? '[]', true) ?: [];
        }

        // 1. 現在進行中の工程（実施日が入っていない最初のステップ）の特定
        $current_step_idx = $this->getCurrentStepIndex($steps, $actuals, $primary_due_date);

        if ($current_step_idx === -1) {
            return [
                'step_name' => '完了',
                'plan_date' => '',
                'is_completed' => true
            ];
        }

        // 2. その工程の予定日の計算
        $calc_date = $primary_due_date;
        $target_plan_date = '';

        if ($primary_due_date) {
            for ($idx = 0; $idx <= $current_step_idx; $idx++) {
                $step = $steps[$idx];

                if ($idx == 0) {
                    // 開始時は計算日なし
                } elseif ($idx == 1) {
                    $calc_date = $overrides[$idx] ?? $primary_due_date;
                } else {
                    if ($step['type'] == 'biz') {
                        $calc_date = $this->addBusinessDays($calc_date, $step['days']);
                    } elseif ($step['type'] == 'cal') {
                        $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                    }
                    
                    if (!empty($overrides[$idx])) {
                        $calc_date = $overrides[$idx];
                    }
                }

                $actual_date = $actuals[$idx] ?? '';
                if ($actual_date) {
                    $calc_date = $actual_date;
                }

                if ($idx === $current_step_idx) {
                    $target_plan_date = $calc_date;
                }
            }
        }

        return [
            'step_name' => $steps[$current_step_idx]['name'],
            'plan_date' => $target_plan_date,
            'is_completed' => false
        ];
    }

    /**
     * 現在進行中の工程インデックスを算出するヘルパー
     */
    public function getCurrentStepIndex(array $steps, array $actuals, ?string $primary_due_date): int {
        if (!$primary_due_date) {
            return 0;
        }
        // primary_due_dateがある場合は設計図書の受領(0)は完了したものとみなし、
        // インデックス1(着手基準日・一次回答)以降から未完了ステップを探す
        for ($i = 1; $i < count($steps); $i++) {
            if (empty($actuals[$i])) {
                return $i;
            }
        }
        return -1; // 全て完了
    }
}
