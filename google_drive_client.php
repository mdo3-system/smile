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
function upload_to_google_drive($local_file_path, $file_name, $mime_type) {
    if (!file_exists($local_file_path)) {
        throw new Exception("アップロード対象のローカルファイルが存在しません: " . $local_file_path);
    }

    $service = get_google_drive_service();
    $folder_id = getenv('GOOGLE_DRIVE_FOLDER_ID');

    $file_metadata = new Google\Service\Drive\DriveFile();
    $file_metadata->setName($file_name);

    if (!empty($folder_id) && $folder_id !== '1_vWqM1F5jC1ZdG6R8O0D_L9S_K_example') {
        $file_metadata->setParents([$folder_id]);
    }

    $content = file_get_contents($local_file_path);

    $file = $service->files->create($file_metadata, [
        'data' => $content,
        'mimeType' => $mime_type,
        'uploadType' => 'multipart',
        'fields' => 'id'
    ]);

    $file_id = $file->id;

    // リンクを知っている全員に閲覧権限 (anyone / reader) を付与
    try {
        $permission = new Google\Service\Drive\Permission();
        $permission->setRole('reader');
        $permission->setType('anyone');
        $service->permissions->create($file_id, $permission);
    } catch (Exception $e) {
        // 権限設定エラー時はログに残し、アップロード自体は成功とするか例外にするか
        // 運用の利便性を考慮し、エラーをログに残しつつ処理を継続
        error_log("Google DriveファイルID: {$file_id} への権限付与に失敗しました: " . $e->getMessage());
    }

    return $file_id;
}
