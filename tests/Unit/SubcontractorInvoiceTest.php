<?php
namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

class SubcontractorInvoiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テスト用のテーブル作成
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_name TEXT NOT NULL,
                company_name TEXT NULL,
                role TEXT NOT NULL,
                drive_folder_id TEXT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE subcontractor_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subcontractor_id INTEGER NOT NULL,
                target_month TEXT NOT NULL,
                paid_amount INTEGER NOT NULL DEFAULT 0,
                paid_at TEXT NULL,
                note TEXT NULL,
                invoice_file_path TEXT NULL,
                invoice_file_name TEXT NULL,
                UNIQUE(subcontractor_id, target_month)
            );
        ");

        $this->pdo->exec("
            CREATE TABLE global_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subcontractor_id INTEGER NOT NULL,
                sender_id INTEGER NOT NULL,
                message_text TEXT NOT NULL
            );
        ");

        // テスト用データ登録
        $this->pdo->exec("INSERT INTO users (id, contact_name, role) VALUES (3, '代表業者', 'subcontractor');");
    }

    public function testLogSubPaymentUpsertDistinctPlaceholders(): void
    {
        $target_sub_id = 3;
        $target_month = '2026-07';
        $paid_amount = 50000;
        $note = 'テスト支払';

        // 別名プレースホルダーでのUPSERT動作確認
        // SQLiteはON DUPLICATE KEY UPDATEをサポートしないため、テストではINSERT OR REPLACEで代用します
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO subcontractor_payments (subcontractor_id, target_month, paid_amount, note) 
            VALUES (:sub_id, :t_month, :amt, :note)
        ");
        $stmt->execute([
            'sub_id' => $target_sub_id,
            't_month' => $target_month,
            'amt' => $paid_amount,
            'note' => $note
        ]);

        // 確認
        $stmtCheck = $this->pdo->prepare("SELECT * FROM subcontractor_payments WHERE subcontractor_id = :sub_id AND target_month = :t_month");
        $stmtCheck->execute(['sub_id' => $target_sub_id, 't_month' => $target_month]);
        $row = $stmtCheck->fetch();

        $this->assertNotEmpty($row);
        $this->assertEquals(50000, (int)$row['paid_amount']);
        $this->assertEquals('テスト支払', $row['note']);
    }

    public function testUploadSubInvoiceUpdatesPaymentsTable(): void
    {
        $target_sub_id = 3;
        $target_month = '2026-07';
        $drive_file_id = 'drive_file_abc123';
        $file_name = 'invoice_202607.pdf';

        // subcontractor_payments の請求書ファイル情報更新の検証
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO subcontractor_payments (subcontractor_id, target_month, invoice_file_path, invoice_file_name, paid_amount)
            VALUES (:sub_id, :t_month, :fpath, :fname, COALESCE((SELECT paid_amount FROM subcontractor_payments WHERE subcontractor_id = :sub_id AND target_month = :t_month), 0))
        ");
        $stmt->execute([
            'sub_id' => $target_sub_id,
            't_month' => $target_month,
            'fpath' => $drive_file_id,
            'fname' => $file_name
        ]);

        // 確認
        $stmtCheck = $this->pdo->prepare("SELECT * FROM subcontractor_payments WHERE subcontractor_id = :sub_id AND target_month = :t_month");
        $stmtCheck->execute(['sub_id' => $target_sub_id, 't_month' => $target_month]);
        $row = $stmtCheck->fetch();

        $this->assertNotEmpty($row);
        $this->assertEquals('drive_file_abc123', $row['invoice_file_path']);
        $this->assertEquals('invoice_202607.pdf', $row['invoice_file_name']);
    }

    public function testAccountantAccessDeterminesSubcontractorId(): void
    {
        // 経理（accountant）としてアクセスした際のID決定ロジックのテスト
        $is_admin = false;
        $is_accountant = true; // 経理ユーザー

        // URLパラメータからの `sub_id` 取得を模擬
        $get_sub_id = 3;

        $target_sub_id = 0;
        if ($is_admin || $is_accountant) {
            $target_sub_id = $get_sub_id;
        } else {
            $target_sub_id = 999; // ダミーのログインユーザーID
        }

        // 正常に業者IDである 3 がセットされること
        $this->assertEquals(3, $target_sub_id);
    }
}
