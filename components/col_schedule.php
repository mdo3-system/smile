<?php
// components/col_schedule.php
?>
<div class="column col-schedule" style="padding: 15px; flex:1; display:flex; flex-direction:column; gap:20px;">
    <!-- ▼▼▼ 進捗スケジュール可視化 ▼▼▼ -->
    <div class="box" style="background:#fff; border-color:#cbd5e1; margin-top:0;">
        <h3 style="margin-top:0; font-size:14px; color:#1e293b; border-bottom:1px solid #cbd5e1; padding-bottom:5px; display:flex; align-items:center; gap:5px;">
            📅 申請図書UPまでのスケジュール
        </h3>
        <div style="font-size:12px; color:#dc2626; font-weight:bold; margin-bottom:10px; background:#fef2f2; border:1px solid #fecaca; padding:8px; border-radius:4px;">
            ⚠️ 一次回答の期限は、設計に必要な図書が全て揃った（アップロード完了）時点で再設定（確定）されます。
        </div>
        
        <?php
        // 計算タイプ別の納期判定
        $req_permit = $project_info['req_permit'] ?? 0;
        $req_wall = $project_info['req_wall'] ?? 0;
        $req_skin = $project_info['req_skin'] ?? 0;
        $req_sky = $project_info['req_sky'] ?? 0;
        $req_opt_kisohari = $project_info['req_opt_kisohari'] ?? 0;

        $base_days = 12;
        if ($req_permit == 1 || $req_opt_kisohari == 1) {
            $base_days = 12;
        } elseif ($req_wall == 1) {
            $base_days = 7;
        } elseif ($req_skin == 1 || $req_sky == 1) {
            $base_days = 10;
        }

        $primary_due_date = $project_info['primary_due_date'] ?? null;
        
        // スケジュール定義 (FIXED_LOGIC.md 準拠)
        $schedule_steps = [
            ['name' => '設計図書の受領', 'actor' => 'client', 'desc' => '開始時', 'days' => 0, 'type' => 'base'],
            ['name' => '着手基準日 (一次回答)', 'actor' => 'designer', 'desc' => "{$base_days}営業日程度", 'days' => $base_days, 'type' => 'biz'],
            ['name' => '構造計算・図面 初回提示', 'actor' => 'designer', 'desc' => '着手から7〜10営業日', 'days' => 10, 'type' => 'biz'],
            ['name' => '構造図CB (内容確認)', 'actor' => 'client', 'desc' => '初回提示から4営業日', 'days' => 4, 'type' => 'biz'],
            ['name' => '修正図面UP', 'actor' => 'designer', 'desc' => 'CB確認から3営業日', 'days' => 3, 'type' => 'biz'],
            ['name' => '申請図書一式UP', 'actor' => 'designer', 'desc' => '修正UPから3営業日', 'days' => 3, 'type' => 'biz'],
            ['name' => '質疑・審査待機', 'actor' => 'wait', 'desc' => '確認機関の審査', 'days' => 30, 'type' => 'cal'],
            ['name' => '補正対応', 'actor' => 'designer', 'desc' => '質疑受領から7営業日', 'days' => 7, 'type' => 'biz'],
            ['name' => '残金のご精算', 'actor' => 'client', 'desc' => '完了後7日以内', 'days' => 7, 'type' => 'cal'],
        ];

        // 日付計算
        $current_date = $primary_due_date ? date('Y-m-d', strtotime("-{$base_days} weekdays", strtotime($primary_due_date))) : null; 
        if ($primary_due_date) {
            $current_calc_date = $primary_due_date;
        }

        echo '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
        echo '<thead><tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;"><th style="padding:6px; text-align:left;">工程</th><th style="padding:6px; text-align:left;">担当</th><th style="padding:6px; text-align:left;">予定</th><th style="padding:6px; text-align:left;">実施日</th></tr></thead>';
        echo '<tbody>';
        
        $calc_date = $primary_due_date; 
        $schedule_actuals = json_decode($project_info['schedule_actuals'] ?? '{}', true) ?: [];
        
        foreach ($schedule_steps as $idx => $step) {
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

            // 実施日入力フォーム (管理者のみ、一次回答日設定後)
            $actual_form = '';
            if ($is_admin && $primary_due_date) {
                $actual_form = '
                <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:5px; align-items:center;">
                    <input type="hidden" name="action" value="update_schedule_actual">
                    <input type="hidden" name="step_idx" value="'.$idx.'">
                    <input type="date" name="actual_date" value="'.htmlspecialchars($actual_date, ENT_QUOTES).'" style="font-size:10px; padding:2px;">
                    <button type="submit" style="font-size:10px; padding:2px 5px; background:#e2e8f0; border:1px solid #cbd5e1; border-radius:3px; cursor:pointer;">保存</button>
                </form>';
            }

            echo "<tr style='background:{$bg_color}; border-bottom:1px solid #e2e8f0;'>";
            echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step['name']}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
            echo "<td style='padding:6px;'>{$badge}</td>";
            echo "<td style='padding:6px;'>{$date_str}</td>";
            echo "<td style='padding:6px;'>{$actual_form}</td>";
            echo "</tr>";
        }
        echo '</tbody></table>';
        ?>
    </div>
    <!-- ▲▲▲ 進捗スケジュール可視化 ▲▲▲ -->
</div>
