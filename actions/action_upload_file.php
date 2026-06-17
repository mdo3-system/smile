<?php
// actions/action_upload_file.php

require_once __DIR__ . '/../src/Services/UploadService.php';
use App\Services\UploadService;

$uploadService = new UploadService($pdo);

// 成果物アップロード処理 (管理者専用)
if ($action === 'upload_artifact' && $is_admin) {
    $file_category = trim($_POST['file_category'] ?? '');
    if (!empty($file_category) && isset($_FILES['artifact_file'])) {
        try {
            $uploadService->uploadArtifact($project_id, $file_category, $_FILES['artifact_file'], $_SESSION['user_id'], $_POST['tab'] ?? '');
        } catch (Exception $e) {
            die("アップロードに失敗しました: " . $e->getMessage());
        }
    }
    $tab = $_POST['tab'] ?? '';
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
}

if ($action === 'toggle_cad_publish' && $is_admin) {
    $file_id = intval($_POST['file_id'] ?? 0);
    if ($file_id > 0) {
        $stmt = $pdo->prepare("UPDATE project_files SET is_published_to_sub = NOT is_published_to_sub WHERE id = :id");
        $stmt->execute(['id' => $file_id]);
    }
    $tab = $_POST['tab'] ?? '';
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
}

// ファイルアップロード処理（管理者・依頼主）
$is_upload = isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK;
$is_included = isset($_POST['included_in_other']) && $_POST['included_in_other'] == '1';

if (($_POST['action_type'] ?? '') === 'single_upload' && ($is_upload || $is_included)) {
    $file_category = $_POST['file_category'] ?? '';
    if ($file_category !== '') {
        try {
            $uploadService->singleUpload(
                $project_id,
                $file_category,
                $is_upload ? $_FILES['upload_file'] : null,
                $is_included,
                $_POST['update_reason'] ?? null,
                $_SESSION['user_id'] ?? 1,
                $_SESSION['role'] ?? 'client',
                $_POST['tab'] ?? ''
            );
        } catch (Exception $e) {
            die("ファイルのアップロードまたはデータベース登録に失敗しました: " . $e->getMessage());
        }
        $tab = $_POST['tab'] ?? '';
        header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
    }
}

// ==============================
// 一括アップロード処理（依頼主向け）
// ==============================
if (($_POST['action_type'] ?? '') === 'bulk_upload' && !$is_admin) {
    $bulk_files = $_FILES['bulk_files'] ?? [];
    $bulk_included = $_POST['bulk_included_in_other'] ?? [];
    $bulk_reason = trim($_POST['bulk_update_reason'] ?? '');

    if (!empty($bulk_files['name'])) {
        try {
            $uploadService->bulkUpload(
                $project_id,
                $bulk_files,
                $bulk_included,
                $bulk_reason,
                $_SESSION['user_id'] ?? 1,
                $_SESSION['role'] ?? 'client',
                $_POST['tab'] ?? ''
            );
        } catch (Exception $e) {
            die("一括アップロードに失敗しました: " . $e->getMessage());
        }
    }
    $tab = $_POST['tab'] ?? '';
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
}

// ==============================
// カスタム図書スロット追加（依頼主向け）
// ==============================
if ($action === 'add_custom_slot' && !$is_admin) {
    $custom_label = trim($_POST['custom_slot_label'] ?? '');
    $tab = $_POST['tab'] ?? '';
    $section_type = $_POST['section_type'] ?? '';
    
    if ($custom_label !== '') {
        try {
            $uploadService->addCustomSlot(
                $project_id,
                $custom_label,
                $section_type,
                $tab,
                $_SESSION['user_id'] ?? 1
            );
        } catch (Exception $e) {
            die("カスタムスロットの追加に失敗しました: " . $e->getMessage());
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
}

// ==============================
// カスタム成果物スロット追加（管理者向け）
// ==============================
if ($action === 'add_custom_deliverable' && $is_admin) {
    $custom_label = trim($_POST['custom_label'] ?? '');
    $tab = $_POST['tab'] ?? '';
    
    if ($custom_label !== '') {
        try {
            $uploadService->addCustomDeliverable(
                $project_id,
                $custom_label,
                $tab,
                $_SESSION['user_id'] ?? 1
            );
        } catch (Exception $e) {
            die("カスタム成果物スロットの追加に失敗しました: " . $e->getMessage());
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
}

// ==============================
// カスタム成果物スロット名称変更（管理者向け）
// ==============================
if ($action === 'rename_custom_deliverable' && $is_admin) {
    $old_category = trim($_POST['old_category'] ?? '');
    $new_label = trim($_POST['new_label'] ?? '');
    $tab = $_POST['tab'] ?? '';
    
    if ($old_category !== '' && $new_label !== '') {
        try {
            $uploadService->renameCustomDeliverable(
                $project_id,
                $old_category,
                $new_label,
                $tab,
                $_SESSION['user_id'] ?? 1
            );
        } catch (Exception $e) {
            die("カスタム成果物スロットの名称変更に失敗しました: " . $e->getMessage());
        }
    }
    header("Location: project_detail.php?id=" . $project_id . "&tab=" . urlencode($tab) . "&t=" . time()); exit;
}
