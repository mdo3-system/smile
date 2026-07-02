<?php
// cron_reminder_inspection.php
// 毎日1回実行される確認申請「審査合格・進捗確認」の3週間後リマインダー自動送信バッチスクリプト

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

try {
    // 進行中の案件のみを対象にする
    $stmt = $pdo->query("
        SELECT id, project_name, client_id, status, 
               req_permit, req_wall, req_skin, req_sky, req_opt_kisohari,
               schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky,
               inspection_reminder_sent
        FROM projects 
        WHERE status != 'completed' 
        AND inspection_reminder_sent = 0
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime();
    $sent_count = 0;

    foreach ($projects as $project) {
        $actuals_col = 'schedule_actuals';
        $correction_step_idx = 10; // 許容応力デフォルト

        if ($project['req_permit'] == 1 || $project['req_opt_kisohari'] == 1) {
            $actuals_col = 'schedule_actuals';
            $correction_step_idx = 10;
        } elseif ($project['req_wall'] == 1) {
            $actuals_col = 'schedule_actuals_wall';
            $correction_step_idx = 7;
        } elseif ($project['req_skin'] == 1) {
            $actuals_col = 'schedule_actuals_skin';
            $correction_step_idx = 7;
        } elseif ($project['req_sky'] == 1) {
            $actuals_col = 'schedule_actuals_sky';
            $correction_step_idx = 6;
        }

        $actuals = json_decode($project[$actuals_col] ?? '{}', true) ?: [];
        $correction_date_str = $actuals[$correction_step_idx] ?? null;

        if ($correction_date_str) {
            $correction_date = new DateTime($correction_date_str);
            $interval = $today->diff($correction_date);
            $days_diff = (int)$interval->format('%a');

            // 補正対応完了日からちょうど21日(3週間)が経過している場合
            if ($days_diff === 21) {
                // 依頼主企業（親・スタッフ全員）の通知有効メールアドレスを抽出
                $emails = getCompanyNotificationEmails($project['client_id'], $pdo);

                if (!empty($emails)) {
                    $subject = "【設計サポート】確認申請の審査進捗状況のご確認: " . $project['project_name'];
                    
                    $body  = "木造住宅設計サポートをご利用いただきありがとうございます。\n\n";
                    $body .= "案件名: " . $project['project_name'] . "\n\n";
                    $body .= "補正対応のご対応（図面一式UP）から3週間が経過いたしました。\n";
                    $body .= "確認機関による確認申請の審査進捗状況（合格の見込み等）はいかがでしょうか？\n\n";
                    $body .= "審査が合格（審査完了）になりましたら、残金をお振込みいただき、\n";
                    $body .= "ダッシュボード基本情報エリアの「残金お振込み ＆ 審査完了にする」ボタンを押して登録を完了させてください。\n\n";
                    $body .= "▼ダッシュボードはこちら\n";
                    $body .= "https://system.thanks.work/project_detail.php?id=" . $project['id'] . "\n\n";
                    $body .= "よろしくお願い申し上げます。\n";
                    $body .= "担当: 菅原 070-8305-8480";

                    foreach ($emails as $email) {
                        sendSystemEmail($email, $subject, $body);
                    }

                    // 送信済みフラグを1に更新
                    $stmtUpdate = $pdo->prepare("UPDATE projects SET inspection_reminder_sent = 1 WHERE id = :id");
                    $stmtUpdate->execute(['id' => $project['id']]);
                    $sent_count++;
                }
            }
        }
    }

    echo "Cron run completed. Sent $sent_count inspection reminders.\n";
} catch (Exception $e) {
    echo "Cron error: " . $e->getMessage() . "\n";
}
