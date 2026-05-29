<?php
class ProjectRepository {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 全案件を依頼主情報付きで取得する（管理者用）
     */
    public function findAllWithClientInfo() {
        $query = "
            SELECT p.*, u.company_name 
            FROM projects p 
            JOIN users u ON p.client_id = u.id 
            ORDER BY p.created_at DESC
        ";
        return $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 特定の依頼主の案件を情報付きで取得する（依頼主用）
     */
    public function findByClientIdWithClientInfo($clientId) {
        $query = "
            SELECT p.*, u.company_name 
            FROM projects p 
            JOIN users u ON p.client_id = u.id 
            WHERE p.client_id = :cid
            ORDER BY p.created_at DESC
        ";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute(['cid' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * IDから案件情報を取得する
     */
    public function findById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * 案件の詳細情報（仕様情報・依頼主情報を含む）を取得する
     */
    public function findDetailById($id) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, s.*, u.company_name, u.contact_name as client_name, u.phone_number as client_phone 
            FROM projects p 
            LEFT JOIN project_specs s ON p.id = s.project_id 
            LEFT JOIN users u ON p.client_id = u.id 
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * 案件のステータスを更新する
     */
    public function updateStatus($id, $status) {
        $stmt = $this->pdo->prepare("UPDATE projects SET status = :status WHERE id = :id");
        return $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * 一次回答納期を更新する
     */
    public function updatePrimaryDueDate($id, $date) {
        $stmt = $this->pdo->prepare("UPDATE projects SET primary_due_date = :due WHERE id = :id");
        return $stmt->execute(['due' => $date, 'id' => $id]);
    }

    /**
     * 実績スケジュールを更新する
     */
    public function updateScheduleActuals($id, $jsonStr) {
        $stmt = $this->pdo->prepare("UPDATE projects SET schedule_actuals = :act WHERE id = :id");
        return $stmt->execute(['act' => $jsonStr, 'id' => $id]);
    }

    /**
     * アップロードモードを更新する
     */
    public function updateUploadMode($id, $mode) {
        $stmt = $this->pdo->prepare("UPDATE projects SET upload_mode = :mode WHERE id = :id");
        return $stmt->execute(['mode' => $mode, 'id' => $id]);
    }
}
