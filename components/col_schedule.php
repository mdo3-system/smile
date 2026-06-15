<?php
// components/col_schedule.php
?>
<div class="column col-schedule" style="padding: 15px; flex:1; display:flex; flex-direction:column; gap:20px;">
    <?php
    $base_days = getScheduleBaseDays($project_info);
    $primary_due_date = $project_info['primary_due_date'] ?? null;

    $schedulesToRender = [];

    if (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1) {
        $schedulesToRender[] = [
            'title' => '許容応力度・基礎横架材計算',
            'type' => 'permit',
            'steps' => getScheduleSteps($base_days),
            'actuals_col' => 'schedule_actuals'
        ];
    }
    if (($project_info['req_wall'] ?? 0) == 1) {
        $schedulesToRender[] = [
            'title' => '壁量計算',
            'type' => 'wall',
            'steps' => getScheduleStepsWall($base_days),
            'actuals_col' => 'schedule_actuals_wall'
        ];
    }
    if (($project_info['req_skin'] ?? 0) == 1) {
        $schedulesToRender[] = [
            'title' => '外皮計算',
            'type' => 'skin',
            'steps' => getScheduleStepsSkin($base_days),
            'actuals_col' => 'schedule_actuals_skin'
        ];
    }
    if (($project_info['req_sky'] ?? 0) == 1) {
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
                        <th style="padding:6px; text-align:left;">工程</th>
                        <th style="padding:6px; text-align:left;">担当</th>
                        <th style="padding:6px; text-align:left;">予定</th>
                        <th style="padding:6px; text-align:left;">実施日</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $calc_date = $primary_due_date; 
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
                    
                    if ($primary_due_date) {
                        if ($idx == 0) {
                            $date_str = '<span style="color:#64748b;">-</span>';
                        } elseif ($idx == 1) {
                            $calc_date = $primary_due_date;
                            $date_str = '<strong>' . date('m/d', strtotime($primary_due_date)) . '</strong>';
                        } else {
                            if ($step['type'] == 'biz') {
                                $calc_date = addBusinessDays($calc_date, $step['days']);
                            } elseif ($step['type'] == 'cal') {
                                $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                            }
                            $date_str = date('m/d', strtotime($calc_date));
                        }
                    }

                    // 実施日があればそれを起算日に上書きする
                    $actual_date = $schedule_actuals[$idx] ?? '';
                    if ($actual_date) {
                        $calc_date = $actual_date;
                        $date_str = '<span style="color:#10b981; font-weight:bold;">' . date('m/d', strtotime($actual_date)) . ' (済)</span>';
                    }

                    // 実施日表示または入力フォーム
                    $actual_display = '';
                    if ($is_admin) {
                        if ($primary_due_date) {
                            $actual_display = '
                            <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:5px; align-items:center;">
                                <input type="hidden" name="action" value="update_schedule_actual">
                                <input type="hidden" name="schedule_type" value="'.htmlspecialchars($scheduleItem['type'], ENT_QUOTES).'">
                                <input type="hidden" name="step_idx" value="'.$idx.'">
                                <input type="date" name="actual_date" value="'.htmlspecialchars($actual_date, ENT_QUOTES).'" style="font-size:10px; padding:2px;">
                                <button type="submit" style="font-size:10px; padding:2px 5px; background:#e2e8f0; border:1px solid #cbd5e1; border-radius:3px; cursor:pointer;">保存</button>
                            </form>';
                        } else {
                            $actual_display = '<span style="color:#aaa;">-</span>';
                        }
                    } elseif ($is_accountant) {
                        $actual_display = $actual_date ? '<strong>' . date('m/d', strtotime($actual_date)) . '</strong>' : '<span style="color:#aaa;">-</span>';
                    }

                    echo "<tr style='background:{$bg_color}; border-bottom:1px solid #e2e8f0;'>";
                    echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step['name']}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
                    echo "<td style='padding:6px;'>{$badge}</td>";
                    echo "<td style='padding:6px;'>{$date_str}</td>";
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
