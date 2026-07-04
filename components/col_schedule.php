<?php
// components/col_schedule.php
?>
<div class="column col-schedule" style="padding: 15px; flex:1; display:flex; flex-direction:column; gap:20px;">
    <?php
    $base_days = getScheduleBaseDays($project_info);
    $primary_due_date = $project_info['primary_due_date'] ?? null;

    $schedulesToRender = [];

    if ($active_tab === 'permit' && (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1)) {
        $schedulesToRender[] = [
            'title' => '許容応力度・基礎横架材計算',
            'type' => 'permit',
            'steps' => getScheduleSteps($base_days, true),
            'actuals_col' => 'schedule_actuals'
        ];
    }
    if ($active_tab === 'wall' && ($project_info['req_wall'] ?? 0) == 1) {
        $schedulesToRender[] = [
            'title' => '壁量計算',
            'type' => 'wall',
            'steps' => getScheduleStepsWall($base_days),
            'actuals_col' => 'schedule_actuals_wall'
        ];
    }
    if ($active_tab === 'skin' && ($project_info['req_skin'] ?? 0) == 1) {
        $schedulesToRender[] = [
            'title' => '外皮計算',
            'type' => 'skin',
            'steps' => getScheduleStepsSkin($base_days),
            'actuals_col' => 'schedule_actuals_skin'
        ];
    }
    if ($active_tab === 'sky' && ($project_info['req_sky'] ?? 0) == 1) {
        $schedulesToRender[] = [
            'title' => '天空率',
            'type' => 'sky',
            'steps' => getScheduleStepsSky($base_days),
            'actuals_col' => 'schedule_actuals_sky'
        ];
    }
    
    if (empty($schedulesToRender)) {
        echo "<div class='box' style='background:#fff;'><p>現在有効なスケジュールはありません。</p></div>";
    }

    foreach ($schedulesToRender as $scheduleItem):
        $schedule_actuals = json_decode($project_info[$scheduleItem['actuals_col']] ?? '{}', true) ?: [];
        $override_col = str_replace('actuals', 'overrides', $scheduleItem['actuals_col']);
        $schedule_overrides = json_decode($project_info[$override_col] ?? '{}', true) ?: [];
        $wishes_col = str_replace('actuals', 'wishes', $scheduleItem['actuals_col']);
        $schedule_wishes = json_decode($project_info[$wishes_col] ?? '{}', true) ?: [];
    ?>
    <!-- ▼▼▼ 進捗スケジュール可視化 ▼▼▼ -->
    <div class="box" style="background:#fff; border-color:#cbd5e1; margin-top:0;">
        <h3 style="margin-top:0; font-size:14px; color:#1e293b; border-bottom:1px solid #cbd5e1; padding-bottom:5px; display:flex; align-items:center; gap:5px;">
            📅 <?= htmlspecialchars($scheduleItem['title']) ?>のスケジュール
        </h3>
        
        <div style="max-height: 400px; overflow-y: auto;">
            <table style="width:100%; border-collapse:collapse; font-size:11px;">
                <thead>
                    <tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1; position:sticky; top:0;">
                        <th style="padding:6px; text-align:left; white-space:nowrap;">工程</th>
                        <th style="padding:6px; text-align:left; white-space:nowrap;">担当</th>
                        <th style="padding:6px; text-align:left; white-space:nowrap;">予定</th>
                        <th style="padding:6px; text-align:left; white-space:nowrap;">依頼主希望日</th>
                        <th style="padding:6px; text-align:left; white-space:nowrap;">実施日</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $base_start_date = $primary_due_date ?: ($schedule_actuals[1] ?? $schedule_actuals[0] ?? '');
                $calc_date = $base_start_date; 
                $scheduleService = new \App\Services\ScheduleService($pdo);
                $current_step_idx = $scheduleService->getCurrentStepIndex($scheduleItem['steps'], $schedule_actuals, $primary_due_date);
                foreach ($scheduleItem['steps'] as $idx => $step) {
                    $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8fafc';
                    $badge = '';
                    if ($step['actor'] == 'designer') {
                        $badge = '<span style="background:#3b82f6; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟦 サポート</span>';
                    } elseif ($step['actor'] == 'client') {
                        $client_display_name = htmlspecialchars($project_info['client_name'], ENT_QUOTES) . '様';
                        $badge = '<span style="background:#10b981; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟩 ' . $client_display_name . '</span>';
                    } else {
                        $badge = '<span style="background:#64748b; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">⬛ 審査・待機</span>';
                    }

                    $date_str = '<span style="color:#64748b;">未確定</span>';
                    
                    if ($base_start_date) {
                        if ($idx == 0) {
                            $date_str = '<span style="color:#64748b;">-</span>';
                        } elseif ($idx == 1) {
                            $calc_date = $schedule_overrides[$idx] ?? $base_start_date;
                            $date_str = '<strong>' . date('m/d', strtotime($calc_date)) . '</strong>';
                        } else {
                            if ($step['type'] == 'biz') {
                                $calc_date = addBusinessDays($calc_date, $step['days']);
                            } elseif ($step['type'] == 'cal') {
                                $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                            }
                            
                            // この工程に予定日の上書きがあるかチェック
                            if (!empty($schedule_overrides[$idx])) {
                                $calc_date = $schedule_overrides[$idx];
                                $date_str = '<span style="color:#2563eb; font-weight:bold;">' . date('m/d', strtotime($calc_date)) . ' (変)</span>';
                            } else {
                                $date_str = date('m/d', strtotime($calc_date));
                            }
                        }
                    }

                    // 予定日の値を取得しておく（編集フォームの初期値用）
                    $planned_date = ($base_start_date && $idx > 0) ? date('Y-m-d', strtotime($calc_date)) : '';

                    // 実施日があればそれを起算日に上書きする
                    $actual_date = $schedule_actuals[$idx] ?? '';
                    if ($actual_date) {
                        $calc_date = $actual_date;
                        $actual_date = date('Y-m-d', strtotime($actual_date));
                        $date_str = '<span style="color:#10b981; font-weight:bold;">' . date('m/d', strtotime($actual_date)) . ' (済)</span>';
                    }

                    // 予定日の表示と編集フォーム
                    $plan_display = $date_str;
                    if ($is_admin && $primary_due_date && $idx > 0 && !$actual_date) {
                        $override_val = $schedule_overrides[$idx] ?? '';
                        if ($override_val) {
                            $override_val = date('Y-m-d', strtotime($override_val));
                        }
                        $plan_display = '
                        <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:3px; align-items:center;">
                            <input type="hidden" name="action" value="update_schedule_override">
                            <input type="hidden" name="schedule_type" value="'.htmlspecialchars($scheduleItem['type'], ENT_QUOTES).'">
                            <input type="hidden" name="step_idx" value="'.$idx.'">
                            <input type="date" name="override_date" value="'.htmlspecialchars($override_val ?: $planned_date, ENT_QUOTES).'" style="font-size:10px; padding:2px; width:100px;">
                            <button type="submit" style="font-size:9px; padding:2px 4px; background:#eff6ff; border:1px solid #bfdbfe; color:#2563eb; border-radius:3px; cursor:pointer;">変更</button>
                        </form>';
                    }

                    // 実施日表示または入力フォーム
                    $actual_display = '';
                    if ($is_admin) {
                        $actual_display = '
                        <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:5px; align-items:center;">
                            <input type="hidden" name="action" value="update_schedule_actual">
                            <input type="hidden" name="schedule_type" value="'.htmlspecialchars($scheduleItem['type'], ENT_QUOTES).'">
                            <input type="hidden" name="step_idx" value="'.$idx.'">
                            <input type="date" name="actual_date" value="'.htmlspecialchars($actual_date, ENT_QUOTES).'" style="font-size:10px; padding:2px;">
                            <button type="submit" style="font-size:10px; padding:2px 5px; background:#e2e8f0; border:1px solid #cbd5e1; border-radius:3px; cursor:pointer;">保存</button>
                        </form>';
                    } elseif ($is_accountant) {
                        $actual_display = $actual_date ? '<strong>' . date('m/d', strtotime($actual_date)) . '</strong>' : '<span style="color:#aaa;">-</span>';
                    }

                    $step_name = $step['name'];
                    if ($step_name === '残金のご精算') {
                        $formal = (int)($project_info['formal_est_amount'] ?? 0);
                        $add_estimates = json_decode($project_info['additional_estimates'] ?? '[]', true) ?: [];
                        $total_add = 0;
                        foreach ($add_estimates as $ae) {
                            $total_add += (int)$ae['amount'];
                        }
                        $total_req = $formal + $total_add;
                        $dep_50 = (int)($project_info['deposit_amount_50'] ?? 0);
                        $dep_rem = (int)($project_info['deposit_amount_rem'] ?? 0);
                        $additional_deposits = json_decode($project_info['additional_deposits'] ?? '[]', true) ?: [];
                        $total_add_dep = 0;
                        foreach ($additional_deposits as $ad) {
                            $total_add_dep += (int)$ad['amount'];
                        }
                        $total_deposit = $dep_50 + $dep_rem + $total_add_dep;
                        $balance = $total_req - $total_deposit;
                        if ($balance <= 0) {
                            $step_name = '審査完了';
                        }
                    }

                    // 希望日の表示
                    $wish_val = $schedule_wishes[$idx] ?? '';
                    $wish_display = !empty($wish_val) ? '<span style="color:#6d28d9; font-weight:bold;">' . date('m/d', strtotime($wish_val)) . '</span>' : '<span style="color:#aaa;">-</span>';

                    $is_current = ($idx === $current_step_idx);
                    $row_style = "background:{$bg_color}; border-bottom:1px solid #e2e8f0;";
                    if ($is_current) {
                        $row_style = "background:#fee2e2; border:2px solid #ef4444; font-weight:bold;";
                    }
                    $current_badge = $is_current ? ' <span style="background:#ef4444; color:white; padding:1px 5px; border-radius:3px; font-size:9px; margin-left:5px; font-weight:bold;">👉 現在地</span>' : '';

                    echo "<tr style='{$row_style}'>";
                    echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step_name}{$current_badge}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
                    echo "<td style='padding:6px; white-space:nowrap;'>{$badge}</td>";
                    echo "<td style='padding:6px;'>{$plan_display}</td>";
                    echo "<td style='padding:6px;'>{$wish_display}</td>";
                    echo "<td style='padding:6px;'>{$actual_display}</td>";
                    echo "</tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
