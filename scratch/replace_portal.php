<?php
// scratch/replace_portal.php
$file = __DIR__ . '/../subcontractor_portal.php';
if (!file_exists($file)) {
    die("File not found: " . $file . "\n");
}
$content = file_get_contents($file);

// 1. クエリの書き換え
$pattern_q1 = '/SELECT o\.\*, p\.project_name\s+FROM subcontractor_orders o\s+JOIN projects p ON o\.project_id = p\.id\s+WHERE o\.subcontractor_id = :user_id/is';
$replacement_q1 = "SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, p.schedule_actuals, p.req_permit, p.req_wall, p.req_skin, p.req_sky, p.req_opt_kisohari 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :user_id";

$pattern_q2 = '/SELECT o\.\*, p\.project_name\s+FROM subcontractor_orders o\s+JOIN projects p ON o\.project_id = p\.id\s+WHERE o\.subcontractor_id = :sub_id_1 OR o\.subcontractor_id IN \(SELECT id FROM users WHERE parent_id = :sub_id_2\)/is';
$replacement_q2 = "SELECT o.*, p.project_name, p.status as project_status, p.primary_due_date, p.schedule_actuals, p.req_permit, p.req_wall, p.req_skin, p.req_sky, p.req_opt_kisohari 
        FROM subcontractor_orders o 
        JOIN projects p ON o.project_id = p.id 
        WHERE o.subcontractor_id = :sub_id_1 OR o.subcontractor_id IN (SELECT id FROM users WHERE parent_id = :sub_id_2)";

$content = preg_replace($pattern_q1, $replacement_q1, $content, 1);
$content = preg_replace($pattern_q2, $replacement_q2, $content, 1);

// 2. グルーピング処理の書き換え
$pattern_group = '/\$project_tasks = \[\];\s*foreach \(\$tasks as \$t\) \{\s*\$pid = \$t\[\'project_id\'\];\s*if \(!isset\(\$project_tasks\[\$pid\]\)\) \{\s*\$project_tasks\[\$pid\] = \[\s*\'project_name\' => \$t\[\'project_name\'\],\s*\'project_id\' => \$pid,\s*\'items\' => \[\]\s*\];\s*\}\s*\$project_tasks\[\$pid\]\[\'items\'\]\[\] = \$t;\s*\}/is';
$replacement_group = "\$project_tasks = [];
foreach (\$tasks as \$t) {
    \$pid = \$t['project_id'];
    if (!isset(\$project_tasks[\$pid])) {
        \$project_tasks[\$pid] = [
            'project_name' => \$t['project_name'],
            'project_id' => \$pid,
            'project_status' => \$t['project_status'],
            'primary_due_date' => \$t['primary_due_date'],
            'schedule_actuals' => \$t['schedule_actuals'],
            'req_permit' => \$t['req_permit'],
            'req_wall' => \$t['req_wall'],
            'req_skin' => \$t['req_skin'],
            'req_sky' => \$t['req_sky'],
            'req_opt_kisohari' => \$t['req_opt_kisohari'],
            'items' => []
        ];
    }
    \$project_tasks[\$pid]['items'][] = \$t;
}";

$content = preg_replace($pattern_group, $replacement_group, $content, 1);

// 3. CSSスタイルの追加
$pattern_style = '/<\/style>/i';
$replacement_style = "        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 15px; margin-top: 15px; }
        .card { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; border-left: 5px solid #ccc; min-height: 180px; }
        .card h3 { margin: 0 0 10px 0; font-size: 15px; color: #1e3a8a; }
    </style>";
$content = preg_replace($pattern_style, $replacement_style, $content, 1);

// 4. HTML案件一覧のカード表示化
$pattern_html = '/<\?php if \(count\(\$project_tasks\) > 0\):\s*\?>\s*<\?php foreach \(\$project_tasks as \$pid => \$proj\):\s*\?>.*?<\?php endforeach;\s*\?>\s*<\?php else:\s*\?>.*?<\?php endif;\s*\?>/is';

$replacement_html = '<?php if (count($project_tasks) > 0): ?>
                <div class="grid">
                    <?php foreach ($project_tasks as $pid => $proj): 
                        $project_dummy = [
                            \'status\' => $proj[\'project_status\'],
                            \'primary_due_date\' => $proj[\'primary_due_date\'],
                            \'schedule_actuals\' => $proj[\'schedule_actuals\'],
                            \'req_permit\' => $proj[\'req_permit\'],
                            \'req_wall\' => $proj[\'req_wall\'],
                            \'req_skin\' => $proj[\'req_skin\'],
                            \'req_sky\' => $proj[\'req_sky\'],
                            \'req_opt_kisohari\' => $proj[\'req_opt_kisohari\']
                        ];
                        $ball = \App\Helpers\StatusHelper::getBallStatus($project_dummy, $pdo, \'subcontractor\');
                    ?>
                        <div class="card" style="border-left: 5px solid <?= $ball[\'color\'] ?>;">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:5px;">
                                    <span class="badge" style="background-color: <?= $ball[\'color\'] ?>; color: white; font-weight: bold; margin:0;"><?= htmlspecialchars($ball[\'label\'], ENT_QUOTES) ?></span>
                                </div>
                                <h3 style="font-size:15px; color:#1e3a8a; margin:0 0 12px 0;">🏠 <?= htmlspecialchars($proj[\'project_name\']) ?></h3>
                                
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <?php foreach ($proj[\'items\'] as $t): ?>
                                        <?php if ($t[\'status\'] === \'cancelled\'): ?>
                                            <div style="font-size:11px; color:#94a3b8; text-decoration:line-through;">
                                                ❌ <?= htmlspecialchars($t[\'task_title\']) ?> (キャンセル済)
                                            </div>
                                        <?php else: ?>
                                            <div style="font-size:12px; background:#f8fafc; border:1px solid #e2e8f0; padding:8px; border-radius:4px; display:flex; flex-direction:column; gap:4px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <span style="font-weight:bold; color:#334155;"><?= htmlspecialchars($t[\'task_title\']) ?></span>
                                                    <?php 
                                                        if ($t[\'status\'] === \'requested\') echo \'<span class="badge" style="background:#f59e0b; padding:2px 5px; font-size:10px; margin:0;">承諾待ち</span>\';
                                                        elseif ($t[\'status\'] === \'accepted\' || $t[\'status\'] === \'in_progress\') echo \'<span class="badge" style="background:#3b82f6; padding:2px 5px; font-size:10px; margin:0;">作業中</span>\';
                                                        elseif ($t[\'status\'] === \'delivered\') echo \'<span class="badge" style="background:#fd7e14; padding:2px 5px; font-size:10px; margin:0;">一次納品</span>\';
                                                        elseif ($t[\'status\'] === \'cb_requested\') echo \'<span class="badge" style="background:#ef4444; padding:2px 5px; font-size:10px; margin:0;">修正依頼</span>\';
                                                        elseif ($t[\'status\'] === \'completed\') echo \'<span class="badge" style="background:#059669; padding:2px 5px; font-size:10px; margin:0;">完了</span>\';
                                                        elseif ($t[\'status\'] === \'rejected\') echo \'<span class="badge" style="background:#ef4444; padding:2px 5px; font-size:10px; margin:0;">辞退済</span>\';
                                                    ?>
                                                </div>
                                                <div style="display:flex; justify-content:space-between; font-size:11px; color:#64748b;">
                                                    <span>発注額: <?= number_format($t[\'order_amount\']) ?>円</span>
                                                    <span>希望納期: <?= !empty($t[\'due_date\']) ? date(\'m/d\', strtotime($t[\'due_date\'])) : \'-\' ?></span>
                                                </div>
                                                
                                                <?php if ($t[\'status\'] === \'requested\' && !$is_admin): ?>
                                                    <div style="margin-top:5px; display:flex; gap:5px; align-items:center;">
                                                        <form method="POST" action="project_subcontractor.php" style="background:#fff3cd; padding:5px; border-radius:4px; border:1px solid #ffeeba; display:flex; gap:5px; align-items:center; margin:0; flex-wrap:wrap; width:100%; justify-content:space-between;">
                                                            <input type="hidden" name="order_id" value="<?= $t[\'id\'] ?>">
                                                            <span style="font-size:10px; font-weight:bold; color:#856404;">予定日:</span>
                                                            <input type="date" name="expected_delivery_date" required style="padding:2px; font-size:10px;">
                                                            <button type="submit" style="background:#28a745; color:white; border:none; padding:3px 6px; border-radius:3px; font-size:10px; cursor:pointer; font-weight:bold;">承諾</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div style="margin-top:12px;">
                                <a href="project_subcontractor.php?id=<?= $proj[\'project_id\'] ?>" class="btn" style="background-color: <?= $ball[\'color\'] ?>; color:#fff; text-decoration:none; font-size:12px; font-weight:bold; display:block; text-align:center; padding:8px; border-radius:4px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">詳細・DL・納品 ➔</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:#777; font-size:14px;">現在担当している案件はありません。</p>
            <?php endif; ?>';

$content = preg_replace($pattern_html, $replacement_html, $content, 1);

file_put_contents($file, $content);
echo "Portal layout replacement completed!\n";
