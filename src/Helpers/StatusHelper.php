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
    public static function getBallStatus(array $project, PDO $pdo): array
    {
        $status = $project['status'] ?? '';

        if ($status === 'completed') {
            return [
                'ball_owner' => 'completed',
                'label' => '完了',
                'color' => '#10b981' // Green
            ];
        }

        if ($status === 'quote_req') {
            // Check if there is an estimate issued for this project
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM estimates WHERE project_id = :pid");
            $stmt->execute(['pid' => $project['id']]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                // Estimate exists -> Client has the ball (見積確認中)
                return [
                    'ball_owner' => 'client',
                    'label' => '回答待ち (依頼主ボール)',
                    'color' => '#e67e22' // Orange
                ];
            } else {
                // No estimate -> Admin has the ball (見積作成中)
                return [
                    'ball_owner' => 'admin',
                    'label' => '図書作成中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }

        // Fetch active subcontractor tasks (not cancelled)
        $stmtTasks = $pdo->prepare("SELECT * FROM subcontractor_orders WHERE project_id = :pid AND status != 'cancelled'");
        $stmtTasks->execute(['pid' => $project['id']]);
        $tasks = $stmtTasks->fetchAll();

        if (count($tasks) > 0) {
            $has_sub_ball = false;
            $has_delivered_task = false;
            foreach ($tasks as $task) {
                if ($task['status'] === 'requested' || $task['status'] === 'accepted') {
                    $has_sub_ball = true;
                } elseif ($task['status'] === 'delivered') {
                    $has_delivered_task = true;
                }
            }

            if ($has_sub_ball) {
                return [
                    'ball_owner' => 'subcontractor',
                    'label' => '作成中 (協力業者ボール)',
                    'color' => '#8b5cf6' // Purple
                ];
            }

            if ($has_delivered_task) {
                return [
                    'ball_owner' => 'admin',
                    'label' => '納品検収中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }

        // If no active subcontractor tasks are in progress, let's look at project status
        if ($status === 'submission') {
            return [
                'ball_owner' => 'shared_waiting',
                'label' => '審査待ち (共通)',
                'color' => '#f59e0b' // Amber
            ];
        }

        if ($status === 'primary_prep' || $status === 'structural_dwg' || $status === 'correction') {
            return [
                'ball_owner' => 'admin',
                'label' => '図書作成中 (管理者ボール)',
                'color' => '#3b82f6' // Blue
            ];
        }

        if ($status === 'contracted') {
            return [
                'ball_owner' => 'admin',
                'label' => '図書作成中 (管理者ボール)',
                'color' => '#3b82f6'
            ];
        }

        return [
            'ball_owner' => 'admin',
            'label' => '図書作成中 (管理者ボール)',
            'color' => '#3b82f6'
        ];
    }
}
