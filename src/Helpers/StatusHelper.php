<?php
namespace App\Helpers;

use PDO;

class StatusHelper
{
    /**
     * Get the ball owner and details based on project, subcontractor tasks, and files.
     *
     * Returns an array:
     * [
     *     'ball_owner' => 'client' | 'admin' | 'subcontractor' | 'shared_waiting' | 'completed',
     *     'label' => string (日本語ラベル),
     *     'color' => string (CSSカラー)
     * ]
     */
    public static function getBallStatus(array $project, PDO $pdo, string $user_role = null): array
    {
        $status = $project['status'] ?? '';
        $res = null;

        if ($status === 'completed') {
            $res = [
                'ball_owner' => 'completed',
                'label' => '完了',
                'color' => '#10b981' // Green
            ];
        }
        elseif ($status === 'quote_req') {
            // Check if there is an estimate issued for this project
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM estimates WHERE project_id = :pid");
            $stmt->execute(['pid' => $project['id']]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                // Estimate exists -> Client has the ball (見積確認中)
                $res = [
                    'ball_owner' => 'client',
                    'label' => '回答待ち (依頼主ボール)',
                    'color' => '#e67e22' // Orange
                ];
            } else {
                // No estimate -> Admin has the ball (見積作成中)
                $res = [
                    'ball_owner' => 'admin',
                    'label' => '図書作成中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }
        else {
            // 1. スケジュール工程の現在地（未完了の最初のステップ）を走査して状態をロードしておく
            require_once __DIR__ . '/../../functions.php';
            $base_days = getScheduleBaseDays($project);
            
            $is_koyou_or_kisohari = (($project['req_permit'] ?? 0) == 1 || ($project['req_opt_kisohari'] ?? 0) == 1);
            $req_wall = (int)($project['req_wall'] ?? 0);
            $req_skin = (int)($project['req_skin'] ?? 0);
            $req_sky = (int)($project['req_sky'] ?? 0);
            
            $steps = getScheduleSteps($base_days, $is_koyou_or_kisohari);
            $actuals_col = 'schedule_actuals';
            
            if ($req_wall) {
                $steps = getScheduleStepsWall($base_days);
                $actuals_col = 'schedule_actuals_wall';
            } elseif ($req_skin) {
                $steps = getScheduleStepsSkin($base_days);
                $actuals_col = 'schedule_actuals_skin';
            } elseif ($req_sky) {
                $steps = getScheduleStepsSky($base_days);
                $actuals_col = 'schedule_actuals_sky';
            }
            
            $actuals = json_decode($project[$actuals_col] ?? '{}', true) ?: [];
            $first_uncompleted_idx = null;
            
            foreach ($steps as $idx => $step) {
                if ($idx == 0) continue; // 「設計図書の受領」は飛ばす
                if (empty($actuals[$idx])) {
                    $first_uncompleted_idx = $idx;
                    break;
                }
            }

            $actor = null;
            $name = null;
            if ($first_uncompleted_idx !== null) {
                $step = $steps[$first_uncompleted_idx];
                $actor = $step['actor'];
                $name = $step['name'];
                
                // 残金0円時の名称変更
                if ($name === '残金のご精算') {
                    $formal = (int)($project['formal_est_amount'] ?? 0);
                    $add_estimates = json_decode($project['additional_estimates'] ?? '[]', true) ?: [];
                    $total_add = 0;
                    foreach ($add_estimates as $ae) {
                        $total_add += (int)$ae['amount'];
                    }
                    $total_req = $formal + $total_add;
                    $dep_50 = (int)($project['deposit_amount_50'] ?? 0);
                    $dep_rem = (int)($project['deposit_amount_rem'] ?? 0);
                    $additional_deposits = json_decode($project['additional_deposits'] ?? '[]', true) ?: [];
                    $total_add_dep = 0;
                    foreach ($additional_deposits as $ad) {
                        $total_add_dep += (int)$ad['amount'];
                    }
                    $total_deposit = $dep_50 + $dep_rem + $total_add_dep;
                    $balance = $total_req - $total_deposit;
                    
                    if ($balance <= 0) {
                        $name = '審査完了';
                    }
                }
            }

            // 2. 【最優先判定】進行中の協力業者タスク（外注）がある場合は、それを最優先でボールとする！
            // ただし、すでにスケジュール上で構造図UPや申請図書一式UP（成果物の提出）の実績日が入っている場合は、
            // 業者タスクが承認待ち等で残っていても、すでに依頼主や申請手番に進んでいるため、スケジュール現在地のボール判定を優先する。
            $submission_idx = -1;
            foreach ($steps as $idx => $step) {
                if ($step['name'] === '構造図UP' || $step['name'] === '申請図書一式UP') {
                    $submission_idx = $idx;
                }
            }
            $is_sub_task_effectively_done = false;
            if ($submission_idx !== -1 && !empty($actuals[$submission_idx])) {
                $is_sub_task_effectively_done = true;
            }

            $stmtTasks = $pdo->prepare("SELECT * FROM subcontractor_orders WHERE project_id = :pid AND status != 'cancelled'");
            $stmtTasks->execute(['pid' => $project['id']]);
            $tasks = $stmtTasks->fetchAll();

            if (count($tasks) > 0 && !$is_sub_task_effectively_done) {
                $has_sub_ball = false;
                $has_delivered_task = false;
                foreach ($tasks as $task) {
                    if ($task['status'] === 'requested' || $task['status'] === 'accepted' || $task['status'] === 'cb_requested') {
                        $has_sub_ball = true;
                    } elseif ($task['status'] === 'delivered') {
                        $has_delivered_task = true;
                    }
                }

                if ($has_sub_ball) {
                    $res = [
                        'ball_owner' => 'subcontractor',
                        'label' => '作成中 (協力業者ボール)',
                        'color' => '#8b5cf6' // Purple
                    ];
                }
                elseif ($has_delivered_task) {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '納品検収中 (管理者ボール)',
                        'color' => '#3b82f6' // Blue
                    ];
                }
            }

            // 3. 協力業者ボールではない場合、スケジュール工程の現在地が「依頼主ボール (actor === 'client')」であれば依頼主ボールとする
            if (!$res && $first_uncompleted_idx !== null && $actor === 'client') {
                $res = [
                    'ball_owner' => 'client',
                    'label' => $name . ' (依頼主ボール)',
                    'color' => '#e67e22'
                ];
            }

            // 4. それでも決定しなかった場合、スケジュール現在地の actor に基づいて判定する
            if (!$res && $first_uncompleted_idx !== null) {
                if ($actor === 'designer') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => $name . ' (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                } else {
                    // 審査待機など
                    $res = [
                        'ball_owner' => 'shared_waiting',
                        'label' => '審査・待機',
                        'color' => '#64748b'
                    ];
                }
            }
            
            // 5. フォールバック
            if (!$res) {
                if ($status === 'submission' || $status === 'submitting') {
                    // 審査待機前かどうかをチェック
                    $base_days = getScheduleBaseDays($project);
                    $is_koyou_or_kisohari = (($project['req_permit'] ?? 0) == 1 || ($project['req_opt_kisohari'] ?? 0) == 1);
                    $req_wall = (int)($project['req_wall'] ?? 0);
                    $req_skin = (int)($project['req_skin'] ?? 0);
                    $req_sky = (int)($project['req_sky'] ?? 0);
                    
                    $steps = getScheduleSteps($base_days, $is_koyou_or_kisohari);
                    $actuals_col = 'schedule_actuals';
                    
                    if ($req_wall) {
                        $steps = getScheduleStepsWall($base_days);
                        $actuals_col = 'schedule_actuals_wall';
                    } elseif ($req_skin) {
                        $steps = getScheduleStepsSkin($base_days);
                        $actuals_col = 'schedule_actuals_skin';
                    } elseif ($req_sky) {
                        $steps = getScheduleStepsSky($base_days);
                        $actuals_col = 'schedule_actuals_sky';
                    }
                    
                    $actuals = json_decode($project[$actuals_col] ?? '{}', true) ?: [];
                    $first_uncompleted_idx = null;
                    foreach ($steps as $idx => $step) {
                        if ($idx == 0) continue;
                        if (empty($actuals[$idx])) {
                            $first_uncompleted_idx = $idx;
                            break;
                        }
                    }
                    
                    $is_early_stage = true;
                    if ($first_uncompleted_idx !== null) {
                        $target_idx = -1;
                        foreach ($steps as $idx => $step) {
                            if ($step['name'] === '質疑・審査待機') {
                                $target_idx = $idx;
                                break;
                            }
                        }
                        if ($target_idx !== -1 && $first_uncompleted_idx >= $target_idx) {
                            $is_early_stage = false;
                        }
                    } else {
                        // すべて完了しているなら初期段階ではない
                        $is_early_stage = false;
                    }
                    
                    if ($is_early_stage) {
                        $res = [
                            'ball_owner' => 'admin',
                            'label' => '設計進行中 (管理者ボール)',
                            'color' => '#3b82f6'
                        ];
                    } else {
                        $res = [
                            'ball_owner' => 'shared_waiting',
                            'label' => '審査・待機',
                            'color' => '#64748b'
                        ];
                    }
                }
                elseif ($status === 'primary_prep' || $status === 'structural_dwg' || $status === 'correction') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6' // Blue
                    ];
                }
                elseif ($status === 'contracted') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                }
                else {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                }
            }
        }

        // 依頼主(client)には協力業者の存在を見せないため、協力業者ボールおよび外注納品関連ステータスは管理者ボールとして返す
        if ($user_role === 'client') {
            if ($res['ball_owner'] === 'subcontractor' || $res['label'] === '納品検収中 (管理者ボール)') {
                return [
                    'ball_owner' => 'admin',
                    'label' => '図書作成中 (管理者ボール)',
                    'color' => '#3b82f6'
                ];
            }
        }

        return $res;
    }
}
