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
            // 現在の発注レコードの subcontractor_id を取得
            $stmtGet = $this->pdo->prepare("SELECT subcontractor_id FROM subcontractor_orders WHERE id = :id");
            $stmtGet->execute(['id' => $orderId]);
            $assignedSubId = $stmtGet->fetchColumn();

            if (!$assignedSubId) {
                throw new Exception("発注レコードが見つかりません。");
            }

            // 承諾者の親IDを取得 (グループ確認用)
            $stmtParent = $this->pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
            $stmtParent->execute(['id' => $subcontractorId]);
            $subParentId = $stmtParent->fetchColumn();

            // 承諾権限の確認
            $isAuthorized = false;
            if ($assignedSubId == $subcontractorId) {
                $isAuthorized = true;
            } elseif ($subParentId && $assignedSubId == $subParentId) {
                $isAuthorized = true;
            } else {
                // 親が承諾者で、スタッフに発注されている場合も許可
                $stmtCheckParent = $this->pdo->prepare("SELECT parent_id FROM users WHERE id = :assigned_id");
                $stmtCheckParent->execute(['assigned_id' => $assignedSubId]);
                $assignedParentId = $stmtCheckParent->fetchColumn();
                if ($assignedParentId == $subcontractorId) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
                throw new Exception("この発注を承諾する権限がありません。");
            }

            // ステータス、完了納期予定日を更新。さらに担当者を承諾した本人に自動更新
            $stmt = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET status = 'accepted', 
                    subcontractor_id = :sub_id, 
                    expected_delivery_date = :edate, 
                    updated_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([
                'edate' => $expectedDeliveryDate,
                'sub_id' => $subcontractorId,
                'id' => $orderId
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

                // メール通知
                if (function_exists('sendChatEmailNotification')) {
                    $stmtRole = $this->pdo->prepare("SELECT role FROM users WHERE id = :uid");
                    $stmtRole->execute(['uid' => $subcontractorId]);
                    $senderRole = $stmtRole->fetchColumn() ?: 'subcontractor';
                    
                    sendChatEmailNotification($projectId, $subcontractorId, $senderRole, 'sub_admin', $msg, $this->pdo);
                }
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
            // 現在の発注レコードの subcontractor_id を取得
            $stmtGet = $this->pdo->prepare("SELECT subcontractor_id FROM subcontractor_orders WHERE id = :id");
            $stmtGet->execute(['id' => $orderId]);
            $assignedSubId = $stmtGet->fetchColumn();

            if (!$assignedSubId) {
                throw new Exception("発注レコードが見つかりません。");
            }

            // 承諾者の親IDを取得
            $stmtParent = $this->pdo->prepare("SELECT parent_id FROM users WHERE id = :id");
            $stmtParent->execute(['id' => $subcontractorId]);
            $subParentId = $stmtParent->fetchColumn();

            // 辞退権限の確認
            $isAuthorized = false;
            if ($assignedSubId == $subcontractorId) {
                $isAuthorized = true;
            } elseif ($subParentId && $assignedSubId == $subParentId) {
                $isAuthorized = true;
            } else {
                $stmtCheckParent = $this->pdo->prepare("SELECT parent_id FROM users WHERE id = :assigned_id");
                $stmtCheckParent->execute(['assigned_id' => $assignedSubId]);
                $assignedParentId = $stmtCheckParent->fetchColumn();
                if ($assignedParentId == $subcontractorId) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
                throw new Exception("この発注を辞退する権限がありません。");
            }

            // ステータスを rejected に更新
            $stmt = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET status = 'rejected', updated_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $orderId
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

                // メール通知
                if (function_exists('sendChatEmailNotification')) {
                    $stmtRole = $this->pdo->prepare("SELECT role FROM users WHERE id = :uid");
                    $stmtRole->execute(['uid' => $subcontractorId]);
                    $senderRole = $stmtRole->fetchColumn() ?: 'subcontractor';
                    
                    sendChatEmailNotification($projectId, $subcontractorId, $senderRole, 'sub_admin', $msg, $this->pdo);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 発注をキャンセルする（設計管理者・経理用）
     *
     * @param int $orderId
     * @param int $userId キャンセルを行ったユーザーID（通常は管理者/経理）
     * @return bool
     * @throws Exception
     */
    public function cancelOrder(int $orderId, int $userId): bool
    {
        $this->pdo->beginTransaction();
        try {
            // ステータスを cancelled に更新
            $stmt = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET status = 'cancelled', updated_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $orderId
            ]);

            // 案件IDと発注時の業者IDを取得
            $stmtP = $this->pdo->prepare("SELECT project_id, subcontractor_id, task_title FROM subcontractor_orders WHERE id = :id");
            $stmtP->execute(['id' => $orderId]);
            $orderInfo = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($orderInfo) {
                // メッセージ（チャット通知）を挿入
                $msg = "【自動通知】「" . $orderInfo['task_title'] . "」の発注依頼がキャンセルされました。\n現在までの費用をご請求してください。";
                $stmtMsg = $this->pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtMsg->execute([
                    'pid' => $orderInfo['project_id'],
                    'sid' => $userId,
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
     * 発注内容を更新する（管理者・経理用）
     */
    public function updateOrderDetails(int $orderId, string $taskTitle, int $orderAmount, ?string $completedAt): bool
    {
        // 経理が支払い完了したかチェック
        $stmtCheck = $this->pdo->prepare("SELECT payment_status FROM subcontractor_orders WHERE id = :id");
        $stmtCheck->execute(['id' => $orderId]);
        $payment_status = $stmtCheck->fetchColumn();

        if ($payment_status !== 'paid') {
            $stmtUpdate = $this->pdo->prepare("
                UPDATE subcontractor_orders 
                SET task_title = :title, 
                    order_amount = :amt, 
                    completed_at = :completed
                WHERE id = :id
            ");
            return $stmtUpdate->execute([
                'title' => $taskTitle,
                'amt' => $orderAmount,
                'completed' => $completedAt,
                'id' => $orderId
            ]);
        }
        return false;
    }

    /**
     * 協力業者向け公開・非表示の切り替え（管理者用）
     */
    public function togglePublishSub(int $fileId, int $projectId, int $publishVal): bool
    {
        if ($fileId > 0 && $projectId > 0) {
            $stmt = $this->pdo->prepare("UPDATE project_files SET is_published_to_sub = :pub WHERE id = :id AND project_id = :pid");
            return $stmt->execute(['pub' => $publishVal, 'id' => $fileId, 'pid' => $projectId]);
        }
        return false;
    }

    /**
     * 成果物の納品処理を行う（協力業者用）
     */
    public function deliverTask(
        int $orderId,
        int $projectId,
        int $userId,
        int $subCompanyId,
        array $files,
        bool $viaArchiserver,
        ?string $deliverType,
        string $userRole
    ): bool {
        require_once __DIR__ . '/../../google_drive_client.php';
        require_once __DIR__ . '/../../functions.php';

        $this->pdo->beginTransaction();
        try {
            $files_to_upload = [
                'architrend_design' => 'sub_architrend_design',
                'architrend_struct' => 'sub_architrend_struct',
                'structural_pdf'  => 'sub_structural_pdf'
            ];
            
            $uploaded_any = false;
            
            foreach ($files_to_upload as $input_name => $category) {
                if (isset($files[$input_name]) && $files[$input_name]['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $files[$input_name]['tmp_name'];
                    $file_name = $files[$input_name]['name'];
                    $mime_type = $files[$input_name]['type'];
                    
                    $drive_file_id = upload_to_google_drive($file_tmp, $file_name, $mime_type, $projectId, $this->pdo);
                    
                    // 1. 最新バージョンの確認
                    $stmtVer = $this->pdo->prepare("SELECT MAX(version) as max_v FROM project_files WHERE project_id = :pid AND file_category = :cat");
                    $stmtVer->execute(['pid' => $projectId, 'cat' => $category]);
                    $max_v = $stmtVer->fetch()['max_v'] ?? 0;
                    $new_v = $max_v + 1;
                    
                    // 2. 過去のファイルの is_latest を 0 に更新
                    $stmtUpdateLatest = $this->pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
                    $stmtUpdateLatest->execute(['pid' => $projectId, 'cat' => $category]);
                    
                    // 3. 新しいファイルを登録 (これらは管理者と業者の間のみで表示される)
                    $stmtInsertFile = $this->pdo->prepare("
                        INSERT INTO project_files (project_id, subcontractor_order_id, file_category, file_name, drive_file_id, version, is_latest) 
                        VALUES (:pid, :order_id, :cat, :fname, :fpath, :ver, 1)
                    ");
                    $stmtInsertFile->execute([
                        'pid' => $projectId,
                        'order_id' => $orderId,
                        'cat' => $category,
                        'fname' => $file_name,
                        'fpath' => $drive_file_id,
                        'ver' => $new_v
                    ]);
                    $uploaded_any = true;
                }
            }
            
            if ($uploaded_any || $via_archiserver) {
                // 発注ステータスを delivered (納品済) に更新
                $stmtOrder = $this->pdo->prepare("
                    UPDATE subcontractor_orders 
                    SET status = 'delivered', updated_at = NOW() 
                    WHERE id = :id 
                      AND (subcontractor_id = :sub_id OR subcontractor_id IN (SELECT id FROM users WHERE parent_id = :parent_id))
                ");
                $stmtOrder->execute([
                    'id' => $orderId,
                    'sub_id' => $subCompanyId,
                    'parent_id' => $subCompanyId
                ]);

                // 協力業者から管理者への納品報告チャットを自動登録
                $stmtGetSubName = $this->pdo->prepare("SELECT contact_name FROM users WHERE id = :uid");
                $stmtGetSubName->execute(['uid' => $userId]);
                $sub_name = $stmtGetSubName->fetchColumn() ?: '協力業者';

                $deliver_type_label = '';
                if ($deliverType === 'design') {
                    $deliver_type_label = '（意匠図）';
                } elseif ($deliverType === 'struct') {
                    $deliver_type_label = '（構造図）';
                }

                if ($viaArchiserver) {
                    $notify_msg = "【自動通知】{$sub_name} 様より成果物の納品{$deliver_type_label}（アーキトレンドサーバーへのアップロード完了連絡）が行われました。\n";
                } else {
                    $notify_msg = "【自動通知】{$sub_name} 様より成果物の納品{$deliver_type_label}（ファイルアップロード）が行われました。\n";
                }
                $notify_msg .= "管理者画面にて内容をご確認の上、承認（クライアントへの公開）処理を行ってください。";

                $stmtChat = $this->pdo->prepare("
                    INSERT INTO messages (project_id, sender_id, thread_type, message_text) 
                    VALUES (:pid, :sid, 'sub_admin', :msg)
                ");
                $stmtChat->execute([
                    'pid' => $projectId,
                    'sid' => $userId,
                    'msg' => $notify_msg
                ]);

                if (function_exists('sendChatEmailNotification')) {
                    sendChatEmailNotification($projectId, $userId, $userRole, 'sub_admin', $notify_msg, $this->pdo);
                }

                $this->pdo->commit();
                return true;
            } else {
                $this->pdo->rollBack();
                throw new Exception("ファイルが選択されていないか、アーキサーバーへのUPボタンが押されていません。");
            }
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
