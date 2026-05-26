<?php
// notifier.php
require_once 'vendor/autoload.php';

// .env の手動パース
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

use Twilio\Rest\Client as TwilioClient;

class Notifier {
    private $pdo;
    private $twilio;
    private $from_number;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        $sid = $_ENV['TWILIO_ACCOUNT_SID'] ?? '';
        $token = $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
        $this->from_number = $_ENV['TWILIO_PHONE_NUMBER'] ?? '';

        // テスト用のプレースホルダー値の場合は初期化をスキップしモック動作とする
        if ($sid && $token && strpos($sid, 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX') === false) {
            $this->twilio = new TwilioClient($sid, $token);
        }
    }

    /**
     * 指定された電話番号へSMSを送信する
     */
    public function send_sms($to, $message) {
        if (!$this->twilio) {
            // テスト環境または未設定時はログにモック出力
            error_log("【Notifier MOCK】To: $to, Body: $message");
            return true; 
        }

        try {
            // 電話番号を E.164 形式に正規化
            $to_normalized = $this->normalize_phone_number($to);
            
            $this->twilio->messages->create(
                $to_normalized,
                [
                    'from' => $this->from_number,
                    'body' => $message
                ]
            );
            return true;
        } catch (Exception $e) {
            error_log("SMS Send Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * クールダウンチェック（連続送信制限）
     * 1時間に1回以上の送信を防ぐ
     */
    public function check_cooldown($user_id) {
        $stmt = $this->pdo->prepare("SELECT last_sms_sent_at FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $last_sent = $stmt->fetchColumn();

        if ($last_sent) {
            $last_time = strtotime($last_sent);
            if (time() - $last_time < 3600) { // 1時間 (3600秒)
                return false; // クールダウン中
            }
        }
        return true; // 送信可能
    }

    /**
     * クールダウン時間を更新
     */
    public function update_cooldown($user_id) {
        $stmt = $this->pdo->prepare("UPDATE users SET last_sms_sent_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
    }

    /**
     * 日本の電話番号を E.164 形式に正規化 (09012345678 -> +819012345678)
     */
    public function normalize_phone_number($phone) {
        $phone = preg_replace('/\D/', '', $phone); // 数字以外を除去
        if (strpos($phone, '0') === 0) {
            $phone = '+81' . substr($phone, 1); // 090... -> +8190...
        }
        return $phone;
    }
}
?>
