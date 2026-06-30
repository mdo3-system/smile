<?php
namespace App\Services;

use PDO;
use Exception;
use DateTime;

/**
 * 経理・売上・支払い管理（買掛金等）のビジネスロジックを担うサービス
 */
class SalesFinanceService {

    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 指定月の締め期間（前月26日〜当月25日）を取得
     */
    public function getClosingPeriod(string $current_month): array {
        $dt = new DateTime($current_month . '-25 23:59:59');
        $end_date = $dt->format('Y-m-d H:i:s');
        $dt->modify('-1 month')->modify('+1 day')->setTime(0, 0, 0);
        $start_date = $dt->format('Y-m-d H:i:s');
        
        return [
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
    }

    /**
     * 経理サマリー及び案件売上・入金リストの取得
     */
    public function getSalesSummary(string $current_month): array {
        $period = $this->getClosingPeriod($current_month);

        // A. 当月内の「実際に入金された」総額 (実績)
        $stmtActualDeposit = $this->pdo->prepare("
            SELECT SUM(deposit_amount) as total 
            FROM projects 
            WHERE DATE_FORMAT(deposit_date, '%Y-%m') = :m
        ");
        $stmtActualDeposit->execute(['m' => $current_month]);
        $actual_deposit_total = intval($stmtActualDeposit->fetch()['total'] ?? 0);

        // B. 当月内の「実際に支払われた」総額 (支払済実績)
        $stmtActualPayment = $this->pdo->prepare("
            SELECT SUM(order_amount) as total 
            FROM subcontractor_orders 
            WHERE DATE_FORMAT(payment_date, '%Y-%m') = :m AND payment_status = 'paid'
        ");
        $stmtActualPayment->execute(['m' => $current_month]);
        $actual_payment_total = intval($stmtActualPayment->fetch()['total'] ?? 0);

        // C. 当月締め対象の「支払予定総額（買掛金）」(今月締め分の全タスク)
        $stmtExpectedPayment = $this->pdo->prepare("
            SELECT SUM(order_amount) as total 
            FROM subcontractor_orders 
            WHERE status = 'completed'
              AND completed_at >= :sd AND completed_at <= :ed
        ");
        $stmtExpectedPayment->execute(['sd' => $period['start_date'], 'ed' => $period['end_date']]);
        $expected_payment_total = intval($stmtExpectedPayment->fetch()['total'] ?? 0);

        // 案件一覧の取得
        $stmtProjects = $this->pdo->prepare("
            SELECT p.*, u.company_name, u.contact_name,
                   (SELECT total_price FROM estimates e WHERE e.project_id = p.id ORDER BY e.id DESC LIMIT 1) as formal_estimate
            FROM projects p
            JOIN users u ON p.client_id = u.id
            WHERE p.status != 'completed' 
              AND (DATE_FORMAT(p.created_at, '%Y-%m') = :m OR p.deposit_status != 'paid')
            ORDER BY (p.last_manual_chat_at IS NULL) ASC, p.last_manual_chat_at DESC, (p.primary_due_date IS NULL) ASC, p.primary_due_date ASC, FIELD(p.status, 'quote_req', 'doc_submitted', 'primary_prep', 'contracted', 'structural_dwg', 'submission', 'submitting', 'correction', 'completed') ASC, p.project_name ASC
        ");
        $stmtProjects->execute(['m' => $current_month]);
        $projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

        $total_sales = 0;
        $total_deposit = 0;
        $total_balance = 0;
        $sales_list = [];

        foreach ($projects as $p) {
            $est = ($p['formal_estimate'] !== null) ? round($p['formal_estimate'] * 1.1) : 0;
            $add = intval($p['additional_amount'] ?? 0);
            $dep = intval($p['deposit_amount'] ?? 0);
            $req = $est + $add;
            $bal = $req - $dep;
            
            $is_current_month = (date('Y-m', strtotime($p['created_at'])) === $current_month);
            if ($is_current_month) {
                $total_sales += $req;
                $total_deposit += $dep;
                $total_balance += $bal;
            }
            
            $sales_list[] = array_merge($p, [
                'req_total' => $req,
                'balance' => $bal,
                'is_current_month' => $is_current_month
            ]);
        }

        return [
            'actual_deposit_total' => $actual_deposit_total,
            'actual_payment_total' => $actual_payment_total,
            'expected_payment_total' => $expected_payment_total,
            'total_sales' => $total_sales,
            'total_deposit' => $total_deposit,
            'total_balance' => $total_balance,
            'sales_list' => $sales_list
        ];
    }

    /**
     * 協力業者 支払管理（25日締め）データ取得
     */
    public function getSubcontractorPayments(string $current_month): array {
        $period = $this->getClosingPeriod($current_month);

        $stmtSubs = $this->pdo->prepare("
            SELECT o.*, u.contact_name, p.project_name
            FROM subcontractor_orders o
            JOIN users u ON o.subcontractor_id = u.id
            JOIN projects p ON o.project_id = p.id
            WHERE o.status = 'completed'
              AND o.completed_at >= :sd AND o.completed_at <= :ed
            ORDER BY u.contact_name, o.completed_at ASC
        ");
        $stmtSubs->execute(['sd' => $period['start_date'], 'ed' => $period['end_date']]);
        $sub_orders = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

        $payments_by_sub = [];
        foreach ($sub_orders as $o) {
            $sub_name = $o['contact_name'];
            if (!isset($payments_by_sub[$sub_name])) {
                $payments_by_sub[$sub_name] = ['total' => 0, 'tasks' => []];
            }
            $payments_by_sub[$sub_name]['total'] += $o['order_amount'];
            $payments_by_sub[$sub_name]['tasks'][] = $o;
        }

        return $payments_by_sub;
    }

    /**
     * 依頼主の入金・ステータス更新処理
     */
    public function updateDeposit(int $project_id, int $deposit_amount, ?string $deposit_date, string $status, int $sender_id): void {
        $this->pdo->beginTransaction();
        try {
            // 請求総額(税込)を計算する
            $stmtEst = $this->pdo->prepare("SELECT total_price FROM estimates WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
            $stmtEst->execute(['pid' => $project_id]);
            $est_price = $stmtEst->fetchColumn() ?: 0;
            $est_price_tax = round($est_price * 1.1);
            
            $stmtProjAdd = $this->pdo->prepare("SELECT additional_amount FROM projects WHERE id = :pid");
            $stmtProjAdd->execute(['pid' => $project_id]);
            $additional_amount = intval($stmtProjAdd->fetchColumn() ?: 0);
            
            $req_total = $est_price_tax + $additional_amount;
            
            // 入金状況を自動決定
            $deposit_status = 'unpaid';
            if ($deposit_amount >= $req_total) {
                $deposit_status = 'paid';
            } elseif ($deposit_amount > 0) {
                $deposit_status = 'partially_paid';
            }
            
            // 変更前データの取得
            $stmtOld = $this->pdo->prepare("SELECT deposit_amount, deposit_date, deposit_status, status FROM projects WHERE id = :id");
            $stmtOld->execute(['id' => $project_id]);
            $old_data = $stmtOld->fetch(PDO::FETCH_ASSOC);
            
            // 入金情報更新
            $stmt = $this->pdo->prepare("
                UPDATE projects 
                SET deposit_amount = :dep_amt, 
                    deposit_date = :dep_date, 
                    deposit_status = :dep_status
                WHERE id = :id
            ");
            $stmt->execute([
                'dep_amt' => $deposit_amount,
                'dep_date' => $deposit_date,
                'dep_status' => $deposit_status,
                'id' => $project_id
            ]);
            
            // 案件ステータスの更新（選択されている場合）
            if (!empty($status)) {
                $stmtStatus = $this->pdo->prepare("UPDATE projects SET status = :status WHERE id = :id");
                $stmtStatus->execute(['status' => $status, 'id' => $project_id]);
            }
            
            // チャットへの自動通知挿入
            if ($old_data) {
                $status_labels_local = [
                    'unpaid' => '未入金',
                    'partially_paid' => '一部入金',
                    'paid' => '完済'
                ];
                $proj_status_labels = [
                    'quote_req' => '見積依頼', 
                    'quote_sent' => '見積送付済', 
                    'doc_submitted' => '図書提出済', 
                    'primary_prep' => '一次回答準備中', 
                    'contracted' => '受注済', 
                    'structural_dwg' => '申請図書作成中', 
                    'submission' => '提出済・確認中', 
                    'submitting' => '申請中',
                    'correction' => '補正対応中', 
                    'completed' => '完了'
                ];
                
                $changes = [];
                if (intval($old_data['deposit_amount']) !== $deposit_amount || $old_data['deposit_status'] !== $deposit_status || $old_data['deposit_date'] !== $deposit_date) {
                    $old_status_text = $status_labels_local[$old_data['deposit_status']] ?? $old_data['deposit_status'];
                    $new_status_text = $status_labels_local[$deposit_status] ?? $deposit_status;
                    $changes[] = "・入金状況: {$old_status_text} ➔ {$new_status_text}\n  入金額: " . number_format($deposit_amount) . "円\n  入金日: " . ($deposit_date ?: '未設定');
                }
                
                if (!empty($status) && $old_data['status'] !== $status) {
                    $old_p_status = $proj_status_labels[$old_data['status']] ?? $old_data['status'];
                    $new_p_status = $proj_status_labels[$status] ?? $status;
                    $changes[] = "・案件ステータス: {$old_p_status} ➔ {$new_p_status}";
                }
                
                if (!empty($changes)) {
                    $msg_text = "【経理情報更新】\n経理担当（または管理者）が案件情報を更新しました。\n" . implode("\n", $changes);
                    
                    $stmtMsg = $this->pdo->prepare("
                        INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                        VALUES (:pid, :sid, 'client_admin', :msg)
                    ");
                    $stmtMsg->execute([
                        'pid' => $project_id,
                        'sid' => $sender_id,
                        'msg' => $msg_text
                    ]);
                }
            }
            
            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 協力業者支払状況の更新処理
     */
    public function updateSubcontractorPayment(int $order_id, string $payment_status, ?string $payment_date, int $sender_id): void {
        $this->pdo->beginTransaction();
        try {
            // 変更前データの取得
            $stmtOrder = $this->pdo->prepare("SELECT project_id, subcontractor_id, task_title, payment_status FROM subcontractor_orders WHERE id = :id");
            $stmtOrder->execute(['id' => $order_id]);
            $order_info = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            
            // 支払い状況更新
            $stmt = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET payment_status = :pay_status,
                    payment_date = :pay_date
                WHERE id = :id
            ");
            $stmt->execute([
                'pay_status' => $payment_status,
                'pay_date' => $payment_date,
                'id' => $order_id
            ]);
            
            // チャットへの自動通知挿入
            if ($order_info && $order_info['payment_status'] !== $payment_status) {
                $pay_status_labels = [
                    'unpaid' => '未払',
                    'paid' => '支払済'
                ];
                $old_p_text = $pay_status_labels[$order_info['payment_status']] ?? $order_info['payment_status'];
                $new_p_text = $pay_status_labels[$payment_status] ?? $payment_status;
                
                $msg_text = "【お支払い状況更新】\n経理担当がタスク「{$order_info['task_title']}」のお支払い状況を更新しました。\n・支払状況: {$old_p_text} ➔ {$new_p_text}\n・支払日: " . ($payment_date ?: '未設定');
                
                $stmtMsg = $this->pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $order_info['project_id'],
                    'sid' => $sender_id,
                    'msg' => $msg_text
                ]);
            }
            
            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
