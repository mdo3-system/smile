<?php
// google_drive_client.php

require_once __DIR__ . '/vendor/autoload.php';

// 簡易.envロード関数
if (!function_exists('load_env')) {
    function load_env($file_path) {
        if (!file_exists($file_path)) {
            return;
        }
        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                // クォーテーションの除去
                $value = trim($value, '"\'');
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

// 環境変数のロード
load_env(__DIR__ . '/.env');

/**
 * Google Drive サービスインスタンスを取得する
 * @return Google\Service\Drive
 */
function get_google_drive_service() {
    static $service = null;
    if ($service !== null) {
        return $service;
    }

    $credentials_path = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: 'credentials.json';
    // 絶対パスに変換
    if (!file_exists($credentials_path) && file_exists(__DIR__ . '/' . $credentials_path)) {
        $credentials_path = __DIR__ . '/' . $credentials_path;
    }

    if (!file_exists($credentials_path)) {
        throw new Exception("GCP認証キーファイルが見つかりません: " . $credentials_path);
    }

    $client = new Google\Client();
    $client->setAuthConfig($credentials_path);
    $client->addScope(Google\Service\Drive::DRIVE);
    $client->setAccessType('offline');

    // トークン保存先
    $token_path = __DIR__ . '/token.json';
    if (file_exists($token_path)) {
        $accessToken = json_decode(file_get_contents($token_path), true);
        if ($accessToken) {
            $client->setAccessToken($accessToken);
        }
    }

    // トークンが期限切れの場合の自動リフレッシュ
    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if ($refreshToken) {
            try {
                $new_token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                if (!isset($new_token['refresh_token'])) {
                    $new_token['refresh_token'] = $refreshToken;
                }
                file_put_contents($token_path, json_encode($new_token));
                $client->setAccessToken($new_token);
            } catch (Exception $e) {
                throw new Exception("Google認証トークンの更新に失敗しました。再連携してください: " . $e->getMessage());
            }
        } else {
            throw new Exception("Googleドライブが連携されていません。管理者画面からログイン連携を行ってください。");
        }
    }
    
    $service = new Google\Service\Drive($client);
    return $service;
}

/**
 * ファイルをGoogle Driveにアップロードし、全員への閲覧権限を付与する
 * @param string $local_file_path ローカルファイルの絶対パス
 * @param string $file_name アップロード後のファイル名
 * @param string $mime_type MIMEタイプ
 * @return string Google DriveのファイルID
 */
/**
 * Google Drive上にフォルダを作成し、リンクを知っている全員に閲覧権限を付与する
 * @param string $folder_name フォルダ名
 * @param string|null $parent_folder_id 親フォルダのID
 * @return string 作成されたフォルダのID
 */
function create_google_drive_folder($folder_name, $parent_folder_id = null) {
    $service = get_google_drive_service();
    
    $file_metadata = new Google\Service\Drive\DriveFile();
    $file_metadata->setName($folder_name);
    $file_metadata->setMimeType('application/vnd.google-apps.folder');
    
    if ($parent_folder_id) {
        $file_metadata->setParents([$parent_folder_id]);
    }
    
    $folder = $service->files->create($file_metadata, [
        'fields' => 'id',
        'supportsAllDrives' => true
    ]);
    
    $folder_id = $folder->id;
    
    // フォルダの閲覧権限を設定 (anyone / reader)
    try {
        $permission = new Google\Service\Drive\Permission();
        $permission->setRole('reader');
        $permission->setType('anyone');
        $service->permissions->create($folder_id, $permission, [
            'supportsAllDrives' => true
        ]);
    } catch (Exception $e) {
        error_log("Google DriveフォルダID: {$folder_id} への権限付与に失敗しました: " . $e->getMessage());
    }
    
    return $folder_id;
}

/**
 * 指定した親フォルダ配下に同名のフォルダが存在するか検索する
 * @param string $folder_name 検索するフォルダ名
 * @param string|null $parent_folder_id 親フォルダのID
 * @return string|null フォルダID（見つからない場合はnull）
 */
function find_google_drive_folder($folder_name, $parent_folder_id = null) {
    $service = get_google_drive_service();
    
    // クエリ作成（シングルクォートのエスケープ）
    $escaped_name = str_replace("'", "\\'", $folder_name);
    $query = "mimeType = 'application/vnd.google-apps.folder' and name = '{$escaped_name}' and trashed = false";
    if ($parent_folder_id) {
        $query .= " and '{$parent_folder_id}' in parents";
    }
    
    $response = $service->files->listFiles([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id, name)',
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true
    ]);
    
    if (count($response->files) > 0) {
        return $response->files[0]->id;
    }
    
    return null;
}

/**
 * 案件に関連する Google Drive フォルダIDを取得、無ければ自動作成してDBにキャッシュ保存する
 * @param PDO $pdo データベース接続インスタンス
 * @param int $project_id 案件ID
 * @return string 案件フォルダのGoogle Drive ID
 */
function get_or_create_project_drive_folder($pdo, $project_id) {
    // 1. 案件情報と依頼主情報をDBから取得
    $stmt = $pdo->prepare("
        SELECT p.project_name, p.drive_folder_id as project_folder_id, 
               u.id as client_id, u.company_name, u.contact_name, u.drive_folder_id as client_folder_id
        FROM projects p
        JOIN users u ON p.client_id = u.id
        WHERE p.id = :pid
    ");
    $stmt->execute(['pid' => $project_id]);
    $data = $stmt->fetch();
    
    if (!$data) {
        throw new Exception("案件情報（ID: {$project_id}）が見つかりません。");
    }
    
    // すでに案件フォルダIDがDBに登録されていればそれを返す
    if (!empty($data['project_folder_id'])) {
        return $data['project_folder_id'];
    }
    
    $root_folder_id = getenv('GOOGLE_DRIVE_FOLDER_ID');
    if (empty($root_folder_id)) {
        throw new Exception("環境変数 GOOGLE_DRIVE_FOLDER_ID が設定されていません。");
    }
    
    // 2. 依頼主フォルダの取得・作成
    $client_folder_id = $data['client_folder_id'];
    $client_folder_name = !empty($data['company_name']) ? trim($data['company_name']) : trim($data['contact_name']);
    if (empty($client_folder_name)) {
        $client_folder_name = "依頼主_ID_" . $data['client_id'];
    }
    
    if (empty($client_folder_id)) {
        // Google Drive上で同名フォルダを検索
        $client_folder_id = find_google_drive_folder($client_folder_name, $root_folder_id);
        if (!$client_folder_id) {
            // 無ければ新規作成
            $client_folder_id = create_google_drive_folder($client_folder_name, $root_folder_id);
        }
        // DBにキャッシュ保存
        $stmtUpdateClient = $pdo->prepare("UPDATE users SET drive_folder_id = :fid WHERE id = :uid");
        $stmtUpdateClient->execute(['fid' => $client_folder_id, 'uid' => $data['client_id']]);
    }
    
    // 3. 案件フォルダの取得・作成
    $project_folder_name = trim($data['project_name']);
    if (empty($project_folder_name)) {
        $project_folder_name = "案件_ID_" . $project_id;
    }
    
    $project_folder_id = find_google_drive_folder($project_folder_name, $client_folder_id);
    if (!$project_folder_id) {
        $project_folder_id = create_google_drive_folder($project_folder_name, $client_folder_id);
    }
    
    // 案件のDBにフォルダIDを保存
    $stmtUpdateProject = $pdo->prepare("UPDATE projects SET drive_folder_id = :fid, updated_at = NOW() WHERE id = :pid");
    $stmtUpdateProject->execute(['fid' => $project_folder_id, 'pid' => $project_id]);
    
    return $project_folder_id;
}

/**
 * 指定したGoogle Driveフォルダ配下にファイルをアップロードする
 * @param string $local_file_path ローカルファイルの絶対パス
 * @param string $file_name アップロード後のファイル名
 * @param string $mime_type MIMEタイプ
 * @param string $parent_folder_id アップロード先のフォルダID
 * @return string Google DriveのファイルID
 */
function upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $parent_folder_id) {
    if (!file_exists($local_file_path)) {
        throw new Exception("アップロード対象のローカルファイルが存在しません: " . $local_file_path);
    }

    $service = get_google_drive_service();

    $file_metadata = new Google\Service\Drive\DriveFile();
    $file_metadata->setName($file_name);

    if (!empty($parent_folder_id)) {
        $file_metadata->setParents([$parent_folder_id]);
    }

    $content = file_get_contents($local_file_path);

    $file = $service->files->create($file_metadata, [
        'data' => $content,
        'mimeType' => $mime_type,
        'uploadType' => 'multipart',
        'fields' => 'id',
        'supportsAllDrives' => true
    ]);

    $file_id = $file->id;

    // リンクを知っている全員に閲覧権限 (anyone / reader) を付与
    try {
        $permission = new Google\Service\Drive\Permission();
        $permission->setRole('reader');
        $permission->setType('anyone');
        $service->permissions->create($file_id, $permission, [
            'supportsAllDrives' => true
        ]);
    } catch (Exception $e) {
        error_log("Google DriveファイルID: {$file_id} への権限付与に失敗しました: " . $e->getMessage());
    }

    return $file_id;
}

/**
 * ファイルをGoogle Driveにアップロードし、全員への閲覧権限を付与する（旧互換用）
 * @param string $local_file_path ローカルファイルの絶対パス
 * @param string $file_name アップロード後のファイル名
 * @param string $mime_type MIMEタイプ
 * @return string Google DriveのファイルID
 */
function upload_to_google_drive($local_file_path, $file_name, $mime_type) {
    $folder_id = getenv('GOOGLE_DRIVE_FOLDER_ID');
    return upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $folder_id);
}

