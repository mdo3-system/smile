<?php
namespace App\Services;

use PDO;
use Exception;

class SubcontractorOrderService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 発注を承諾する
     *
     * @param int $orderId
     * @param int $subcontractorId
     * @param string $expectedDeliveryDate
     * @return bool
     * @throws Exception
     */
    public function acceptOrder(int $orderId, int $subcontractorId, string $expectedDeliveryDate): bool
    {
        $this->pdo->beginTransaction();
        try {
            // ステータスと完了納期予定日を更新
            $stmt = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET status = 'accepted', expected_delivery_date = :edate, updated_at = NOW() 
                WHERE id = :id AND subcontractor_id = :sub_id
            ");
            $stmt->execute([
                'edate' => $expectedDeliveryDate,
                'id' => $orderId,
                'sub_id' => $subcontractorId
            ]);

            // 案件IDを取得
            $stmtP = $this->pdo->prepare("SELECT project_id FROM subcontractor_orders WHERE id = :id");
            $stmtP->execute(['id' => $orderId]);
            $projectId = $stmtP->fetchColumn();

            if ($projectId) {
                // メッセージ（チャット通知）を挿入
                $msg = "発注を承諾しました。完了予定日: " . date('Y年m月d日', strtotime($expectedDeliveryDate));
                $stmtMsg = $this->pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $projectId,
                    'sid' => $subcontractorId,
                    'msg' => $msg
                ]);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 発注を辞退する
     *
     * @param int $orderId
     * @param int $subcontractorId
     * @return bool
     * @throws Exception
     */
    public function rejectOrder(int $orderId, int $subcontractorId): bool
    {
        $this->pdo->beginTransaction();
        try {
            // ステータスを rejected に更新
            $stmt = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET status = 'rejected', updated_at = NOW() 
                WHERE id = :id AND subcontractor_id = :sub_id
            ");
            $stmt->execute([
                'id' => $orderId,
                'sub_id' => $subcontractorId
            ]);

            // 案件IDを取得
            $stmtP = $this->pdo->prepare("SELECT project_id FROM subcontractor_orders WHERE id = :id");
            $stmtP->execute(['id' => $orderId]);
            $projectId = $stmtP->fetchColumn();

            if ($projectId) {
                // メッセージ（チャット通知）を挿入
                $msg = "発注を辞退（拒否）しました。";
                $stmtMsg = $this->pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $projectId,
                    'sid' => $subcontractorId,
                    'msg' => $msg
                ]);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
