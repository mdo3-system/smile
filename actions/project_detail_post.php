<?php
// ==========================================
// POST処理（発注依頼の登録など）ルーター
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $actionType = $_POST['action_type'] ?? '';

    // 1. 正式発注・仕様保存・クライアント情報更新（最優先）
    if (in_array($action, ['request_design_start', 'save_client_specs_draft', 'update_client_info', 'replace_documents', 'update_specs_detail'])) {
        require __DIR__ . '/action_save_specs.php';
    }

    // 2. チャット関連
    elseif ($action === 'send_message') {
        require __DIR__ . '/action_chat.php';
    }

    // 3. サブコントラクター関連
    elseif (in_array($action, ['order_subcontractor', 'approve_delivery', 'submit_checkback'])) {
        require __DIR__ . '/action_subcontractor.php';
    }

    // 4. スケジュール関連
    elseif (in_array($action, ['set_primary_due_date', 'update_schedule_actual', 'start_design', 'submit_primary_response', 'update_schedule_override', 'complete_review', 'pay_intermediate', 'revert_completed_status', 'update_schedule_wish'])) {
        require __DIR__ . '/action_schedule.php';
    }

    // 5. ファイルアップロード関連（明示的なアクションまたはaction_typeで判定し、勝手な横取りを防止）
    elseif (
        in_array($action, ['upload_artifact', 'toggle_cad_publish', 'add_custom_slot', 'add_custom_deliverable', 'rename_custom_deliverable', 'rename_custom_slot', 'delete_custom_slot', 'delete_custom_deliverable', 'submit_client_checkback'])
        || in_array($actionType, ['single_upload', 'bulk_upload'])
    ) {
        require __DIR__ . '/action_upload_file.php';
    }
}

