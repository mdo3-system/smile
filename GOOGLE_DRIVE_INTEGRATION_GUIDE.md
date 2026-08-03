# Google Drive API 連携・自動フォルダ構築ガイドライン (エージェント引き継ぎ用)

本ドキュメントは、PHP（またはその他Web言語）を用いて **Google Drive API** と連携し、**顧客別・案件別の階層フォルダを動的生成・キャッシング** しながらファイルを安全かつ円滑に管理・参照するシステムをゼロから構築するための手順書・仕様書です。

別のプロジェクト、ツール、別のアカウントで新規にGoogle Drive連携を実装する際は、本ドキュメントをAIエージェント（または開発担当者）へ引き渡して構築を行わせてください。

---

## 1. 全体建築と設計思想

本システムは以下の要件を満たすよう設計されています。

1. **二重・三重の動的フォルダ階層構造**
   - ルートフォルダ ➔ 顧客（会社）別フォルダ ➔ 案件（プロジェクト）別フォルダ
   - ファイルアップロード時に該当フォルダが存在しなければ自動検索・新規作成します。
2. **フォルダIDのデータベースキャッシング**
   - 毎回Google APIに問い合わせると処理が遅くなるため、一度生成された `drive_folder_id` をDBテーブル（`users`, `projects` 等）にキャッシュ保存し、2回目以降は即座にフォルダIDを取得します。
   - キャッシュされたフォルダIDがGoogle Drive上で手動削除されていた場合、自動検出して再作成する「自己修復機能」を備えます。
3. **自動パーミッション（閲覧権限）付与**
   - アップロードされたファイルおよび生成されたフォルダに対し、API経由で即座に「リンクを知っている全員に閲覧許可（`type: anyone`, `role: reader`）」を付与します。
   - これにより、プレビューURL（`view?usp=drivesdk`）やサムネイル画像URLをWeb画面上に埋め込んで簡単に表示可能にします。
4. **アクセストークンの自動サイレント更新（OAuth2対応）**
   - 初回認可後は `refresh_token` を保存し、トークンの有効期限（1時間）が切れた際はリクエスト時に裏側で自動的にトークンを再取得して `token.json` を更新します。
5. **ローカルフォールバック機能**
   - 万が一Google APIの障害や認証切れが発生しても、システムエラーで停止させず、ローカルの `uploads/` ディレクトリにバックアップ保存します。

---

## 2. 事前準備（Google Cloud Console での設定手順）

Google Drive APIを利用するため、対象のGoogleアカウントで Google Cloud Console の初期設定を行います。

### Step 1: プロジェクトの作成と API の有効化
1. [Google Cloud Console](https://console.cloud.google.com/) にログインします。
2. 新規プロジェクトを作成します（例: `MySystem-Drive-Integration`）。
3. 左メニュー「APIとサービス」 ➔ 「ライブラリ」を選択し、**Google Drive API** を検索して「有効にする」をクリックします。

### Step 2: OAuth 同意画面の設定
1. 「APIとサービス」 ➔ 「OAuth 同意画面」を開きます。
2. ユーザータイプを **「外部」** に選択して「作成」をクリックします。
3. アプリ名、ユーザーサポートメール、送信者メールアドレスを入力して保存します。
4. スコープの追加で `https://www.googleapis.com/auth/drive` を選択・追加します。
5. テストユーザーに対象のGoogleアカウントのメールアドレスを追加します。

### Step 3: OAuth 2.0 クライアント ID の発行
1. 「APIとサービス」 ➔ 「認証情報」を開きます。
2. 「認証情報を作成」 ➔ **「OAuth クライアント ID」** を選択します。
3. アプリケーションの種類: **「ウェブ アプリケーション」**
4. 承認済みのリダイレクト URI に認証コールバックURLを追加します。
   - 例: `https://your-domain.com/oauth2callback.php`
5. 作成後、`JSON をダウンロード` ボタンを押し、ダウンロードしたファイルを **`credentials.json`** にリネームしてWebサーバーのプロジェクトルートへ配置します。

---

## 3. 環境変数（`.env`）の設定

プロジェクトのルートディレクトリに `.env` ファイルを作成し、以下の情報を設定します。

```env
# Google Drive連携設定
GOOGLE_APPLICATION_CREDENTIALS=credentials.json
# 保存先のルートフォルダID（Google DriveのブラウザURLの folders/XXXX の末尾ID）
GOOGLE_DRIVE_FOLDER_ID=1A2B3C4D5E6F7G8H9I0J_EXAMPLE
```

---

## 4. ライブラリのインストール (Composer)

PHPプロジェクトの場合、公式 SDK をインストールします。

```bash
composer require google/apiclient:^2.0
```

---

## 5. 実装コードテンプレート (`google_drive_client.php`)

以下のコードをベースに構築してください。認証、フォルダ自動生成、キャッシング、ファイルUP、パーミッション付与の全ロジックが含まれています。

```php
<?php
// google_drive_client.php

require_once __DIR__ . '/vendor/autoload.php';

// 1. 簡易.envロード処理
if (!function_exists('load_env')) {
    function load_env($file_path) {
        if (!file_exists($file_path)) return;
        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $value = trim($value, '"\' ');
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
load_env(__DIR__ . '/.env');

/**
 * 2. Google Drive サービスインスタンスの取得（自動トークン更新付き）
 */
function get_google_drive_service() {
    static $service = null;
    if ($service !== null) return $service;

    $credentials_path = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: __DIR__ . '/credentials.json';
    if (!file_exists($credentials_path)) {
        throw new Exception("Google認証ファイルが見つかりません: " . $credentials_path);
    }

    $client = new Google\Client();
    $client->setAuthConfig($credentials_path);
    $client->addScope(Google\Service\Drive::DRIVE);
    $client->setAccessType('offline');

    $token_path = __DIR__ . '/token.json';
    if (file_exists($token_path)) {
        $accessToken = json_decode(file_get_contents($token_path), true);
        if (is_array($accessToken) && isset($accessToken['access_token'])) {
            $client->setAccessToken($accessToken);
        }
    }

    // トークン期限切れ時のサイレント更新処理
    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();
        if ($refreshToken) {
            $new_token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (!isset($new_token['refresh_token'])) {
                $new_token['refresh_token'] = $refreshToken;
            }
            file_put_contents($token_path, json_encode($new_token));
            $client->setAccessToken($new_token);
        } else {
            throw new Exception("Google Driveが未連携です。初回認証を行ってください。");
        }
    }

    $service = new Google\Service\Drive($client);
    return $service;
}

/**
 * 3. フォルダ作成 & 全員閲覧権限付与
 */
function create_google_drive_folder($folder_name, $parent_folder_id = null) {
    $service = get_google_drive_service();
    
    $file_metadata = new Google\Service\Drive\DriveFile([
        'name' => $folder_name,
        'mimeType' => 'application/vnd.google-apps.folder'
    ]);
    if ($parent_folder_id) {
        $file_metadata->setParents([$parent_folder_id]);
    }
    
    $folder = $service->files->create($file_metadata, [
        'fields' => 'id',
        'supportsAllDrives' => true
    ]);
    $folder_id = $folder->id;

    // 全員への閲覧権限を付与
    try {
        $permission = new Google\Service\Drive\Permission([
            'role' => 'reader',
            'type' => 'anyone'
        ]);
        $service->permissions->create($folder_id, $permission, ['supportsAllDrives' => true]);
    } catch (Exception $e) {
        error_log("フォルダ権限付与エラー: " . $e->getMessage());
    }

    return $folder_id;
}

/**
 * 4. 同名フォルダの検索
 */
function find_google_drive_folder($folder_name, $parent_folder_id = null) {
    $service = get_google_drive_service();
    $escaped_name = str_replace("'", "\\'", $folder_name);
    $query = "mimeType = 'application/vnd.google-apps.folder' and name = '{$escaped_name}' and trashed = false";
    if ($parent_folder_id) {
        $query .= " and '{$parent_folder_id}' in parents";
    }

    $response = $service->files->listFiles([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id, name)',
        'supportsAllDrives' => true
    ]);

    return (count($response->files) > 0) ? $response->files[0]->id : null;
}

/**
 * 5. 階層フォルダの動的生成 ＆ DBキャッシング
 */
function get_or_create_project_drive_folder($pdo, $project_id) {
    // 案件・顧客情報を取得
    $stmt = $pdo->prepare("
        SELECT p.project_name, p.drive_folder_id as project_folder_id, 
               u.id as client_id, u.company_name, u.drive_folder_id as client_folder_id
        FROM projects p JOIN users u ON p.client_id = u.id WHERE p.id = :pid
    ");
    $stmt->execute(['pid' => $project_id]);
    $data = $stmt->fetch();
    if (!$data) throw new Exception("案件が見つかりません。");

    if (!empty($data['project_folder_id'])) {
        return $data['project_folder_id'];
    }

    $root_folder_id = getenv('GOOGLE_DRIVE_FOLDER_ID');

    // 顧客フォルダの準備
    $client_folder_id = $data['client_folder_id'];
    $client_folder_name = !empty($data['company_name']) ? $data['company_name'] : "顧客_ID_" . $data['client_id'];
    
    if (empty($client_folder_id)) {
        $client_folder_id = find_google_drive_folder($client_folder_name, $root_folder_id)
            ?: create_google_drive_folder($client_folder_name, $root_folder_id);
        $pdo->prepare("UPDATE users SET drive_folder_id = :fid WHERE id = :uid")
            ->execute(['fid' => $client_folder_id, 'uid' => $data['client_id']]);
    }

    // 案件フォルダの準備
    $project_folder_name = $data['project_name'];
    $project_folder_id = find_google_drive_folder($project_folder_name, $client_folder_id)
        ?: create_google_drive_folder($project_folder_name, $client_folder_id);
    
    $pdo->prepare("UPDATE projects SET drive_folder_id = :fid WHERE id = :pid")
        ->execute(['fid' => $project_folder_id, 'pid' => $project_id]);

    return $project_folder_id;
}

/**
 * 6. 指定フォルダへファイルをアップロード ＆ 全員閲覧権限付与
 */
function upload_to_google_drive_folder($local_file_path, $file_name, $mime_type, $parent_folder_id) {
    $service = get_google_drive_service();

    $file_metadata = new Google\Service\Drive\DriveFile(['name' => $file_name]);
    if ($parent_folder_id) {
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

    // 閲覧権限の付与
    try {
        $permission = new Google\Service\Drive\Permission([
            'role' => 'reader',
            'type' => 'anyone'
        ]);
        $service->permissions->create($file_id, $permission, ['supportsAllDrives' => true]);
    } catch (Exception $e) {
        error_log("ファイル権限付与エラー: " . $e->getMessage());
    }

    return $file_id; // 保存された Drive File ID を返す
}
```

---

## 6. 初回 Web 認可処理 (`oauth2callback.php`) の仕組み

管理者がブラウザで認証を行い、`token.json` を発行するためのスクリプトです。

```php
<?php
// oauth2callback.php
require_once __DIR__ . '/vendor/autoload.php';

$client = new Google\Client();
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->addScope(Google\Service\Drive::DRIVE);
$client->setAccessType('offline');
$client->setPrompt('consent'); // refresh_tokenを確実に取得するために設定

$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/oauth2callback.php';
$client->setRedirectUri($redirect_uri);

if (!isset($_GET['code'])) {
    $auth_url = $client->createAuthUrl();
    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit;
} else {
    $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $token = $client->getAccessToken();
    file_put_contents(__DIR__ . '/token.json', json_encode($token));
    echo "Google Driveとの連携設定が完了しました！このページを閉じて元の画面に戻ってください。";
}
```

---

## 7. Web UI での表示・プレビュー用 URL 生成ロジック

Google Drive の `file_id` をデータベースに保持した後、画面上に表示する際は以下のURLフォーマットを使用します。

### ① PDF・CADデータ・ドキュメントの閲覧・ダウンロードリンク
```php
$file_id = $record['drive_file_id'];
$url = "https://drive.google.com/file/d/{$file_id}/view?usp=drivesdk";

echo '<a href="' . htmlspecialchars($url) . '" target="_blank">📄 ファイルを開く</a>';
```

### ② 画像（JPEG/PNG/WebP等）のサムネイル埋め込み
```php
$file_id = $record['drive_file_id'];
$thumb_url = "https://drive.google.com/thumbnail?id={$file_id}&sz=w400"; // szで幅を指定

echo '<img src="' . htmlspecialchars($thumb_url) . '" alt="サムネイル">';
```

---

## 8. データベース設計のポイント

`drive_file_id` や `drive_folder_id` を保存するため、関連するテーブルに以下のカラムを設けてください。

1. **`users` テーブル (顧客・業者マスタ)**
   - `drive_folder_id` VARCHAR(255) NULL (顧客専用ルートフォルダID)
2. **`projects` テーブル (案件マスタ)**
   - `drive_folder_id` VARCHAR(255) NULL (案件専用フォルダID)
3. **`project_files` / `attachments` テーブル (ファイル管理)**
   - `drive_file_id` VARCHAR(255) NULL (Google Drive上のユニークファイルID)
   - `file_name` VARCHAR(255) NOT NULL (元のファイル名)
   - `version` INT DEFAULT 1 (バージョン管理用)

---

## 9. 他のエージェント・開発者への指示出し用プロンプト例文

別のエージェントに本機能を実装してもらう際は、以下のプロンプトをそのままコピペして渡してください。

> **【指示プロンプト例】**  
> 「添付の `GOOGLE_DRIVE_INTEGRATION_GUIDE.md` の仕様に従って、Google Drive API を使用した階層フォルダ自動生成およびファイルアップロード連携機能を実装してください。  
> 初回認証フロー (`oauth2callback.php`)、`refresh_token` による自動トークン維持、顧客・案件別のフォルダ階層作成とDBへのIDキャッシング、ファイル表示用URL（`view?usp=drivesdk` および `thumbnail?id=`）の出力まで一通り組み込んでください。」
