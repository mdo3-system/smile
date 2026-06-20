import re
import os
import subprocess

def run_git_restore():
    files = [
        'project_subcontractor.php',
        'subcontractor_portal.php',
        'src/Helpers/StatusHelper.php',
        'index.php',
        'project_detail.php',
        'components/dashboard_client.php',
        'admin_sales.php',
        'actions/action_schedule.php',
        'src/Services/UploadService.php',
        'estimate_print.php',
        'estimate_pdf_generator.php'
    ]
    for file in files:
        if os.path.exists(file):
            subprocess.run(['git', 'restore', file])
            print(f"Restored {file} to HEAD")

def apply_regex_replace(filepath, pattern, replacement):
    with open(filepath, 'r', encoding='utf-8', newline='') as f:
        content = f.read()
    
    normalized_content = content.replace('\r\n', '\n')
    
    # 正規表現でマッチする部分を探す
    match = re.search(pattern, normalized_content, flags=re.DOTALL)
    if not match:
        print(f"Warning: Pattern NOT matched in {filepath}")
        return False
        
    # マッチした正確なテキストを単純文字列置換で置き換える (バックスラッシュエラーを完全に防ぐ)
    matched_text = match.group(0)
    new_content = normalized_content.replace(matched_text, replacement)
    
    if '\r\n' in content:
        new_content = new_content.replace('\n', '\r\n')
        
    with open(filepath, 'w', encoding='utf-8', newline='') as f:
        f.write(new_content)
    print(f"Successfully modified {filepath}")
    return True

run_git_restore()

print("\n--- Applying Regex Replacements ---\n")

# =========================================================================
# 1. project_subcontractor.php 修正
# =========================================================================
filepath_sub = 'project_subcontractor.php'

# (A) 納品時の INSERT INTO project_files
pattern_insert = r'// 3\. 新しいファイルを登録.*?\s+\$stmtInsertFile = \$pdo->prepare\(\s*"\s*INSERT INTO project_files\s+\(project_id,\s+file_category,\s+file_name,\s+drive_file_id,\s+version,\s+is_latest\)\s+VALUES\s+\(:pid,\s+:cat,\s+:fname,\s+:fpath,\s+:ver,\s+1\)\s*"\s*\);\s+\$stmtInsertFile->execute\(\[\s*\'pid\'\s+=>\s+\$project_id,\s*\'cat\'\s+=>\s+\$category,\s*\'fname\'\s+=>\s+\$file_name,\s*\'fpath\'\s+=>\s+\$drive_file_id,\s*\'ver\'\s+=>\s+\$new_v\s*\]\);'

replacement_insert = """// 3. 新しいファイルを登録 (これらは管理者と業者の間のみで表示される)
                $stmtInsertFile = $pdo->prepare("
                    INSERT INTO project_files (project_id, subcontractor_order_id, file_category, file_name, drive_file_id, version, is_latest) 
                    VALUES (:pid, :order_id, :cat, :fname, :fpath, :ver, 1)
                ");
                $stmtInsertFile->execute([
                    'pid' => $project_id,
                    'order_id' => $order_id,
                    'cat' => $category,
                    'fname' => $file_name,
                    'fpath' => $drive_file_id,
                    'ver' => $new_v
                ]);"""

apply_regex_replace(filepath_sub, pattern_insert, replacement_insert)

# (B) 業者側のタスク取得クエリ $stmt
pattern_query_sub = r'\$stmt\s*=\s*\$pdo->prepare\(\s*"\s*SELECT\s+o\.\*,\s*p\.project_name,\s*p\.status\s+AS\s+project_status\s+FROM\s+subcontractor_orders\s+o\s+JOIN\s+projects\s+p\s+ON\s+o\.project_id\s*=\s*p\.id\s+WHERE\s+o\.subcontractor_id\s*=\s*:sub_id\s+AND\s+o\.project_id\s*=\s*:pid\s+ORDER\s+BY\s+o\.created_at\s+DESC\s*"\s*\);'

replacement_query_sub = """$stmt = $pdo->prepare("
        SELECT o.*, p.project_name, p.status AS project_status,
               f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
               f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
               f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY subcontractor_order_id) f1 ON o.id = f1.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY subcontractor_order_id) f2 ON o.id = f2.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY subcontractor_order_id) f3 ON o.id = f3.subcontractor_order_id
        WHERE o.subcontractor_id = :sub_id AND o.project_id = :pid
        ORDER BY o.created_at DESC
    ");"""

apply_regex_replace(filepath_sub, pattern_query_sub, replacement_query_sub)

# (C) 管理者側の発注履歴クエリ $stmtOrd
pattern_query_admin = r'\$stmtOrd\s*=\s*\$pdo->prepare\(\s*"\s*SELECT\s+o\.\*,\s*u\.contact_name,\s*f1\.drive_file_id\s+AS\s+pdf_id,.*?WHERE\s+o\.project_id\s*=\s*:pid\s+ORDER\s+BY\s+o\.created_at\s+DESC\s*"\s*\);'

replacement_query_admin = """$stmtOrd = $pdo->prepare("
        SELECT o.*, u.contact_name,
               f1.drive_file_id AS pdf_id, f1.file_name AS pdf_name, f1.version AS pdf_ver,
               f2.drive_file_id AS arc_d_id, f2.file_name AS arc_d_name, f2.version AS arc_d_ver,
               f3.drive_file_id AS arc_s_id, f3.file_name AS arc_s_name, f3.version AS arc_s_ver
        FROM subcontractor_orders o 
        JOIN users u ON o.subcontractor_id = u.id 
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY subcontractor_order_id) f1 ON o.id = f1.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY subcontractor_order_id) f2 ON o.id = f2.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY subcontractor_order_id) f3 ON o.id = f3.subcontractor_order_id
        WHERE o.project_id = :pid 
        ORDER BY o.created_at DESC
    ");"""

apply_regex_replace(filepath_sub, pattern_query_admin, replacement_query_admin)

# (D-1) 業者側タスクループ内の 納品ファイル一覧 表示追加
pattern_file_list_trigger = r'<\?php\s+if\s+\(\$task\[\'status\'\]\s*!==\s*\'cancelled\'\):\s*\?>'

replacement_file_list_trigger = """<?php if (!empty($task['pdf_id']) || !empty($task['arc_d_id']) || !empty($task['arc_s_id'])): ?>
                                        <div style="margin-top:8px; padding:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                                            <strong style="color:#334155; font-size:12px;">📤 納品ファイル一覧:</strong>
                                            <ul style="margin:4px 0 0 0; padding-left:20px; font-size:12px;">
                                                <?php if (!empty($task['arc_d_id'])): 
                                                    $d_url = (strpos($task['arc_d_id'], 'uploads/') === 0) ? $task['arc_d_id'] : 'https://drive.google.com/file/d/' . $task['arc_d_id'] . '/view?usp=drivesdk';
                                                ?>
                                                    <li>意匠用アーキ: <a href="<?= htmlspecialchars($d_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($task['arc_d_name'], ENT_QUOTES) ?> (V<?= $task['arc_d_ver'] ?>)</a></li>
                                                <?php endif; ?>
                                                <?php if (!empty($task['arc_s_id'])): 
                                                    $s_url = (strpos($task['arc_s_id'], 'uploads/') === 0) ? $task['arc_s_id'] : 'https://drive.google.com/file/d/' . $task['arc_s_id'] . '/view?usp=drivesdk';
                                                ?>
                                                    <li>構造用アーキ: <a href="<?= htmlspecialchars($s_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($task['arc_s_name'], ENT_QUOTES) ?> (V<?= $task['arc_s_ver'] ?>)</a></li>
                                                <?php endif; ?>
                                                <?php if (!empty($task['pdf_id'])): 
                                                    $pdf_url = (strpos($task['pdf_id'], 'uploads/') === 0) ? $task['pdf_id'] : 'https://drive.google.com/file/d/' . $task['pdf_id'] . '/view?usp=drivesdk';
                                                ?>
                                                    <li>構造図PDF: <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES) ?>" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:bold;"><?= htmlspecialchars($task['pdf_name'], ENT_QUOTES) ?> (V<?= $task['pdf_ver'] ?>)</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($task['status'] !== 'cancelled'): ?>"""

apply_regex_replace(filepath_sub, pattern_file_list_trigger, replacement_file_list_trigger)

# (D-2) $task_type の定義追加
pattern_task_type_trigger = r'\$show_struct_delivery\s*=\s*\(\$project_info\[\'req_permit\'\]\s*==\s*1\s*\|\|\s*\$project_info\[\'req_opt_kisohari\'\]\s*==\s*1\);\s*\?>'

replacement_task_type_trigger = """$show_struct_delivery = ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1);
                                        $task_type = $task['order_type'] ?: 'design';
                                        ?>"""

apply_regex_replace(filepath_sub, pattern_task_type_trigger, replacement_task_type_trigger)

# (D-3) 意匠図納品エリアの task_type 条件追加
pattern_design_trigger = r'<!-- ■ 意匠図の納品エリア -->\s*<div style="background:#f8fafc;'

replacement_design_trigger = """<!-- ■ 意匠図の納品エリア -->
                                            <?php if ($task_type === 'design'): ?>
                                            <div style="background:#f8fafc;"""

apply_regex_replace(filepath_sub, pattern_design_trigger, replacement_design_trigger)

# (D-4) 意匠図の閉じタグと構造図への条件追加
pattern_struct_trigger = r'</form>\s*</div>\s*<\?php\s+if\s+\(\$show_struct_delivery\):\s*\?>\s*<!-- ■ 構造図の納品エリア -->\s*<div style="background:#f8fafc;'

replacement_struct_trigger = """</form>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($show_struct_delivery && $task_type === 'struct'): ?>
                                                <!-- ■ 構造図の納品エリア -->
                                                <div style="background:#f8fafc;"""

apply_regex_replace(filepath_sub, pattern_struct_trigger, replacement_struct_trigger)


# =========================================================================
# 2. subcontractor_portal.php 修正
# =========================================================================
filepath_portal = 'subcontractor_portal.php'
pattern_portal_query = r'// 業者全体.*?\$stmtTasks = \$pdo->prepare\(\s*"\s*SELECT o\.\*, p\.project_name\s+FROM subcontractor_orders o\s+JOIN projects p ON o\.project_id = p\.id\s+WHERE o\.subcontractor_id = :sub_id OR o\.subcontractor_id IN \(SELECT id FROM users WHERE parent_id = :sub_id\)\s+ORDER BY o\.created_at DESC\s*"\s*\);\s*\$stmtTasks->execute\(\[\'sub_id\' => \$target_sub_id\]\);'

replacement_portal_query = """// 業者全体（本アカウント宛て ＋ スタッフ宛て）またはメインアカウントの場合
    $stmtTasks = $pdo->prepare("
        SELECT o.*, p.project_name 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2)
        ORDER BY o.created_at DESC
    ");
    $stmtTasks->execute([
        'sub_id_1' => $target_sub_id,
        'sub_id_2' => $target_sub_id
    ]);"""

apply_regex_replace(filepath_portal, pattern_portal_query, replacement_portal_query)


# =========================================================================
# 3. StatusHelper.php 修正 (大枠の置換)
# =========================================================================
filepath_helper = 'src/Helpers/StatusHelper.php'
pattern_helper_full = r'public static function getBallStatus\(array \$project, PDO \$pdo\): array\s*\{.*?\n\s*\}'

replacement_helper_full = """public static function getBallStatus(array $project, PDO $pdo, string $user_role = null): array
    {
        $status = $project['status'] ?? '';
        $res = null;

        if ($status === 'completed') {
            $res = [
                'ball_owner' => 'completed',
                'label' => '完了',
                'color' => '#10b981' // Green
            ];
        }
        elif ($status === 'quote_req') {
            // Check if there is an estimate issued for this project
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM estimates WHERE project_id = :pid");
            $stmt->execute(['pid' => $project['id']]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                // Estimate exists -> Client has the ball (見積確認中)
                $res = [
                    'ball_owner' => 'client',
                    'label' => '回答待ち (依頼主ボール)',
                    'color' => '#e67e22' // Orange
                ];
            } else {
                // No estimate -> Admin has the ball (見積作成中)
                $res = [
                    'ball_owner' => 'admin',
                    'label' => '図書作成中 (管理者ボール)',
                    'color' => '#3b82f6' // Blue
                ];
            }
        }
        else {
            // Fetch active subcontractor tasks (not cancelled)
            $stmtTasks = $pdo->prepare("SELECT * FROM subcontractor_orders WHERE project_id = :pid AND status != 'cancelled'");
            $stmtTasks->execute(['pid' => $project['id']]);
            $tasks = $stmtTasks->fetchAll();

            if (count($tasks) > 0) {
                $has_sub_ball = false;
                $has_delivered_task = false;
                foreach ($tasks as $task) {
                    if ($task['status'] === 'requested' || $task['status'] === 'accepted') {
                        $has_sub_ball = true;
                    } elseif ($task['status'] === 'delivered') {
                        $has_delivered_task = true;
                    }
                }

                if ($has_sub_ball) {
                    $res = [
                        'ball_owner' => 'subcontractor',
                        'label' => '作成中 (協力業者ボール)',
                        'color' => '#8b5cf6' // Purple
                    ];
                }
                elif ($has_delivered_task) {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '納品検収中 (管理者ボール)',
                        'color' => '#3b82f6' // Blue
                    ];
                }
            }

            if (!$res) {
                // If no active subcontractor tasks are in progress, let's look at project status
                if ($status === 'submission') {
                    $res = [
                        'ball_owner' => 'shared_waiting',
                        'label' => '審査待ち (共通)',
                        'color' => '#f59e0b' // Amber
                    ];
                }
                elif ($status === 'submitting') {
                    $res = [
                        'ball_owner' => 'shared_waiting',
                        'label' => '申請中 (共通待ち)',
                        'color' => '#f59e0b'
                    ];
                }
                elif ($status === 'primary_prep' || $status === 'structural_dwg' || $status === 'correction') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6' // Blue
                    ];
                }
                elif ($status === 'contracted') {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                }
                else {
                    $res = [
                        'ball_owner' => 'admin',
                        'label' => '図書作成中 (管理者ボール)',
                        'color' => '#3b82f6'
                    ];
                }
            }
        }

        // 依頼主(client)には協力業者の存在を見せないため、協力業者ボールは管理者ボールとして返す
        if ($res['ball_owner'] === 'subcontractor' && $user_role === 'client') {
            return [
                'ball_owner' => 'admin',
                'label' => '図書作成中 (管理者ボール)',
                'color' => '#3b82f6'
            ];
        }

        return $res;
    }"""

apply_regex_replace(filepath_helper, pattern_helper_full, replacement_helper_full)


# =========================================================================
# 4. index.php & project_detail.php / components の getBallStatus と status_labels 修正
# =========================================================================
filepath_index = 'index.php'
pattern_index_call = r'\$ball\s*=\s*\\App\\Helpers\\StatusHelper::getBallStatus\(\$project,\s*\$pdo\);'
replacement_index_call = """$ball = \\App\\Helpers\\StatusHelper::getBallStatus($project, $pdo, $_SESSION['role'] ?? null);"""
apply_regex_replace(filepath_index, pattern_index_call, replacement_index_call)

pattern_labels_index = r'\$status_labels\s*=\s*\[\s*\'quote_req\'\s*=>\s*\'見積依頼\',.*?\n\s*\];'
replacement_labels_index = """$status_labels = [
                'quote_req'      => '見積依頼',
                'contracted'     => '受注済',
                'primary_prep'   => '一次回答準備中',
                'structural_dwg' => '構造図作成中',
                'submission'     => '提出済・確認中',
                'submitting'     => '申請中',
                'correction'     => '補正対応中',
                'completed'      => '完了'
            ];"""
apply_regex_replace(filepath_index, pattern_labels_index, replacement_labels_index)

filepath_detail = 'project_detail.php'
pattern_detail_call = r'\$ball\s*=\s*\\App\\Helpers\\StatusHelper::getBallStatus\(\$project_info,\s*\$pdo\);'
replacement_detail_call = """$ball = \\App\\Helpers\\StatusHelper::getBallStatus($project_info, $pdo, $_SESSION['role'] ?? null);"""
apply_regex_replace(filepath_detail, pattern_detail_call, replacement_detail_call)

pattern_labels_detail = r'\$status_labels\s*=\s*\[\s*\'quote_req\'\s*=>\s*\'見積依頼\',.*?\n\s*\];'
replacement_labels_detail = """$status_labels = [
    'quote_req' => '見積依頼',
    'doc_submitted' => '図書提出済',
    'primary_prep' => '一次回答準備中',
    'contracted' => 'スケジュール確定',
    'structural_dwg' => '構造図作成中',
    'submission' => '提出済・確認中',
    'submitting' => '申請中',
    'correction' => '補正対応中',
    'completed' => '完了'
];"""
apply_regex_replace(filepath_detail, pattern_labels_detail, replacement_labels_detail)

filepath_dashboard = 'components/dashboard_client.php'
pattern_labels_dashboard = r'\$status_labels\s*=\s*\[\s*\'quote_req\'\s*=>\s*\'見積依頼\',.*?\n\s*\];'
replacement_labels_dashboard = """$status_labels = [
                            'quote_req'      => '見積依頼',
                            'contracted'     => '受注済',
                            'primary_prep'   => '一次回答準備中',
                            'structural_dwg' => '構造図作成中',
                            'submission'     => '提出済・確認中',
                            'submitting'     => '申請中',
                            'correction'     => '補正対応中',
                            'completed'      => '完了'
                        ];"""
apply_regex_replace(filepath_dashboard, pattern_labels_dashboard, replacement_labels_dashboard)

filepath_sales = 'admin_sales.php'
pattern_labels_sales_1 = r'\$proj_status_labels\s*=\s*\[\s*\'quote_req\'\s*=>\s*\'見積依頼\',.*?\n\s*\];'
replacement_labels_sales_1 = """$proj_status_labels = [
                    'quote_req' => '見積依頼', 
                    'quote_sent' => '見積送付済', 
                    'doc_submitted' => '図書提出済', 
                    'primary_prep' => '一次回答準備中', 
                    'contracted' => '受注済', 
                    'structural_dwg' => '構造図作成中', 
                    'submission' => '提出済・確認中', 
                    'submitting' => '申請中',
                    'correction' => '補正対応中', 
                    'completed' => '完了'
                ];"""
apply_regex_replace(filepath_sales, pattern_labels_sales_1, replacement_labels_sales_1)

pattern_labels_sales_2 = r'\$status_labels\s*=\s*\[\s*\'quote_req\'\s*=>\s*\'見積依頼\',.*?\n\s*\];'
replacement_labels_sales_2 = """$status_labels = [
    'quote_req'      => '見積依頼',
    'contracted'     => '受注済',
    'primary_prep'   => '一次回答中',
    'structural_dwg' => '構造図作成中',
    'submission'     => '提出済・確認中',
    'submitting'     => '申請中',
    'correction'     => '補正対応中',
    'completed'      => '完了'
];"""
apply_regex_replace(filepath_sales, pattern_labels_sales_2, replacement_labels_sales_2)


# =========================================================================
# 5. actions/action_schedule.php 修正
# =========================================================================
filepath_action_schedule = 'actions/action_schedule.php'

# (A) 「申請図書一式UP」の保存時にステータスを `'submitting'` (申請中) に自動更新
pattern_actual_update = r'\$stmt\s*=\s*\$pdo->prepare\(\s*"\s*UPDATE projects SET \{\$db_col\} = :act WHERE id = :pid\s*"\s*\);\s*\$stmt->execute\(\[\s*\'act\'\s+=>\s+json_encode\(\$actuals\),\s*\'pid\'\s+=>\s+\$project_id\s*\]\);\s*// チャットへ自動通知メッセージを投稿'

replacement_actual_update = """$stmt = $pdo->prepare("UPDATE projects SET {$db_col} = :act WHERE id = :pid");
            $stmt->execute(['act' => json_encode($actuals), 'pid' => $project_id]);

            // 「申請図書一式UP」の実施日が設定された場合、案件ステータスを「申請中」(submitting) に自動遷移
            if (!empty($actual_date)) {
                $is_submitting_step = false;
                if ($schedule_type === 'permit' && $step_idx == 7) $is_submitting_step = true;
                if ($schedule_type === 'wall' && $step_idx == 4) $is_submitting_step = true;
                if ($schedule_type === 'skin' && $step_idx == 4) $is_submitting_step = true;
                if ($schedule_type === 'sky' && $step_idx == 3) $is_submitting_step = true;

                if ($is_submitting_step) {
                    $stmtStatusUpd = $pdo->prepare("UPDATE projects SET status = 'submitting' WHERE id = :pid");
                    $stmtStatusUpd->execute(['pid' => $project_id]);
                }
            }

            // チャットへ自動通知メッセージを投稿"""

apply_regex_replace(filepath_action_schedule, pattern_actual_update, replacement_actual_update)

# (B) 「補正対応」の保存時に取引条件付きのチャット通知を行う
pattern_actual_chat = r'\$step_name\s*=\s*\$steps\[\$step_idx\]\[\'name\'\]\s*.*?\s*\$chat_msg\s*=\s*".*?";\s*.*?\s*\$thread_type\s*=\s*\(\$schedule_type\s*===\s*\'permit\'\)\s*\?\s*\'client_admin_permit\'\s*:\s*\'client_admin_\'\s*\.\s*\$schedule_type;'

replacement_actual_chat = """$step_name = $steps[$step_idx]['name'] ?? "工程 #{$step_idx}";
                $action_desc = "「{$actual_date}」に設定";
                $chat_msg = "【スケジュール実績更新】\\n{$step_name} の実施日が{$action_desc}されました。";

                // 「補正対応」の実施日が設定された場合、取引条件のチャット通知を追加
                $is_correction_step = false;
                if ($schedule_type === 'permit' && $step_idx == 9) $is_correction_step = true;
                if ($schedule_type === 'wall' && $step_idx == 6) $is_correction_step = true;
                if ($schedule_type === 'skin' && $step_idx == 6) $is_correction_step = true;
                if ($schedule_type === 'sky' && $step_idx == 6) $is_correction_step = true;

                if ($is_correction_step) {
                    $chat_msg .= "\\n審査完了しましたら、審査完了にしていただき、1週間以内の残金のご清算をお願いします。初回見積もり時に、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算が、お取引条件となります。";
                }
                
                $thread_type = ($schedule_type === 'permit') ? 'client_admin_permit' : 'client_admin_' . $schedule_type;"""

apply_regex_replace(filepath_action_schedule, pattern_actual_chat, replacement_actual_chat)


# =========================================================================
# 6. src/Services/UploadService.php 修正
# =========================================================================
filepath_upload_service = 'src/Services/UploadService.php'
pattern_upload_commit = r'\$this->pdo->commit\(\);\s*return true;\s*\}\s*catch\s*\(Exception\s+\$e\)\s*\{'

replacement_upload_commit = """// 補正通知 (correction_notice) ファイルがアップロードされた場合で、ステータスが「申請中」であれば「補正対応中」に更新
            if ($fileCategory === 'correction_notice') {
                $stmtCheckStatus = $this->pdo->prepare("SELECT status FROM projects WHERE id = :id");
                $stmtCheckStatus->execute(['id' => $projectId]);
                $currentStatus = $stmtCheckStatus->fetchColumn();
                if ($currentStatus === 'submitting') {
                    $stmtUpdateStatus = $this->pdo->prepare("UPDATE projects SET status = 'correction' WHERE id = :id");
                    $stmtUpdateStatus->execute(['id' => $projectId]);

                    // チャット通知 (自動)
                    $msgSubmittingCorrection = "【自動通知】補正通知書がアップロードされました。案件ステータスを「申請中」から「補正対応中」に変更しました。";
                    $stmtMsgCorrection = $this->pdo->prepare("INSERT INTO messages (project_id, sender_id, thread_type, message_text) VALUES (:pid, :sid, :thread, :msg)");
                    $stmtMsgCorrection->execute([
                        'pid' => $projectId,
                        'sid' => $userId,
                        'thread' => $threadType,
                        'msg' => $msgSubmittingCorrection
                    ]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {"""

apply_regex_replace(filepath_upload_service, pattern_upload_commit, replacement_upload_commit)


# =========================================================================
# 7. 注意書きの追記 (project_detail.phpモーダル定型文, estimate_print.php, estimate_pdf_generator.php)
# =========================================================================

# (A) project_detail.php 定型文
target_greeting_text = """3. 一次回答を1か月以内にご確認いただきます。お見積額の50%入金をお願い致します。ご入金確認後4営業日以内に構造図をUP致します。"""
replacement_greeting_text = """3. 一次回答を1か月以内にご確認いただきます。一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算が、お取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。"""
apply_regex_replace(filepath_detail, target_greeting_text, replacement_greeting_text)

# (B) estimate_print.php
filepath_est_print = 'estimate_print.php'
target_est_print_note = """・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>"""
replacement_est_print_note = """・業務の流れとして、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算がお取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。<br>"""
apply_regex_replace(filepath_est_print, target_est_print_note, replacement_est_print_note)

# (C) estimate_pdf_generator.php
filepath_est_pdf = 'estimate_pdf_generator.php'
target_est_pdf_note = """・業務の流れとして、一次回答チェック後に見積額の50%のご入金をお願いしております。<br>"""
replacement_est_pdf_note = """・業務の流れとして、一次回答時に本見積額の50％、審査完了から1週間以内の残金のご清算がお取引条件となります。ご入金確認後4営業日以内に構造図をUP致します。<br>"""
apply_regex_replace(filepath_est_pdf, target_est_pdf_note, replacement_est_pdf_note)

print("\n--- Replacements Complete ---")
