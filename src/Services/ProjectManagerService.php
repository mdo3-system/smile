<?php
namespace App\Services;

use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\MessageRepositoryInterface;
use App\Domain\Entities\Message;

class ProjectManagerService
{
    private ProjectRepositoryInterface $projectRepo;
    private MessageRepositoryInterface $messageRepo;

    public function __construct(
        ProjectRepositoryInterface $projectRepo,
        MessageRepositoryInterface $messageRepo
    ) {
        $this->projectRepo = $projectRepo;
        $this->messageRepo = $messageRepo;
    }

    public function requestDesignStart(int $projectId, int $clientId, string $drawingChanged, string $notes): void
    {
        // 1. メッセージ保存
        $changeMsg = "【図面変更の有無報告】\n";
        $changeMsg .= ($drawingChanged === 'yes') ? "見積時から変更あり\n詳細: " . $notes : "見積時から変更なし";
        
        $msg = new Message(null, $projectId, $clientId, 'client_admin', $changeMsg);
        $this->messageRepo->save($msg);

        // 2. ステータス更新
        $this->projectRepo->updateStatus($projectId, 'primary_prep');

        // 3. 通知メッセージ
        $notify = new Message(null, $projectId, $clientId, 'client_admin', "【通知】構造仕様の指定と必要図書の提出が完了し、設計開始が依頼されました。一次回答期日の設定をお願いします。");
        $this->messageRepo->save($notify);
    }

    public function setPrimaryDueDate(int $projectId, int $adminId, string $dueDate): void
    {
        $this->projectRepo->updatePrimaryDueDate($projectId, $dueDate);

        $msg = new Message(null, $projectId, $adminId, 'client_admin', "【通知】一次回答の基準日（期日）が {$dueDate} に設定され、スケジュールが確定しました。左パネルのスケジュール表をご確認ください。");
        $this->messageRepo->save($msg);
    }
}
