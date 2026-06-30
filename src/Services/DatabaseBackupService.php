<?php
namespace App\Services;

use PDO;
use Exception;

class DatabaseBackupService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * データベース全体をSQLダンプ形式の文字列として生成する
     */
    public function exportDatabaseToSql(): string {
        $sqlDump = "-- Database Backup\n";
        $sqlDump .= "-- Generated on " . date('Y-m-d H:i:s') . "\n\n";
        $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // テーブル一覧を取得
        if ($driver === 'sqlite') {
            $tablesStmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $tablesStmt = $this->pdo->query("SHOW TABLES");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($tables as $table) {
            // テスト用のマイグレーション履歴や一時テーブルなど、除外したいものがあればここで制御可能
            // 今回は全テーブルを対象とします。
            
            $sqlDump .= "-- ------------------------------------------------------\n";
            $sqlDump .= "-- Table structure for table `{$table}`\n";
            $sqlDump .= "-- ------------------------------------------------------\n";
            $sqlDump .= "DROP TABLE IF EXISTS `{$table}`;\n";

            // CREATE TABLE 文を取得
            if ($driver === 'sqlite') {
                $createStmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = :name");
                $createStmt->execute(['name' => $table]);
                $sqlDump .= $createStmt->fetchColumn() . ";\n\n";
            } else {
                $createStmt = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
                $createResult = $createStmt->fetch(PDO::FETCH_ASSOC);
                $sqlDump .= $createResult['Create Table'] . ";\n\n";
            }

            // データを取得して INSERT INTO 文を作成
            $sqlDump .= "-- Dumping data for table `{$table}`\n";
            $dataStmt = $this->pdo->query("SELECT * FROM `{$table}`");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $sqlDump .= "INSERT INTO `{$table}` (";
                $columns = array_keys($rows[0]);
                $sqlDump .= implode(', ', array_map(fn($col) => "`{$col}`", $columns));
                $sqlDump .= ") VALUES\n";

                $valLines = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = $this->pdo->quote($val);
                        }
                    }
                    $valLines[] = "(" . implode(', ', $vals) . ")";
                }
                $sqlDump .= implode(",\n", $valLines) . ";\n";
            }
            $sqlDump .= "\n";
        }

        $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $sqlDump;
    }
}
