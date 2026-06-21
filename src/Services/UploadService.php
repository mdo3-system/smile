<?php
namespace App\Services;

use PDO;
use Exception;

class UploadService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * カテゴリーキーから日本語ラベルを取得する
     *
     * @param string $category
     * @return string
     */
    public function getFileCategoryLabel(string $category): string
    {
        global $file_categories_left_pdf, $file_categories_left_cad, $file_categories_center, $money_categories;
        $map = array_merge(
            $file_categories_left_pdf ?? [],
            $file_categories_left_cad ?? [],
            $file_categories_center ?? [],
            $money_categories ?? [],
            [
                'pdf_area_calc' => '求積図',
                'all_in_one_zip' => '一括図書圧縮ファイル(ZIP)',
                'calc_doc' => '構造計算書',
                'qa_doc' => '疑義照会・回答書',
                'correction_doc' => '補正・指示図書',
                'other' => 'その他参考資料',
                'other_file' => 'その他図書',
            ]
        );
        if (isset($map[$category])) {
            return $map[$category];
        }
        if (strpos($category, 'custom_') === 0) {
            $parts = explode('_', $category);
            return end($parts);
        }
        return $category;
    }

    /**
     * 成果物アップロード処理 (管理者専用)
     *
     * @param int $projectId
     * @param string $fileCategory
     * @param array $fileInfo $_FILES['artifact_file'] 相当の配列
     * @param int $userId
     * @param string $tab
     * @return bool
     * @throws Exception
     */
    public function uploadArtifact(int $projectId, string $fileCategory, array $fileInfo, int $userId, string $tab = ''): bool
    {
        if (empty($fileCategory) || !isset($fileInfo['error']) || $fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("無効なファイルまたはアップロードエラーです。");
        }

        require_once __DIR__ . '/../../google_drive_client.php';
        
        // プロジェクト情報の取得
        $stmtProj = $this->pdo->prepare("SELECT * FROM projects WHERE id = :pid");
        $stmtProj->execute(['pid' => $projectId]);
        $project = $stmtProj->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            throw new Exception("プロジェクトが見つかりません。");
        }

        $fileName = $fileInfo['name'];
        $tmpName  = $fileInfo['tmp_name'];
        $mimeType = $fileInfo['type'];

        $driveFileId = upload_to_google_drive($tmpName, $fileName, $mimeType, $projectId, $this->pdo);

        $this->pdo->beginTransaction();
        try {
            // 既存の同カテゴリファイルを履歴に落とす
            $stmtOld = $this->pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat");
            $stmtOld->execute(['pid' => $projectId, 'cat' => $fileCategory]);

            // バージョン番号の決定
            $stmtVer = $this->pdo->prepare("SELECT MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
            $stmtVer->execute(['pid' => $projectId, 'cat' => $fileCategory]);
            $nextVer = intval($stmtVer->fetchColumn()) + 1;

            $stmtNew = $this->pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) VALUES (:pid, :cat, :fname, :fid, :ver, 1)");
            $stmtNew->execute([
                'pid' => $projectId,
                'cat' => $fileCategory,
                'fname' => $fileName,
                'fid' => $driveFileId,
                'ver' => $nextVer
            ]);

            // === スケジュール自動入力処理 ===
            $today = date('Y-m-d');
            $colsToUpdate = [];
            $targetIndex = null;
            $msgTitle = "";

            // 1. 初回提示（一次回答）の自動入力 (Index 2)
            if ($fileCategory === 'inv_primary') {
                $colsToUpdate = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                $targetIndex = 2;
                $msgTitle = "初回提示（一次回答）";

                // ステータスを自動更新 (受注済へ)
                $stmtStatus = $this->pdo->prepare("UPDATE projects SET status = 'contracted' WHERE id = :pid");
                $stmtStatus->execute(['pid' => $projectId]);
            }

            // 2. 申請図書一式UPの自動入力
            // 許容応力度 (Index 4)
            if ($fileCategory === 'structural_dwg' && ($project['req_permit'] == 1 || $project['req_opt_kisohari'] == 1)) {
                $colsToUpdate[] = 'schedule_actuals';
                $targetIndex = 4;
                $msgTitle = "構造図UP";
            }
            // 壁量計算 (Index 4)
            if (($fileCategory === 'wall_calc_doc' || $fileCategory === 'wall_spreadsheet') && $project['req_wall'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_wall';
                $targetIndex = 4;
                $msgTitle = "壁量計算書";
            }
            // 外皮計算 (Index 4) - 外皮計算書、WEBプログラム、外皮計算資料が全て揃っている場合のみ
            if (($fileCategory === 'skin_calc_doc' || $fileCategory === 'skin_doc' || $fileCategory === 'skin_web_prog') && $project['req_skin'] == 1) {
                $stmtCheckSkinFiles = $this->pdo->prepare("
                    SELECT file_category 
                    FROM project_files 
                    WHERE project_id = :pid 
                      AND is_latest = 1 
                      AND file_category IN ('skin_calc_doc', 'skin_web_prog', 'skin_doc')
                ");
                $stmtCheckSkinFiles->execute(['pid' => $projectId]);
                $existingCats = $stmtCheckSkinFiles->fetchAll(PDO::FETCH_COLUMN);

                $requiredCats = ['skin_calc_doc', 'skin_web_prog', 'skin_doc'];
                $hasAll = true;
                foreach ($requiredCats as $reqCat) {
                    if (!in_array($reqCat, $existingCats)) {
                        $hasAll = false;
                    }
                }

                if ($hasAll) {
                    $colsToUpdate[] = 'schedule_actuals_skin';
                    $targetIndex = 4;
                    $msgTitle = "外皮計算書";
                }
            }
            // 外皮計算・初回提示 (Index 2) は WEBプログラム計算書をUPした時
            if ($fileCategory === 'skin_web_prog' && $project['req_skin'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_skin';
                $targetIndex = 2;
                $msgTitle = "外皮計算・初回提示（WEBプログラム計算書）";
            }
            // 天空率 (Index 3)
            if ($fileCategory === 'sky_dwg' && $project['req_sky'] == 1) {
                $colsToUpdate[] = 'schedule_actuals_sky';
                $targetIndex = 3;
                $msgTitle = "天空率図面";
            }

            if (!empty($colsToUpdate) && $targetIndex !== null) {
                $stmtAct = $this->pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
                $stmtAct->execute(['id' => $projectId]);
                $currentActualsRow = $stmtAct->fetch(PDO::FETCH_ASSOC);

                $updatedAny = false;
                foreach (array_unique($colsToUpdate) as $col) {
                    $actuals = json_decode($currentActualsRow[$col] ?? '{}', true) ?: [];
                    if (empty($actuals[$targetIndex])) {
                        $actuals[$targetIndex] = $today;
                        $stmtUpdateSchedule = $this->pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                        $stmtUpdateSchedule->execute(['act' => json_encode($actuals), 'pid' => $projectId]);
                        $updatedAny = true;
                    }
                }

                if ($updatedAny) {
                    // チャット通知
                    $threadType = ($tab === 'permit' || $tab === '') ? 'client_admin_permit' : 'client_admin_' . $tab;
                    $stmtNotify = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                    $stmtNotify->execute([
                        'pid' => $projectId,
                        'sid' => $userId,
                        'thread' => $threadType,
                        'msg' => "【自動通知】{$msgTitle}が提出されました。該当スケジュールの実施日が自動設定されました。"
                    ]);
                }
            }

            $this->pdo->commit();
            
            // 依頼主へのメール通知
            try {
                $stmtClientEmail = $this->pdo->prepare("
                    SELECT u.email FROM projects p JOIN users u ON p.client_id = u.id WHERE p.id = :pid
                ");
                $stmtClientEmail->execute(['pid' => $projectId]);
                $clientEmail = $stmtClientEmail->fetchColumn();
                if ($clientEmail && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                    $pname = $project['project_name'] ?? 'your project';
                    $subj = "【設計サポート】案件「{$pname}」に新しい成果物が登録されました";
                    $body  = "案件「{$pname}」に完成成果物・図書が登録されました。\n\n";
                    $body .= "以下のURLよりダッシュボードにログインしてご確認ください。\n";
                    $body .= "https://system.thanks.work/project_detail.php?id={$projectId}\n\n";
                    $body .= "※このメールに返信いただいてもお返事できません。ご不明な点は担当まで直接お問い合わせください。";
                    sendSystemEmail($clientEmail, $subj, $body);
                }
            } catch (Exception $e) { /* メール送信エラーは無視 */ }

            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * ファイルアップロード処理（管理者・依頼主）
     *
     * @param int $projectId
     * @param string $fileCategory
     * @param array|null $fileInfo $_FILES['upload_file'] 相当の配列、またはnull
     * @param bool $isIncluded 「他ファイルに記載」かどうか
     * @param string|null $updateReason 差し替え理由
     * @param int $userId
     * @param string $role
     * @param string $tab
     * @return bool
     * @throws Exception
     */
    public function singleUpload(int $projectId, string $fileCategory, ?array $fileInfo, bool $isIncluded, ?string $updateReason, int $userId, string $role, string $tab = ''): bool
    {
        if (empty($fileCategory)) {
            throw new Exception("カテゴリーが空です。");
        }

        $isUpload = isset($fileInfo['error']) && $fileInfo['error'] === UPLOAD_ERR_OK;
        if (!$isUpload && !$isIncluded) {
            throw new Exception("ファイルが選択されていないか、アップロードエラーです。");
        }

        try {
            $fileName = '';
            $driveFileId = '';

            if ($isIncluded) {
                $fileName = '【他ファイルに記載】';
            } else {
                $fileName = $fileInfo['name'];
                $tmpName = $fileInfo['tmp_name'];
                $mimeType = $fileInfo['type'];
                require_once __DIR__ . '/../../google_drive_client.php';
                $driveFileId = upload_to_google_drive($tmpName, $fileName, $mimeType, $projectId, $this->pdo);
            }

            $this->pdo->beginTransaction();

            // 1. 既存の同カテゴリのファイルを最新フラグから外す
            $stmtDisable = $this->pdo->prepare("
                UPDATE project_files 
                SET is_latest = 0 
                WHERE project_id = :pid AND file_category = :cat
            ");
            $stmtDisable->execute([
                'pid' => $projectId,
                'cat' => $fileCategory
            ]);

            // 2. 現在の最大バージョンを取得
            $stmtVersion = $this->pdo->prepare("
                SELECT MAX(version) 
                FROM project_files 
                WHERE project_id = :pid AND file_category = :cat
            ");
            $stmtVersion->execute([
                'pid' => $projectId,
                'cat' => $fileCategory
            ]);
            $maxVersion = (int)$stmtVersion->fetchColumn();
            $newVersion = $maxVersion + 1;

            // 3. 新しいレコードを挿入
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) 
                VALUES (:pid, :cat, :name, :drive_id, :ver, 1, :reason)
            ");
            $stmtInsert->execute([
                'pid' => $projectId,
                'cat' => $fileCategory,
                'name' => $fileName,
                'drive_id' => $driveFileId,
                'ver' => $newVersion,
                'reason' => $updateReason
            ]);

            // 誰がアップロードしたかのロール名
            $uploaderRoleName = '担当者';
            if (in_array($role, ['admin', 'accountant'])) {
                $uploaderRoleName = '設計担当（または管理者）';
            } elseif ($role === 'client') {
                $uploaderRoleName = '依頼主';
            } elseif ($role === 'subcontractor') {
                $uploaderRoleName = '協力業者';
            }

            $catLabel = $this->getFileCategoryLabel($fileCategory);
            $threadType = ($tab === 'permit' || $tab === '') ? 'client_admin_permit' : 'client_admin_' . $tab;

            if ($newVersion > 1) {
                if ($isIncluded) {
                    $msg = "【図書差し替え設定】\n{$uploaderRoleName}が「{$catLabel}」を「他ファイルに記載」に設定変更しました。";
                } else {
                    $msg = "【図書差し替え】\n{$uploaderRoleName}が「{$catLabel}」に「{$fileName}」をアップロード（差し替え）しました。";
                }
                if (!empty($updateReason)) {
                    $msg .= "\n差し替え理由: {$updateReason}";
                }
            } else {
                if ($isIncluded) {
                    $msg = "【図書提出設定】\n{$uploaderRoleName}が「{$catLabel}」を「他ファイルに記載」に設定しました。";
                } else {
                    $msg = "【図書提出】\n{$uploaderRoleName}が「{$catLabel}」に「{$fileName}」をアップロードしました。";
                }
            }

            $stmtMsg = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
            $stmtMsg->execute([
                'pid' => $projectId,
                'sid' => $userId,
                'thread' => $threadType,
                'msg' => $msg
            ]);

            // ============================
            // 後出し図書充足トリガー：一次回答日の自動起算
            // ============================
            if (in_array($fileCategory, ['app_doc', 'soil_report', 'cad_design_all', 'cad_layout', 'cad_plan_1f', 'cad_plan_2f', 'cad_elevation', 'cad_section', 'all_in_one_zip'])) {
                $stmtStatus = $this->pdo->prepare("SELECT status, req_permit, req_opt_kisohari FROM projects WHERE id = :id");
                $stmtStatus->execute(['id' => $projectId]);
                $pj = $stmtStatus->fetch(PDO::FETCH_ASSOC);

                if ($pj && $pj['status'] === 'primary_prep') {
                    $stmtAct = $this->pdo->prepare("SELECT schedule_actuals FROM projects WHERE id = :id");
                    $stmtAct->execute(['id' => $projectId]);
                    $actRow = $stmtAct->fetch(PDO::FETCH_ASSOC);
                    $alreadyStarted = false;
                    if ($actRow) {
                        $actualsCheck = json_decode($actRow['schedule_actuals'] ?? '{}', true) ?: [];
                        $alreadyStarted = !empty($actualsCheck[0]);
                    }

                    if (!$alreadyStarted) {
                        $hasFile = function($c) use ($projectId) {
                            $s = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat AND is_latest = 1");
                            $s->execute(['pid' => $projectId, 'cat' => $c]);
                            return (int)$s->fetchColumn() > 0;
                        };
                        $hasAppDoc = $hasFile('app_doc');
                        $needsSoil = ($pj['req_permit'] == 1 || $pj['req_opt_kisohari'] == 1);
                        $hasSoil = $needsSoil ? $hasFile('soil_report') : true;

                        if ($hasAppDoc && $hasSoil) {
                            $today2 = date('Y-m-d');
                            $allCols = ['schedule_actuals', 'schedule_actuals_wall', 'schedule_actuals_skin', 'schedule_actuals_sky'];
                            $stmtAllAct = $this->pdo->prepare("SELECT schedule_actuals, schedule_actuals_wall, schedule_actuals_skin, schedule_actuals_sky FROM projects WHERE id = :id");
                            $stmtAllAct->execute(['id' => $projectId]);
                            $allActRow = $stmtAllAct->fetch(PDO::FETCH_ASSOC);
                            if ($allActRow) {
                                foreach ($allCols as $col) {
                                    $act = json_decode($allActRow[$col] ?? '{}', true) ?: [];
                                    if (empty($act[0])) {
                                        $act[0] = $today2;
                                        $stmtUpd = $this->pdo->prepare("UPDATE projects SET {$col} = :act WHERE id = :pid");
                                        $stmtUpd->execute(['act' => json_encode($act), 'pid' => $projectId]);
                                    }
                                }
                            }

                            $stmtN2 = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                            $stmtN2->execute([
                                'pid' => $projectId,
                                'sid' => $userId,
                                'thread' => $threadType,
                                'msg' => "【自動通知】必要図書がすべて揃いました。本日（{$today2}）が一次回答の起算日となりました。図書の内容を確認の上、一次回答期日の設定をお願いします。"
                            ]);
                        }
                    }
                }
            }

            // 補正通知 (correction_notice) ファイルがアップロードされた場合で、ステータスが「申請中」であれば「補正対応中」に更新
            if ($fileCategory === 'correction_notice') {
                $stmtCheckStatus = $this->pdo->prepare("SELECT status FROM projects WHERE id = :id");
                $stmtCheckStatus->execute(['id' => $projectId]);
                $currentStatus = $stmtCheckStatus->fetchColumn();
                if ($currentStatus === 'submitting') {
                    $stmtUpdateStatus = $this->pdo->prepare("UPDATE projects SET status = 'correction' WHERE id = :id");
                    $stmtUpdateStatus->execute(['id' => $projectId]);

                    // チャット通知 (自動)
                    $msgSubmittingCorrection = "【自動通知】補正通知書がアップロードされました。案件ステータスを「申請中」から「補正対応中」に変更しました。";
                    $stmtMsgCorrection = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                    $stmtMsgCorrection->execute([
                        'pid' => $projectId,
                        'sid' => $userId,
                        'thread' => $threadType,
                        'msg' => $msgSubmittingCorrection
                    ]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 一括アップロード処理（依頼主向け）
     *
     * @param int $projectId
     * @param array $bulkFiles $_FILES['bulk_files'] 相当の多次元配列
     * @param array $bulkIncluded $_POST['bulk_included_in_other'] 相当の配列
     * @param string $bulkReason 差し替え理由
     * @param int $userId
     * @param string $role
     * @param string $tab
     * @return bool
     * @throws Exception
     */
    public function bulkUpload(int $projectId, array $bulkFiles, array $bulkIncluded, string $bulkReason, int $userId, string $role, string $tab = ''): bool
    {
        if (empty($bulkFiles['name'])) {
            return false;
        }

        require_once __DIR__ . '/../../google_drive_client.php';
        $uploadedCats = [];
        $hasReplace = false;

        try {
            $uploadData = [];
            foreach ($bulkFiles['name'] as $cat => $fname) {
                $error  = $bulkFiles['error'][$cat] ?? UPLOAD_ERR_NO_FILE;
                $isInc = isset($bulkIncluded[$cat]) && $bulkIncluded[$cat] == '1';

                if ($error !== UPLOAD_ERR_OK && !$isInc) continue;

                // 既存ファイルの有無確認
                $stmtChk = $this->pdo->prepare("SELECT COUNT(*), MAX(version) FROM project_files WHERE project_id = :pid AND file_category = :cat");
                $stmtChk->execute(['pid' => $projectId, 'cat' => $cat]);
                [$existingCount, $maxVer] = $stmtChk->fetch(PDO::FETCH_NUM);
                $isReplace = ($existingCount > 0);
                if ($isReplace) $hasReplace = true;

                $fileName = '';
                $driveId  = '';

                if ($isInc) {
                    $fileName = '【他ファイルに記載】';
                } else {
                    $fileName = $bulkFiles['name'][$cat];
                    $tmpName  = $bulkFiles['tmp_name'][$cat];
                    $mimeType = $bulkFiles['type'][$cat];
                    $driveId  = upload_to_google_drive($tmpName, $fileName, $mimeType, $projectId, $this->pdo);
                }

                $uploadData[$cat] = [
                    'fileName' => $fileName,
                    'driveId' => $driveId,
                    'isReplace' => $isReplace,
                    'maxVer' => $maxVer
                ];
            }

            $this->pdo->beginTransaction();

            foreach ($uploadData as $cat => $ud) {
                $fileName = $ud['fileName'];
                $driveId = $ud['driveId'];
                $isReplace = $ud['isReplace'];
                $maxVer = $ud['maxVer'];

                // 既存を履歴へ
                $this->pdo->prepare("UPDATE project_files SET is_latest = 0 WHERE project_id = :pid AND file_category = :cat")
                    ->execute(['pid' => $projectId, 'cat' => $cat]);

                $newVer = intval($maxVer) + 1;
                $this->pdo->prepare("INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest, update_reason) VALUES (:pid, :cat, :name, :drive_id, :ver, 1, :reason)")
                    ->execute([
                        'pid' => $projectId, 'cat' => $cat, 'name' => $fileName,
                        'drive_id' => $driveId, 'ver' => $newVer,
                        'reason' => $isReplace ? $bulkReason : null
                    ]);

                $uploadedCats[] = $cat;
            }

            // チャットへ1回投稿
            $threadType = ($tab === 'permit' || $tab === '') ? 'client_admin_permit' : 'client_admin_' . $tab;
            if (!empty($uploadedCats)) {
                $uploaderRoleName = '担当者';
                if ($role === 'client') {
                    $uploaderRoleName = '依頼主';
                } elseif (in_array($role, ['admin', 'accountant'])) {
                    $uploaderRoleName = '設計担当（または管理者）';
                }

                $details = [];
                foreach ($uploadedCats as $cat) {
                    $catLabel = $this->getFileCategoryLabel($cat);
                    $isInc = isset($bulkIncluded[$cat]) && $bulkIncluded[$cat] == '1';
                    if ($isInc) {
                        $details[] = "・{$catLabel}: 他ファイルに記載として設定";
                    } else {
                        $fname = $bulkFiles['name'][$cat] ?? '';
                        $details[] = "・{$catLabel}: {$fname}";
                    }
                }

                if ($hasReplace) {
                    $chatMsg = "【一括図書差し替え通知】\n{$uploaderRoleName}が一括で図書を差し替えました。\n" . implode("\n", $details);
                    if (!empty($bulkReason)) {
                        $chatMsg .= "\n理由: {$bulkReason}";
                    }
                } else {
                    $chatMsg = "【一括図書提出通知】\n{$uploaderRoleName}が一括で図書を提出しました。\n" . implode("\n", $details);
                }

                $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)")
                    ->execute(['pid' => $projectId, 'sid' => $userId, 'thread' => $threadType, 'msg' => $chatMsg]);
            }

            // 補正通知 (correction_notice) ファイルがアップロードされた場合で、ステータスが「申請中」であれば「補正対応中」に更新
            if (in_array('correction_notice', $uploadedCats)) {
                $stmtCheckStatus = $this->pdo->prepare("SELECT status FROM projects WHERE id = :id");
                $stmtCheckStatus->execute(['id' => $projectId]);
                $currentStatus = $stmtCheckStatus->fetchColumn();
                if ($currentStatus === 'submitting') {
                    $stmtUpdateStatus = $this->pdo->prepare("UPDATE projects SET status = 'correction' WHERE id = :id");
                    $stmtUpdateStatus->execute(['id' => $projectId]);

                    // チャット通知 (自動)
                    $msgSubmittingCorrection = "【自動通知】補正通知書がアップロードされました。案件ステータスを「申請中」から「補正対応中」に変更しました。";
                    $stmtMsgCorrection = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                    $stmtMsgCorrection->execute([
                        'pid' => $projectId,
                        'sid' => $userId,
                        'thread' => $threadType,
                        'msg' => $msgSubmittingCorrection
                    ]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * カスタム図書スロット追加
     *
     * @param int $projectId
     * @param string $customLabel
     * @param string $sectionType
     * @param string $tab
     * @param int $userId
     * @return bool
     * @throws Exception
     */
    public function addCustomSlot(int $projectId, string $customLabel, string $sectionType, string $tab, int $userId): bool
    {
        if (empty($customLabel)) {
            return false;
        }

        $prefix = 'custom_';
        if ($sectionType === '専門図書' && in_array($tab, ['permit', 'wall', 'skin', 'sky'])) {
            $prefix = 'custom_' . $tab . '_';
        }
        $fileCategory = $prefix . $customLabel;

        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtCheck->execute(['pid' => $projectId, 'cat' => $fileCategory]);
        if ($stmtCheck->fetchColumn() == 0) {
            $this->pdo->beginTransaction();
            try {
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :cat, '', NULL, 1, 1)
                ");
                $stmtInsert->execute([
                    'pid' => $projectId,
                    'cat' => $fileCategory
                ]);

                // チャットへ通知
                $uploaderRoleName = '依頼主';
                $chatMsg = "【カスタムスロット追加】\n{$uploaderRoleName}が新しいカスタムスロット「{$customLabel}」を追加しました。";
                $threadType = ($tab === 'permit' || $tab === '') ? 'client_admin_permit' : 'client_admin_' . $tab;

                $stmtMsg = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                $stmtMsg->execute([
                    'pid' => $projectId,
                    'sid' => $userId,
                    'thread' => $threadType,
                    'msg' => $chatMsg
                ]);

                $this->pdo->commit();
                return true;
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return false;
    }

    /**
     * カスタム成果物スロット追加 (管理者用)
     *
     * @param int $projectId
     * @param string $customLabel
     * @param string $tab
     * @param int $userId
     * @return bool
     * @throws Exception
     */
    public function addCustomDeliverable(int $projectId, string $customLabel, string $tab, int $userId): bool
    {
        if (empty($customLabel)) {
            return false;
        }

        $fileCategory = 'custom_deliverable_' . $customLabel;

        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtCheck->execute(['pid' => $projectId, 'cat' => $fileCategory]);
        if ($stmtCheck->fetchColumn() == 0) {
            $this->pdo->beginTransaction();
            try {
                $stmtInsert = $this->pdo->prepare("
                    INSERT INTO project_files (project_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :cat, '', NULL, 1, 1)
                ");
                $stmtInsert->execute([
                    'pid' => $projectId,
                    'cat' => $fileCategory
                ]);

                // チャットへ通知
                $uploaderRoleName = '設計担当';
                $chatMsg = "【成果物スロット追加】\n{$uploaderRoleName}が新しい成果物スロット「{$customLabel}」を追加しました。";
                $threadType = ($tab === 'permit' || $tab === '') ? 'client_admin_permit' : 'client_admin_' . $tab;

                $stmtMsg = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                $stmtMsg->execute([
                    'pid' => $projectId,
                    'sid' => $userId,
                    'thread' => $threadType,
                    'msg' => $chatMsg
                ]);

                $this->pdo->commit();
                return true;
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return false;
    }

    /**
     * カスタム成果物スロット名称変更 (管理者用)
     *
     * @param int $projectId
     * @param string $oldCategory
     * @param string $newLabel
     * @param string $tab
     * @param int $userId
     * @return bool
     * @throws Exception
     */
    public function renameCustomDeliverable(int $projectId, string $oldCategory, string $newLabel, string $tab, int $userId): bool
    {
        if (empty($newLabel) || strpos($oldCategory, 'custom_deliverable_') !== 0) {
            return false;
        }

        $newCategory = 'custom_deliverable_' . $newLabel;

        // すでに存在するカテゴリ名かどうかチェック
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM project_files WHERE project_id = :pid AND file_category = :cat");
        $stmtCheck->execute(['pid' => $projectId, 'cat' => $newCategory]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception("既に同じ名称の成果物スロットが存在します。");
        }

        $this->pdo->beginTransaction();
        try {
            $stmtUpdate = $this->pdo->prepare("
                UPDATE project_files 
                SET file_category = :new_cat 
                WHERE project_id = :pid AND file_category = :old_cat
            ");
            $stmtUpdate->execute([
                'pid' => $projectId,
                'old_cat' => $oldCategory,
                'new_cat' => $newCategory
            ]);

            // チャットへ通知
            $oldLabel = substr($oldCategory, strlen('custom_deliverable_'));
            $uploaderRoleName = '設計担当';
            $chatMsg = "【成果物スロット名称変更】\n{$uploaderRoleName}が成果物スロット「{$oldLabel}」の名称を「{$newLabel}」に変更しました。";
            $threadType = ($tab === 'permit' || $tab === '') ? 'client_admin_permit' : 'client_admin_' . $tab;

            $stmtMsg = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
            $stmtMsg->execute([
                'pid' => $projectId,
                'sid' => $userId,
                'thread' => $threadType,
                'msg' => $chatMsg
            ]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
