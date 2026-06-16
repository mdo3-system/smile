<?php
// ==========================================
// POST処理（発注依頼の登録など）ルーター
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // サブコントラクター関連
    if (in_array($action, ['order_subcontractor', 'approve_delivery'])) {
        require __DIR__ . '/action_subcontractor.php';
    }
    
    // ファイルアップロード関連（成果物・通常アップロード・他ファイル記載・CAD公開トグル・一括UP・カスタムスロット追加）
    elseif (in_array($action, ['upload_artifact', 'toggle_cad_publish', 'add_custom_slot']) || in_array(($_POST['action_type'] ?? ''), ['single_upload', 'bulk_upload']) || (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK)) {
        require __DIR__ . '/action_upload_file.php';
    }

    // チャット関連
    elseif ($action === 'send_message') {
        require __DIR__ . '/action_chat.php';
    }

    // クライアント仕様・情報関連
    elseif (in_array($action, ['update_client_info', 'save_client_specs_draft', 'request_design_start', 'replace_documents', 'update_specs_detail'])) {
        require __DIR__ . '/action_save_specs.php';
    }

    // スケジュール関連
    elseif (in_array($action, ['set_primary_due_date', 'update_schedule_actual', 'start_design', 'submit_primary_response'])) {
        require __DIR__ . '/action_schedule.php';
    }
}
