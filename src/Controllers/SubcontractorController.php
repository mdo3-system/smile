<?php
namespace App\Controllers;

use App\Container\AppContainer;
use App\Services\SubcontractorOrderService;
use Exception;

class SubcontractorController
{
    private AppContainer $container;
    private SubcontractorOrderService $orderService;

    public function __construct(?SubcontractorOrderService $orderService = null)
    {
        $this->container = AppContainer::getInstance();
        $this->orderService = $orderService ?: new SubcontractorOrderService($this->container->getPDO());
    }

    /**
     * POSTリクエストのアクションを処理する
     */
    public function handlePostRequest(int $userId, bool $isAdmin, array $postData, array $files): void
    {
        $action = $postData['action'] ?? '';
        $orderId = intval($postData['order_id'] ?? 0);
        $projectId = intval($postData['project_id'] ?? 0);

        try {
            // 1. 承諾処理 (POSTデータに order_id はあり、action キーはない、かつ expected_delivery_date が指定されている場合)
            if ($orderId > 0 && !isset($postData['action']) && isset($postData['expected_delivery_date'])) {
                $expectedDate = $postData['expected_delivery_date'];
                $this->orderService->acceptOrder($orderId, $userId, $expectedDate);
                
                $redirectUrl = "project_subcontractor.php?id=" . $this->getProjectIdFromOrder($orderId) . "&t=" . time();
                $this->redirect($redirectUrl);
                return;
            }

            // 2. 拒否（辞退）処理
            if ($action === 'reject_order' && $orderId > 0) {
                $this->orderService->rejectOrder($orderId, $userId);
                $this->redirect("subcontractor_portal.php");
                return;
            }

            // 3. キャンセル処理
            if ($action === 'cancel_order' && $orderId > 0 && $isAdmin) {
                $this->orderService->cancelOrder($orderId, $userId);
                
                $redirectUrl = "project_subcontractor.php?id=" . $projectId . "&t=" . time();
                $this->redirect($redirectUrl);
                return;
            }

            // 4. 発注内容更新処理
            if ($action === 'update_order_details' && $orderId > 0 && $isAdmin) {
                $taskTitle = trim($postData['task_title'] ?? '');
                $orderAmount = intval($postData['order_amount'] ?? 0);
                $completedAt = !empty($postData['completed_at']) ? $postData['completed_at'] : null;

                $this->orderService->updateOrderDetails($orderId, $taskTitle, $orderAmount, $completedAt);
                
                $redirectUrl = "project_subcontractor.php?id=" . $projectId . "&t=" . time();
                $this->redirect($redirectUrl);
                return;
            }

            // 5. 公開・非表示切り替え
            if ($action === 'toggle_publish_sub' && $isAdmin) {
                $fileId = intval($postData['file_id'] ?? 0);
                $publishVal = intval($postData['publish_val'] ?? 0);
                
                $this->orderService->togglePublishSub($fileId, $projectId, $publishVal);
                
                $redirectUrl = "project_subcontractor.php?id=" . $projectId . "&t=" . time();
                $this->redirect($redirectUrl);
                return;
            }

            // 6. 納品処理
            if ($action === 'deliver_task' && $orderId > 0 && $projectId > 0) {
                $subCompanyId = $userId;
                if (!$isAdmin) {
                    $pdo = $this->container->getPDO();
                    $stmtParent = $pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
                    $stmtParent->execute(['id' => $userId]);
                    $p_id = $stmtParent->fetchColumn();
                    if ($p_id) {
                        $subCompanyId = (int)$p_id;
                    }
                }
                
                $viaArchiserver = isset($postData['via_archiserver']) && $postData['via_archiserver'] == '1';
                $deliverType = $postData['deliver_type'] ?? null;
                $userRole = $_SESSION['role'] ?? 'subcontractor';

                $this->orderService->deliverTask(
                    $orderId,
                    $projectId,
                    $userId,
                    $subCompanyId,
                    $files,
                    $viaArchiserver,
                    $deliverType,
                    $userRole
                );
                
                $redirectUrl = "project_subcontractor.php?id=" . $projectId . "&t=" . time();
                $this->redirect($redirectUrl);
                return;
            }
        } catch (Exception $e) {
            die("処理に失敗しました: " . $e->getMessage());
        }
    }

    /**
     * リダイレクトを処理する（テスト時にモック可能）
     */
    protected function redirect(string $url): void
    {
        if (defined('PHPUNIT_RUNNING')) {
            return; // テスト時はヘッダー出力を避ける
        }
        header("Location: " . $url);
        exit;
    }

    /**
     * 発注IDからプロジェクトIDを取得する
     */
    private function getProjectIdFromOrder(int $orderId): int
    {
        $pdo = $this->container->getPDO();
        $stmtP = $pdo->prepare("SELECT project_id FROM subcontractor_orders WHERE id = :id");
        $stmtP->execute(['id' => $orderId]);
        return intval($stmtP->fetchColumn() ?: 0);
    }
}
