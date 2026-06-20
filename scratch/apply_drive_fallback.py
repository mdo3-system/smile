file_path = "google_drive_client.php"

with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

normalized_content = content.replace("\r\n", "\n")

old_func = """function upload_to_google_drive($local_file_path, $file_name, $mime_type, $project_id = null, $pdo = null) {
    if ($project_id && $pdo) {
        try {
            $folder_id = get_or_create_project_drive_folder($pdo, $project_id);
            return upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $folder_id);
        } catch (Exception $e) {
            error_log("Failed to upload to project drive folder for project ID {$project_id}: " . $e->getMessage());
            // Fallback to root folder
        }
    }
    $folder_id = getenv('GOOGLE_DRIVE_FOLDER_ID');
    return upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $folder_id);
}"""

new_func = """function upload_to_google_drive($local_file_path, $file_name, $mime_type, $project_id = null, $pdo = null) {
    try {
        if ($project_id && $pdo) {
            try {
                $folder_id = get_or_create_project_drive_folder($pdo, $project_id);
                return upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $folder_id);
            } catch (Exception $e) {
                error_log("Failed to upload to project drive folder for project ID {$project_id}: " . $e->getMessage());
                // Fallback to root folder
            }
        }
        $folder_id = getenv('GOOGLE_DRIVE_FOLDER_ID');
        return upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $folder_id);
    } catch (Exception $e) {
        error_log("Google Drive upload completely failed: " . $e->getMessage() . ". Falling back to local storage.");
        
        // ローカル保存へのフォールバック
        $upload_dir = __DIR__ . '/uploads';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // ファイル名の衝突を防止
        $unique_name = time() . '_' . uniqid() . '_' . $file_name;
        $dest_path = $upload_dir . '/' . $unique_name;
        
        // move_uploaded_file か copy かで保存
        if (is_uploaded_file($local_file_path)) {
            if (move_uploaded_file($local_file_path, $dest_path)) {
                return 'uploads/' . $unique_name;
            }
        } else {
            if (copy($local_file_path, $dest_path)) {
                return 'uploads/' . $unique_name;
            }
        }
        
        throw new Exception("Google Driveアップロードに失敗し、ローカルフォールバック保存にも失敗しました: " . $e->getMessage());
    }
}"""

normalized_old = old_func.replace("\r\n", "\n")
normalized_new = new_func.replace("\r\n", "\n")

if normalized_old in normalized_content:
    normalized_content = normalized_content.replace(normalized_old, normalized_new)
    print("Successfully added local fallback to upload_to_google_drive.")
else:
    print("Error: Target function upload_to_google_drive not found.")
    exit(1)

with open(file_path, "w", encoding="utf-8", newline="\r\n") as f:
    f.write(normalized_content)
