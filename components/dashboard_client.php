<?php
// components/dashboard_client.php
// 依頼主用ダッシュボード
?>
<div class="container" style="flex-direction: column;">
    <div style="display:flex; gap:20px; width:100%;">
        
        <!-- 左パネル：案件情報と金銭情報 -->
        <div class="column col-left" style="flex: 1;">
            <h2 class="section-title" style="background:#4a5568;">📋 案件情報とご請求状況</h2>
            
            <div class="box" style="position:relative;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #ccc; padding-bottom:5px; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:14px;">基本情報</h3>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <?php
                        $company_id = $_SESSION['parent_id'] ?: $_SESSION['user_id'];
                        $stmtStaff = $pdo->prepare("
                            SELECT id, contact_name, last_active_at, email_notification_enabled 
                            FROM users 
                            WHERE id = :cid1 OR parent_id = :cid2 
                            ORDER BY id ASC
                        ");
                        $stmtStaff->execute([
                            'cid1' => $company_id,
                            'cid2' => $company_id
                        ]);
                        $staff_members = $stmtStaff->fetchAll();
                        ?>
                        <div style="display:flex; gap:6px; align-items:center; margin-right:10px;">
                            <?php foreach ($staff_members as $member): 
                                $is_me = ($member['id'] == $_SESSION['user_id']);
                                $is_online = (!empty($member['last_active_at']) && (time() - strtotime($member['last_active_at'])) < 300);
                                $indicator_color = $is_online ? '#10b981' : '#94a3b8';
                                $initial = mb_substr($member['contact_name'], 0, 1);
                                $notif_status = $member['email_notification_enabled'] ? '通知ON' : '通知OFF';
                            ?>
                                <div class="staff-avatar-wrapper" style="position:relative; cursor:<?= $is_me ? 'pointer' : 'default' ?>;" 
                                     title="<?= htmlspecialchars($member['contact_name'], ENT_QUOTES) ?> (<?= $notif_status ?>)"
                                     <?= $is_me ? 'onclick="toggleNotificationPopup(event)"' : '' ?>>
                                    <div style="width:24px; height:24px; border-radius:50%; background:#2563eb; color:white; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:bold; border:1px solid <?= $is_me ? '#059669' : '#fff' ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.15);">
                                        <?= htmlspecialchars($initial, ENT_QUOTES) ?>
                                    </div>
                                    <div style="position:absolute; bottom:-1px; right:-1px; width:7px; height:7px; border-radius:50%; background:<?= $indicator_color ?>; border:1.5px solid #fff; <?= $is_online ? 'box-shadow: 0 0 6px #10b981;' : '' ?>"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button onclick="document.getElementById('editInfoModal').classList.add('active')" style="background:#e2e8f0; border:none; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; color:#475569; font-weight:bold;">編集</button>
                    </div>
                </div>

                <!-- 通知設定ポップアップ -->
                <div id="myAccountPopup" style="display:none; position:absolute; background:white; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); border:1px solid #cbd5e1; padding:12px; z-index:1000; width:180px; font-size:11px; top:35px; right:60px;">
                    <div style="font-weight:bold; border-bottom:1px solid #edf2f7; padding-bottom:5px; margin-bottom:8px; color:#1e293b; display:flex; justify-content:space-between; align-items:center;">
                        <span>⚙️ 通知設定</span>
                        <span style="cursor:pointer; color:#94a3b8; font-size:12px;" onclick="closeMyAccountPopup()">✕</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="font-weight:600; color:#475569;">メール通知の受け取り</span>
                        <label class="switch" style="position:relative; display:inline-block; width:34px; height:18px;">
                            <input type="checkbox" id="user_notification_toggle" style="opacity:0; width:0; height:0;" 
                                   <?= ($_SESSION['email_notification_enabled'] ?? 1) ? 'checked' : '' ?>
                                   onchange="updateNotificationSetting(this.checked)">
                            <span class="slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#cbd5e1; transition:.3s; border-radius:18px;"></span>
                        </label>
                    </div>
                    <div style="font-size:9px; color:#94a3b8; line-height:1.3;">
                        ※新着メッセージや設計成果物アップロード時の通知メールを制御します。
                    </div>
                </div>

                <style>
                    .slider:before {
                        position: absolute; content: ""; height: 12px; width: 12px; left: 3px; bottom: 3px;
                        background-color: white; transition: .3s; border-radius: 50%;
                    }
                    input:checked + .slider { background-color: #10b981; }
                    input:checked + .slider:before { transform: translateX(16px); }
                </style>

                <script>
                function toggleNotificationPopup(event) {
                    event.stopPropagation();
                    const popup = document.getElementById('myAccountPopup');
                    popup.style.display = (popup.style.display === 'none' || popup.style.display === '') ? 'block' : 'none';
                }

                function closeMyAccountPopup() {
                    document.getElementById('myAccountPopup').style.display = 'none';
                }

                document.addEventListener('click', function(e) {
                    const popup = document.getElementById('myAccountPopup');
                    if (popup && !popup.contains(e.target) && !e.target.closest('.staff-avatar-wrapper')) {
                        popup.style.display = 'none';
                    }
                });

                function updateNotificationSetting(checked) {
                    fetch('api_update_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ enabled: checked ? 1 : 0 })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            console.log("Notification setting updated:", data.enabled);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert("設定の更新に失敗しました。");
                    });
                }
                </script>
                <div style="font-size:13px; line-height:1.6;">
                    <strong>案件名:</strong> <?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?><br>
                    <strong>担当者:</strong> <?= htmlspecialchars($project_info['client_name'], ENT_QUOTES) ?> 様<br>
                    <?php if (!empty($project_info['mobile_number'])): ?>
                    <strong>📱 携帯番号:</strong> <a href="tel:<?= htmlspecialchars($project_info['mobile_number'], ENT_QUOTES) ?>" style="color:#0056b3; font-weight:bold;"><?= htmlspecialchars($project_info['mobile_number'], ENT_QUOTES) ?></a><br>
                    <?php else: ?>
                    <strong>📱 携帯番号:</strong> <span style="color:#e53e3e; font-size:11px;">未登録（「編集」ボタンからご登録ください）</span><br>
                    <?php endif; ?>
                    <strong>地盤調査:</strong> <?= htmlspecialchars($project_info['soil_status'] ?? '未定', ENT_QUOTES) ?><br>
                    <?php
                        $status_labels = [
                            'quote_req'      => '見積依頼',
                            'contracted'     => '受注済',
                            'primary_prep'   => '一次回答準備中',
                            'structural_dwg' => '申請図書作成中',
                            'submission'     => '審査・待機',
                            'submitting'     => '審査・待機',
                            'correction'     => '補正対応中',
                            'completed'      => '完了'
                        ];
                        $status_ja = getDynamicStatusLabel($project_info, $pdo);
                        $status_bg = '#007bff';
                        if ($status_ja === '審査・待機') {
                            $status_bg = '#64748b';
                        }
                    ?>
                    <strong>ステータス:</strong> <span class="badge" style="background:<?= $status_bg ?>;"><?= htmlspecialchars($status_ja, ENT_QUOTES) ?></span>
                    <?php
                    // 中間金支払いの判定
                    $actuals_column = 'schedule_actuals';
                    if ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $actuals_column = 'schedule_actuals';
                    } elseif ($project_info['req_wall'] == 1) {
                        $actuals_column = 'schedule_actuals_wall';
                    } elseif ($project_info['req_skin'] == 1) {
                        $actuals_column = 'schedule_actuals_skin';
                    } elseif ($project_info['req_sky'] == 1) {
                        $actuals_column = 'schedule_actuals_sky';
                    }
                    $actuals_data = json_decode($project_info[$actuals_column] ?? '{}', true) ?: [];
                    
                    $scheduleService = new \App\Services\ScheduleService($pdo);
                    $base_days = $scheduleService->getScheduleBaseDays($project_info);
                    $steps_list = [];
                    if ($project_info['req_permit'] == 1 || $project_info['req_opt_kisohari'] == 1) {
                        $steps_list = $scheduleService->getScheduleSteps($base_days, true);
                    } elseif ($project_info['req_wall'] == 1) {
                        $steps_list = $scheduleService->getScheduleStepsWall($base_days);
                    } elseif ($project_info['req_skin'] == 1) {
                        $steps_list = $scheduleService->getScheduleStepsSkin($base_days);
                    } elseif ($project_info['req_sky'] == 1) {
                        $steps_list = $scheduleService->getScheduleStepsSky($base_days);
                    }
                    
                    $mid_pay_idx = -1;
                    foreach ($steps_list as $idx => $step) {
                        if ($step['name'] === '中間金（50％）のご入金') {
                            $mid_pay_idx = $idx;
                            break;
                        }
                    }
                    
                    $is_mid_paid = ($mid_pay_idx !== -1 && !empty($actuals_data[$mid_pay_idx]));
                    
                    // 中間金支払いボタンの表示（ステータスが進行中かつ見積中・完了以外）
                    if (in_array($project_info['status'], ['contracted', 'primary_prep', 'structural_dwg', 'submission', 'submitting', 'correction'])):
                    ?>
                        <div style="margin-top: 12px; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <?php if ($is_mid_paid): ?>
                                <div style="color: #059669; font-weight: bold; font-size: 11px; display: flex; align-items: center; gap: 4px; justify-content: center;">
                                    ✅ 中間金（50％）ご入金報告済み<br>
                                    <span style="font-weight: normal; color: #64748b; font-size: 10px;">(報告日: <?= htmlspecialchars($actuals_data[$mid_pay_idx], ENT_QUOTES) ?>)</span>
                                </div>
                            <?php else: ?>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('中間金（50％）のご入金を報告し、経理担当者へ通知します。よろしいですか？');">
                                    <input type="hidden" name="action" value="pay_intermediate">
                                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                    <button type="submit" style="width:100%; background:#2563eb; color:white; border:none; padding:8px 10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:11px; display:flex; align-items:center; justify-content:center; gap:5px; box-shadow: 0 2px 4px rgba(37,99,235,0.2);">
                                        💵 中間金（50％）を入金しました
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($project_info['status'] === 'submission'): ?>
                        <?php 
                        $formal_amt = (int)($project_info['formal_est_amount'] ?? 0);
                        $add_est_amt = (int)($project_info['add_est_amount'] ?? 0);
                        $total_billed = $formal_amt + $add_est_amt;
                        $deposit_50 = (int)($project_info['deposit_amount_50'] ?? 0);
                        $remaining_balance = $total_billed - $deposit_50;
                        $is_zero_balance = ($remaining_balance <= 0);
                        ?>
                        <div style="margin-top: 15px; border-top: 1px dashed #cbd5e1; padding-top: 12px;">
                            <?php if (!$is_zero_balance): ?>
                                <div style="font-size: 10px; color: #dc2626; font-weight: bold; margin-bottom: 5px; line-height: 1.4; text-align: left;">
                                    ※確認申請の審査合格が確認できましたら、残金をお振込みいただき、本ボタンを押して完了登録を行ってください。
                                </div>
                            <?php endif; ?>
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('<?= $is_zero_balance ? '確認機関の審査が完了（合格）したことを登録して、設計業務を完了にします。よろしいですか？' : '確認機関の審査が完了（合格）し、残金の振込みが完了したことを登録して、設計業務を完了にします。よろしいですか？' ?>');">
                                <input type="hidden" name="action" value="complete_review">
                                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                <button type="submit" style="width:100%; background:#10b981; color:white; border:none; padding:8px 10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:11px; display:flex; align-items:center; justify-content:center; gap:4px; box-shadow: 0 2px 4px rgba(16,185,129,0.3);">
                                    <?= $is_zero_balance ? '💮 審査完了にする（審査合格）' : '💮 残金お振込み ＆ 審査完了にする（審査合格）' ?>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>



            <div class="box">
                <h3 style="margin-top:0; font-size:14px; border-bottom:1px solid #ccc; padding-bottom:5px;">📋 ご依頼内容</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <?php if ($project_info['req_permit'] ?? 0): ?><div>・許容応力度計算</div><?php endif; ?>
                    <?php if ($project_info['req_wall'] ?? 0): ?><div>・壁量計算書作成</div><?php endif; ?>
                    <?php if ($project_info['req_skin'] ?? 0): ?><div>・外皮計算書作成</div><?php endif; ?>
                    <?php if ($project_info['req_sky'] ?? 0): ?><div>・天空率計算書作成</div><?php endif; ?>
                    <?php if ($project_info['req_opt_kisohari'] ?? 0): ?><div>・【オプション】基礎梁計算</div><?php endif; ?>
                    <?php 
                        if (!($project_info['req_permit'] ?? 0) && !($project_info['req_wall'] ?? 0) && !($project_info['req_skin'] ?? 0) && !($project_info['req_sky'] ?? 0) && !($project_info['req_opt_kisohari'] ?? 0)) {
                            echo "<div>・構造計算等の基本業務</div>";
                        }
                    ?>
                </div>
            </div>

            <div class="box" style="margin-top:15px; background:#e8f5e9; border-color:#c8e6c9;">
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">📝 見積書・請求書</h3>
                
                <?php if (!empty($all_estimates)): ?>
                    <div style="margin-bottom:15px; padding:10px; background:#fff; border:2px solid #28a745; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#155724; margin-bottom:5px;">最新の御見積書</div>
                        <a href="estimate_print.php?id=<?= $project_id ?>&est_id=<?= $all_estimates[0]['id'] ?>" target="_blank" style="display:inline-block; padding:8px 15px; background:#28a745; color:white; font-weight:bold; border-radius:4px; text-decoration:none;">
                            📄 見積書を表示・ダウンロード
                        </a>
                    </div>
                    
                    <?php if (count($all_estimates) > 1): ?>
                    <details style="font-size:12px; margin-bottom:10px;">
                        <summary style="cursor:pointer; color:#0056b3;">過去の見積履歴を表示</summary>
                        <ul style="list-style:none; padding-left:10px; margin-top:5px; line-height:1.6;">
                        <?php foreach(array_slice($all_estimates, 1) as $est): ?>
                            <li>
                                <a href="estimate_print.php?id=<?= $project_id ?>&est_id=<?= $est['id'] ?>" target="_blank" style="text-decoration:none; color:#555;">
                                    📄 <?= htmlspecialchars($est['created_at']) ?> 提示分 (税込: ¥<?= number_format($est['total_price'] * 1.1) ?>)
                                </a>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="font-size:12px; color:#666; padding:10px; text-align:center; background:#fff; border-radius:4px;">見積書はまだ発行されていません。</div>
                <?php endif; ?>
                
                <hr style="border:0; border-top:1px dashed #c8e6c9; margin:15px 0;">
                
                <h3 style="margin-top:0; font-size:14px; color:#2e7d32; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">📄 請求書</h3>
                <?php 
                $has_invoice = false;
                if (!empty($files_by_cat['inv_primary'])): 
                    $inv = $files_by_cat['inv_primary'][0];
                    $inv_url = (strpos($inv['drive_file_id'], 'uploads/') !== 0 && !empty($inv['drive_file_id'])) 
                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($inv['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                        : htmlspecialchars($inv['drive_file_id'], ENT_QUOTES);
                    $has_invoice = true;
                    $is_full_invoice = (($project_info['primary_invoice_rate'] ?? 0.5) >= 1.0);
                ?>
                    <div style="margin-bottom:10px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#1e40af; margin-bottom:5px;">
                            <?= $is_full_invoice ? 'ご請求書 (100%全額分)' : '一次請求書 (着手金50%分)' ?>
                        </div>
                        <a href="<?= $inv_url ?>" target="_blank" style="display:inline-block; padding:6px 12px; background:#2563eb; color:white; font-size:12px; font-weight:bold; border-radius:4px; text-decoration:none;">
                            📄 <?= $is_full_invoice ? '請求書を表示' : '一次請求書を表示' ?>
                        </a>
                        
                        <?php if (!$is_full_invoice): ?>
                            <form method="POST" style="margin-top: 8px; border-top: 1px dashed #cbd5e1; padding-top: 8px;" onsubmit="return confirm('請求書を【全額一括請求 (100%分)】に変更して再発行しますか？\n（管理者の代わりに100%請求書が自動再発行され、チャットへも自動通知されます）');">
                                <input type="hidden" name="action" value="request_full_invoice">
                                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                                <button type="submit" style="width:100%; background:#8b5cf6; color:white; border:none; padding:6px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:11px; display:flex; align-items:center; justify-content:center; gap:4px;">
                                    🔄 100%全額請求書へ差し替える
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php 
                if (!empty($files_by_cat['inv_final'])): 
                    $inv_f = $files_by_cat['inv_final'][0];
                    $inv_f_url = (strpos($inv_f['drive_file_id'], 'uploads/') !== 0 && !empty($inv_f['drive_file_id'])) 
                        ? 'https://drive.google.com/file/d/' . htmlspecialchars($inv_f['drive_file_id'], ENT_QUOTES) . '/view?usp=drivesdk'
                        : htmlspecialchars($inv_f['drive_file_id'], ENT_QUOTES);
                    $has_invoice = true;
                ?>
                    <div style="margin-bottom:10px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; text-align:center;">
                        <div style="font-weight:bold; color:#b91c1c; margin-bottom:5px;">最終ご請求書 (残金精算分)</div>
                        <a href="<?= $inv_f_url ?>" target="_blank" style="display:inline-block; padding:6px 12px; background:#dc3545; color:white; font-size:12px; font-weight:bold; border-radius:4px; text-decoration:none;">
                            📄 最終請求書を表示
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!$has_invoice): ?>
                    <div style="font-size:12px; color:#666; padding:10px; text-align:center; background:#fff; border-radius:4px;">請求書はまだ発行されていません。</div>
                <?php endif; ?>
                
                <hr style="border:0; border-top:1px dashed #c8e6c9; margin:15px 0;">
                
                <h3 style="margin-top:0; font-size:14px; color:#0056b3; border-bottom:1px solid #c8e6c9; padding-bottom:5px;">正式なご依頼（設計依頼データ送付）</h3>
                
                <?php if ($project_info['status'] === 'quote_req'): ?>
                    <p style="font-size:11px; color:#666; margin-bottom:10px;">見積もり内容をご確認いただき、正式に発注される場合は、こちらから必要な設計データを送付してください。</p>
                    <button onclick="document.getElementById('orderModal').classList.add('active')" style="width:100%; background:#0056b3; color:white; border:none; padding:10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:14px;">
                        📤 設計依頼データの送付
                    </button>
                <?php else: ?>
                    <div style="font-size:12px; color:#155724; background:#d4edda; padding:10px; border-radius:4px; text-align:center; border:1px solid #c3e6cb;">
                        <strong>✅ 正式発注済み（必要図書提出済）</strong><br>
                        <span style="font-size:11px;">現在、担当者が図書を確認し、設計作業を進めています。</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="box" style="margin-top:15px; background:#fff3cd; border-color:#ffeeba;">
                <h3 style="margin-top:0; font-size:14px; color:#856404; border-bottom:1px solid #ffeeba; padding-bottom:5px;">💰 ご請求・お支払い状況</h3>
                <div style="font-size:13px; line-height:1.8;">
                    <?php
                        $initial = $project_info['initial_est_amount'] ?? 0;
                        $initial_date = $project_info['initial_est_date'] ?? '';
                        $formal = $project_info['formal_est_amount'] ?? 0;
                        $formal_date = $project_info['formal_est_date'] ?? '';
                        
                        // 複数追加見積のパース
                        $add_estimates = json_decode($project_info['additional_estimates'] ?? '[]', true) ?: [];
                        
                        $dep_50 = $project_info['deposit_amount_50'] ?? 0;
                        $dep_date_50 = $project_info['deposit_date_50'] ?? '';
                        $dep_rem = $project_info['deposit_amount_rem'] ?? 0;
                        $dep_date_rem = $project_info['deposit_date_rem'] ?? '';
                        $additional_deposits = json_decode($project_info['additional_deposits'] ?? '[]', true) ?: [];

                        // 合計追加費用
                        $total_add = 0;
                        foreach ($add_estimates as $ae) {
                            $total_add += intval($ae['amount']);
                        }

                        // 追加入金合計
                        $total_add_dep = 0;
                        foreach ($additional_deposits as $ad) {
                            $total_add_dep += intval($ad['amount']);
                        }

                        $total_req = $formal + $total_add;
                        $total_deposit = $dep_50 + $dep_rem + $total_add_dep;
                        $balance = $total_req - $total_deposit;

                        // 一次請求額の計算 (消費税加算前税抜の50% + 消費税10%)
                        $primary_invoice_amount = 0;
                        if ($formal > 0) {
                            $base_formal = round($formal / 1.1);
                            $subtotal_primary = round($base_formal * 0.5);
                            $tax_primary = round($subtotal_primary * 0.1);
                            $primary_invoice_amount = $subtotal_primary + $tax_primary;
                        }
                    ?>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>初期お見積額 (<?= $initial_date ? htmlspecialchars($initial_date) : '-' ?>):</span> <strong><?= number_format($initial) ?> 円</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px;">
                        <span>本見積額 (<?= $formal_date ? htmlspecialchars($formal_date) : '-' ?>):</span> <strong><?= number_format($formal) ?> 円</strong>
                    </div>
                    
                    <!-- 追加見積一覧の表示 -->
                    <div style="margin-left: 10px; font-size:12px; color:#c0392b;">
                        <?php foreach ($add_estimates as $idx => $ae): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・追加見積 #<?= $idx+1 ?> (<?= htmlspecialchars($ae['date'] ?: '-') ?>) <?= !empty($ae['note']) ? '['.htmlspecialchars($ae['note']).']' : '' ?>:</span>
                                <strong>+ <?= number_format($ae['amount']) ?> 円</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px dashed #ccc; padding-top:5px;">
                        <span>合計ご請求額 (本見積＋追加):</span> <strong><?= number_format($total_req) ?> 円</strong>
                    </div>
                    <?php if ($formal > 0): ?>
                    <div style="display:flex; justify-content:space-between; color:#4a5568; margin-bottom: 2px;">
                        <span>└ 一次請求予定額 (50%):</span> <strong><?= number_format($primary_invoice_amount) ?> 円</strong>
                    </div>
                    <?php endif; ?>
                    
                    <div style="display:flex; justify-content:space-between; color:#28a745; margin-top: 5px;">
                        <span>入金済合計 (50% + 残金 + 追加):</span> <strong>- <?= number_format($total_deposit) ?> 円</strong>
                    </div>
                    
                    <!-- 各入金の明細表示 -->
                    <div style="margin-left: 10px; font-size:11px; color:#555; line-height: 1.4;">
                        <?php if ($dep_50 > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・着手金 (50%) 入金 (<?= htmlspecialchars($dep_date_50 ?: '-') ?>):</span>
                                <span><?= number_format($dep_50) ?> 円</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($dep_rem > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・残金入金 (<?= htmlspecialchars($dep_date_rem ?: '-') ?>):</span>
                                <span><?= number_format($dep_rem) ?> 円</span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($additional_deposits as $idx => $ad): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span>・追加入金 (<?= htmlspecialchars($ad['date'] ?: '-') ?><?php if(!empty($ad['note'])) echo ' - ' . htmlspecialchars($ad['note']); ?>):</span>
                                <span><?= number_format($ad['amount']) ?> 円</span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top:5px; border-top:1px solid #ccc; padding-top:5px; font-size:15px; font-weight:bold; color:#d32f2f;">
                        <span>最終ご請求額 (残金精算額):</span> <span><?= number_format($balance) ?> 円</span>
                    </div>
                </div>
            </div>

            <?php require __DIR__ . '/col_estimate_files.php'; ?>
        </div>
        
        <!-- ===== 発注データアップロードモーダル ===== -->
        <div class="modal-overlay" id="orderModal">
            <div class="modal-box" style="max-width:600px;">
                <div class="modal-title">📤 設計依頼データの送付（正式発注）</div>

                <!-- 免責事項 -->
                <div style="background:#fff3cd; border:2px solid #ffc107; border-radius:8px; padding:12px; margin-bottom:15px;">
                    <div style="font-size:14px; font-weight:bold; color:#856404; margin-bottom:6px;">⚠️ ご依頼前に必ずお読みください</div>
                    <div style="font-size:13px; color:#664d03; line-height:1.7;">
                        当サービスは「木造住宅設計サポート業務」として、構造計算書・壁量計算書等の作成をお手伝いするものです。<br>
                        <strong style="color:#b91c1c;">弊社（担当者）は設計者にはなりません。</strong><br>
                        意匠設計・確認申請の代理人業務・設計者欄への記名・押印等は一切行いません。<br>
                        設計者（建築士）の責任のもと、弊社の成果物をご活用ください。
                    </div>
                </div>

                <!-- 承諾チェック -->
                <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:10px; margin-bottom:15px;">
                    <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; font-size:13px; color:#7f1d1d; font-weight:bold;">
                        <input type="checkbox" id="agreeDisclaimer" onchange="toggleOrderSubmit()" style="margin-top:3px; width:16px; height:16px; accent-color:#dc2626; flex-shrink:0;">
                        <span>上記の内容を理解しました。弊社が設計者にならないことに同意した上で、設計依頼データを送付します。</span>
                    </label>
                </div>

                <div style="font-size:13px; margin-bottom:15px; color:#555;">承諾後、以下の必須データをアップロードして、正式に発注してください。</div>
                <form method="POST" enctype="multipart/form-data" id="orderForm">
                    <input type="hidden" name="action" value="request_design_start">
                    
                    <?php
                        $is_common = ($project_info['req_permit'] || $project_info['req_wall'] || (!($project_info['req_permit']||$project_info['req_wall']||$project_info['req_skin']||$project_info['req_sky'])));
                        $is_sky = $project_info['req_sky'];
                        $is_skin = $project_info['req_skin'];
                    ?>
                    
                    <?php include 'upload_slots.php'; ?>
                    
                    <div style="margin-bottom:15px; background:#fef2f2; border:1px solid #fecaca; padding:10px; border-radius:6px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:8px; color:#b91c1c;">【重要】見積時から図面の変更はありますか？</label>
                        <div style="display:flex; gap:15px; margin-bottom:10px;">
                            <label style="font-size:13px; cursor:pointer;"><input type="radio" name="drawing_changed" value="no" required onchange="document.getElementById('drawing_change_notes_area').style.display='none'; document.getElementById('drawing_change_notes').removeAttribute('required');"> 変更なし</label>
                            <label style="font-size:13px; cursor:pointer;"><input type="radio" name="drawing_changed" value="yes" required onchange="document.getElementById('drawing_change_notes_area').style.display='block'; document.getElementById('drawing_change_notes').setAttribute('required', 'required');"> 変更あり</label>
                        </div>
                        <div id="drawing_change_notes_area" style="display:none;">
                            <label style="display:block; font-size:11px; margin-bottom:5px; color:#555;">変更箇所を簡単にお知らせください</label>
                            <textarea id="drawing_change_notes" name="drawing_change_notes" rows="2" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 2階の窓の位置を変更、面積が1坪増えました 等"></textarea>
                        </div>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">その他補足事項・メッセージ</label>
                        <textarea name="client_notes_extra" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="よろしくお願いいたします。"></textarea>
                    </div>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('orderModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" id="orderSubmitBtn" disabled style="padding:8px 20px; background:#9ca3af; color:white; border:none; border-radius:6px; cursor:not-allowed; font-weight:bold;" onclick="if(this.disabled) return false; this.innerHTML='送信中...';">送信して正式発注（承諾が必要です）</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        function toggleOrderSubmit() {
            const cb = document.getElementById('agreeDisclaimer');
            const btn = document.getElementById('orderSubmitBtn');
            if (cb && btn) {
                if (cb.checked) {
                    btn.disabled = false;
                    btn.style.background = '#0056b3';
                    btn.style.cursor = 'pointer';
                    btn.textContent = '送信して正式発注';
                } else {
                    btn.disabled = true;
                    btn.style.background = '#9ca3af';
                    btn.style.cursor = 'not-allowed';
                    btn.textContent = '送信して正式発注（承諾が必要です）';
                }
            }
        }
        </script>

        <!-- カラム2：成果物一覧 ＋ スケジュール -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:300px;">
            <?php require __DIR__ . '/col_center_deliverables.php'; ?>
            <?php require __DIR__ . '/col_center_post_uploads.php'; ?>

            <!-- スケジュールボックス（旧左カラムから移動） -->
            <?php
            $base_days = getScheduleBaseDays($project_info);
            $primary_due_date = $project_info['primary_due_date'] ?? null;

            $schedulesToRender = [];

            $is_koyou_or_kisohari = (($project_info['req_permit'] ?? 0) == 1 || ($project_info['req_opt_kisohari'] ?? 0) == 1);
            if ($is_koyou_or_kisohari) {
                $schedulesToRender[] = [
                    'title' => '許容応力度・基礎横架材計算',
                    'type' => 'permit',
                    'steps' => getScheduleSteps($base_days, true),
                    'actuals_col' => 'schedule_actuals'
                ];
            }
            if (($project_info['req_wall'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '壁量計算',
                    'type' => 'wall',
                    'steps' => getScheduleStepsWall($base_days),
                    'actuals_col' => 'schedule_actuals_wall'
                ];
            }
            if (($project_info['req_skin'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '外皮計算',
                    'type' => 'skin',
                    'steps' => getScheduleStepsSkin($base_days),
                    'actuals_col' => 'schedule_actuals_skin'
                ];
            }
            if (($project_info['req_sky'] ?? 0) == 1) {
                $schedulesToRender[] = [
                    'title' => '天空率',
                    'type' => 'sky',
                    'steps' => getScheduleStepsSky($base_days),
                    'actuals_col' => 'schedule_actuals_sky'
                ];
            }

            if (empty($schedulesToRender)) {
                $schedulesToRender[] = [
                    'title' => '構造計算・基本スケジュール',
                    'type' => 'permit',
                    'steps' => getScheduleSteps($base_days, false),
                    'actuals_col' => 'schedule_actuals'
                ];
            }

            foreach ($schedulesToRender as $scheduleItem):
                $schedule_actuals = json_decode($project_info[$scheduleItem['actuals_col']] ?? '{}', true) ?: [];
                $override_col = str_replace('actuals', 'overrides', $scheduleItem['actuals_col']);
                $schedule_overrides = json_decode($project_info[$override_col] ?? '{}', true) ?: [];
                $wishes_col = str_replace('actuals', 'wishes', $scheduleItem['actuals_col']);
                $schedule_wishes = json_decode($project_info[$wishes_col] ?? '{}', true) ?: [];
            ?>
            <div class="box" style="background:#f0f8ff; border-color:#cce5ff;">
                <h3 style="margin-top:0; font-size:14px; color:#004085; border-bottom:1px solid #cce5ff; padding-bottom:5px;">📅 <?= htmlspecialchars($scheduleItem['title']) ?> スケジュール</h3>
                <div style="font-size:13px; line-height:1.6;">
                    <?php
                    if (empty($primary_due_date)) {
                        echo '<div style="color:#e53e3e; font-size:12px; margin-bottom:10px; background:#fef2f2; border:1px solid #fecaca; padding:8px; border-radius:4px;">⏳ 具体的な日付は、設計依頼のご提出後に担当者が確認・設定します。</div>';
                    } else {
                        echo '<div style="color:#155724; font-size:12px; margin-bottom:10px; background:#d4edda; border:1px solid #c3e6cb; padding:8px; border-radius:4px;">✅ 一次回答期日：<strong>' . date('Y年m月d日', strtotime($primary_due_date)) . '</strong>（スケジュール確定済み）</div>';
                    }

                    // 警告メッセージの表示
                    echo '<div style="color:#6d28d9; font-size:11px; margin-bottom:10px; background:#f5f3ff; border:1px solid #ddd6fe; padding:8px; border-radius:4px; line-height:1.4;">';
                    echo '<strong>⚠️ ご希望日入力についてのお願い</strong><br>';
                    echo '各工程の「ご希望日」を入力いただけます。ご希望日に収められるようサポート担当者および協力業者一同、善処いたしますが、他案件の進捗や確認機関の審査状況などにより、お約束（保証）するものではございません。予めご承知おき願います。';
                    echo '</div>';

                    echo '<table style="width:100%; border-collapse:collapse; font-size:11px; margin-bottom:10px;">';
                    echo '<thead><tr style="background:#f1f5f9; border-bottom:1px solid #cbd5e1;"><th style="padding:6px; text-align:left; width:20%;">工程</th><th style="padding:6px; text-align:left; width:30%;">担当</th><th style="padding:6px; text-align:left; width:20%;">予定日/実績日</th><th style="padding:6px; text-align:left; width:30%;">ご希望日</th></tr></thead>';
                    echo '<tbody>';
                    
                    $base_start_date = $primary_due_date ?: ($schedule_actuals[1] ?? $schedule_actuals[0] ?? '');
                    $calc_date = $base_start_date;
                    $scheduleService = new \App\Services\ScheduleService($pdo);
                    $current_step_idx = $scheduleService->getCurrentStepIndex($scheduleItem['steps'], $schedule_actuals, $primary_due_date);
                    
                    foreach ($scheduleItem['steps'] as $idx => $step) {
                        $bg_color = ($idx % 2 == 0) ? '#ffffff' : '#f8fafc';
                        $badge = '';
                        if ($step['actor'] == 'designer') {
                            $badge = '<span style="background:#3b82f6; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟦 サポート</span>';
                        } elseif ($step['actor'] == 'client') {
                            $client_display_name = htmlspecialchars($project_info['client_name'], ENT_QUOTES) . '様';
                            $badge = '<span style="background:#10b981; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">🟩 ' . $client_display_name . '</span>';
                        } else {
                            $badge = '<span style="background:#64748b; color:white; padding:2px 6px; border-radius:10px; font-size:10px;">⬛ 審査・待機</span>';
                        }

                        $date_str = '<span style="color:#64748b;">未確定</span>';
                        
                        if ($base_start_date) {
                            if ($idx == 0) {
                                $date_str = '<span style="color:#64748b;">-</span>';
                            } elseif ($idx == 1) {
                                $calc_date = $schedule_overrides[$idx] ?? $base_start_date;
                                $date_str = '<strong>' . date('m/d', strtotime($calc_date)) . '</strong>';
                            } else {
                                if ($step['type'] == 'biz') {
                                    $calc_date = addBusinessDays($calc_date, $step['days']);
                                } elseif ($step['type'] == 'cal') {
                                    $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                                }
                                
                                // この工程に予定日の上書きがあるかチェック
                                if (!empty($schedule_overrides[$idx])) {
                                    $calc_date = $schedule_overrides[$idx];
                                    $date_str = '<span style="color:#2563eb; font-weight:bold;">' . date('m/d', strtotime($calc_date)) . ' (変)</span>';
                                } else {
                                    $date_str = date('m/d', strtotime($calc_date));
                                }
                            }
                        }

                        // 実施日があればそれを起算日に上書きする
                        $actual_date = $schedule_actuals[$idx] ?? '';
                        if ($actual_date) {
                            $calc_date = $actual_date;
                            $date_str = '<span style="color:#10b981; font-weight:bold;">' . date('m/d', strtotime($actual_date)) . ' (済)</span>';
                        }

                        $step_name = $step['name'];
                        if ($step_name === '残金のご精算') {
                            if (isset($balance) && $balance <= 0) {
                                $step_name = '審査完了';
                            }
                        }

                        // 依頼主希望日の表示と入力フォーム
                        $wish_val = $schedule_wishes[$idx] ?? '';
                        $wish_display = '';
                        if ($actual_date || $idx === 0 || $idx === 1) {
                            $wish_display = !empty($wish_val) ? '<span style="color:#6d28d9; font-weight:bold;">' . date('m/d', strtotime($wish_val)) . '</span>' : '<span style="color:#aaa;">-</span>';
                        } else {
                            $wish_display = '
                            <form action="project_detail.php?id='.$project_id.'" method="POST" style="margin:0; display:inline-flex; gap:3px; align-items:center;">
                                <input type="hidden" name="action" value="update_schedule_wish">
                                <input type="hidden" name="schedule_type" value="'.htmlspecialchars($scheduleItem['type'], ENT_QUOTES).'">
                                <input type="hidden" name="step_idx" value="'.$idx.'">
                                <input type="date" name="wish_date" value="'.htmlspecialchars($wish_val, ENT_QUOTES).'" style="font-size:10px; padding:2px; width:100px;">
                                <button type="submit" style="font-size:9px; padding:2px 4px; background:#f5f3ff; border:1px solid #ddd6fe; color:#6d28d9; border-radius:3px; cursor:pointer;">保存</button>
                            </form>';
                        }

                        $is_current = ($idx === $current_step_idx);
                        $row_style = "background:{$bg_color}; border-bottom:1px solid #e2e8f0;";
                        if ($is_current) {
                            $row_style = "background:#fee2e2; border:2px solid #ef4444; font-weight:bold;";
                        }
                        $current_badge = $is_current ? ' <span style="background:#ef4444; color:white; padding:1px 5px; border-radius:3px; font-size:9px; margin-left:5px; font-weight:bold;">👉 現在地</span>' : '';

                        echo "<tr style='{$row_style}'>";
                        echo "<td style='padding:6px; font-weight:bold; color:#334155;'>{$step_name}{$current_badge}<div style='font-size:9px; color:#94a3b8; font-weight:normal;'>{$step['desc']}</div></td>";
                        echo "<td style='padding:6px;'>{$badge}</td>";
                        echo "<td style='padding:6px;'>{$date_str}</td>";
                        echo "<td style='padding:6px;'>{$wish_display}</td>";
                        echo "</tr>";
                    }
                    echo '</tbody></table>';
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
            
        </div>

        <!-- 右パネル：チャット ＋ 依頼主アップロード図書 -->
        <div style="flex:1; display:flex; flex-direction:column; gap:15px; min-width:350px;">
            <div class="column col-right" style="flex:1; margin:0; width:100%; box-sizing:border-box;">
                <h2 class="section-title" style="background:#17a2b8;">💬 メッセージ <span style="font-size:10px; font-weight:normal; margin-left:10px; color:#fff3cd;">※チェックバックは添付ファイルを添えてチャットにUPして下さい。</span></h2>
                <!-- チャットエリア (LINEスタイル) -->
                <div class="chat-wrapper">
                    <div class="chat-messages" id="chatMessages">
                        <?php foreach ($chat_messages as $msg):
                            $isMe = ($msg['sender_id'] == $_SESSION['user_id']);
                            $rowClass = $isMe ? 'from-me' : '';
                            $bubbleClass = ($msg['sender_id'] == 1) ? 'bubble-admin' : 'bubble-client';
                            $avatarClass = ($msg['sender_id'] == 1) ? 'admin-avatar' : 'client-avatar';
                            $avatarIcon  = ($msg['sender_id'] == 1) ? '👷' : '👤';
                            $senderName  = ($msg['sender_id'] == 1) ? 'サポート担当者' : 'あなた';
                            $timeStr     = date('m/d H:i', strtotime($msg['created_at'] ?? 'now'));
                        ?>
                            <div class="chat-bubble-row <?= $rowClass ?>" data-msg-id="<?= $msg['id'] ?>">
                                <?php if (!$isMe): ?>
                                    <div class="chat-avatar <?= $avatarClass ?>"><?= $avatarIcon ?></div>
                                <?php endif; ?>
                                <div class="chat-content">
                                    <?php if (!$isMe): ?>
                                    <div class="chat-name"><?= $senderName ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($msg['message_text'])): ?>
                                    <div class="chat-bubble <?= $bubbleClass ?>"><?= htmlspecialchars($msg['message_text'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($msg['file_path'])): ?>
                                        <?php
                                            $ftype = $msg['file_type'] ?? '';
                                            $fpath = $msg['file_path'];
                                            $isGdrive = (strlen($fpath) > 15 && strpos($fpath, '/') === false && strpos($fpath, 'uploads/') !== 0);
                                            $furl = $isGdrive ? 'https://drive.google.com/file/d/' . htmlspecialchars($fpath, ENT_QUOTES) . '/view?usp=drivesdk' : htmlspecialchars($fpath, ENT_QUOTES);
                                            $thumbUrl = $isGdrive ? 'https://drive.google.com/thumbnail?id=' . htmlspecialchars($fpath, ENT_QUOTES) . '&sz=w200' : '';
                                        ?>
                                        <?php if ($ftype === 'image' && $isGdrive): ?>
                                            <a href="<?= $furl ?>" target="_blank">
                                                <img src="<?= $thumbUrl ?>" class="chat-image-thumb" alt="添付画像">
                                            </a>
                                        <?php elseif ($ftype === 'pdf' || !empty($fpath)): ?>
                                            <a href="<?= $furl ?>" target="_blank" class="chat-pdf-link">📄 添付ファイルを開く</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <div class="chat-time">
                                        <?= $timeStr ?>
                                        <?php if ($isMe || $_SESSION['role'] === 'admin'): ?>
                                            <span class="chat-delete-btn" style="cursor:pointer; color:#ef4444; font-size:10px; margin-left:8px;" onclick="deleteChatMessage(<?= $msg['id'] ?>)">取り消し</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($chat_messages)): ?>
                            <div style="text-align:center; color:#aaa; font-size:12px; margin-top:40px;">メッセージはまだありません</div>
                        <?php endif; ?>
                    </div>
    
                    <!-- 入力エリア -->
                    <div class="chat-input-area">
                        <div id="filePreview" class="chat-file-preview"></div>
                        <div style="margin-bottom:8px;">
                            <select id="chatTargetFile" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; font-size:12px;">
                                <option value="">-- 対象ファイル（全体へのメッセージ） --</option>
                                <?php
                                $uploaded_file_names = [];
                                foreach ($files_by_cat as $cat => $files) {
                                    foreach ($files as $f) {
                                        $uploaded_file_names[] = $f['file_name'];
                                    }
                                }
                                try {
                                    $stmtAllCenter = $pdo->prepare("SELECT file_name FROM project_files WHERE project_id = :pid AND is_latest = 1 ORDER BY id DESC");
                                    $stmtAllCenter->execute(['pid' => $project_id]);
                                    while ($row = $stmtAllCenter->fetch(PDO::FETCH_ASSOC)) { $uploaded_file_names[] = $row['file_name']; }
                                    $uploaded_file_names = array_unique($uploaded_file_names);
                                    foreach ($uploaded_file_names as $fname) {
                                        echo '<option value="' . htmlspecialchars($fname, ENT_QUOTES) . '">📎 ' . htmlspecialchars($fname, ENT_QUOTES) . '</option>';
                                    }
                                } catch (Exception $e) {}
                                ?>
                            </select>
                        </div>
                        <div class="chat-input-row">
                            <label class="chat-attach-btn" title="ファイルを添付">
                                📎
                                <input type="file" id="chatFileInput" accept="image/*,.pdf" style="display:none;" onchange="previewFile(this)">
                            </label>
                            <textarea id="chatTextarea" class="chat-textarea" placeholder="メッセージを入力..." rows="1" onkeydown="handleKey(event)"></textarea>
                            <button class="chat-send-btn" onclick="sendMessage()" title="送信">➤</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php if (($project_info['status'] ?? '') !== 'quote_req'): ?>
                <?php require __DIR__ . '/col_center_uploads.php'; ?>
            <?php endif; ?>
            
            <?php require __DIR__ . '/col_specs.php'; ?>
        </div>
        
        <!-- ===== 基本情報編集モーダル ===== -->
        <div class="modal-overlay" id="editInfoModal">
            <div class="modal-box" style="max-width:550px;">
                <div class="modal-title">🏠 依頼主情報の登録・編集</div>
                <form method="POST" action="project_detail.php?id=<?= $project_id ?>">
                    <input type="hidden" name="action" value="update_client_info">
                    <input type="hidden" name="project_id" value="<?= $project_id ?>">
                    
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">案件名</label>
                        <input type="text" name="project_name" value="<?= htmlspecialchars($project_info['project_name'], ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">会社・事務所名</label>
                            <input type="text" name="company_name" value="<?= htmlspecialchars($project_info['company_name'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">会社・事務所名フリガナ</label>
                            <input type="text" name="company_kana" value="<?= htmlspecialchars($project_info['company_kana'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="全角カタカナ">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:100px 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">郵便番号</label>
                            <input type="text" name="zip_code" value="<?= htmlspecialchars($project_info['zip_code'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 123-4567">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">住所</label>
                            <input type="text" name="address" value="<?= htmlspecialchars($project_info['address'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="市区町村・番地・マンション名等">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">電話番号</label>
                            <input type="text" name="phone_number" value="<?= htmlspecialchars($project_info['client_phone'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 03-1234-5678">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">担当者名</label>
                            <input type="text" name="contact_name" value="<?= htmlspecialchars($project_info['client_name'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">担当者名フリガナ</label>
                            <input type="text" name="contact_kana" value="<?= htmlspecialchars($project_info['contact_kana'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="全角カタカナ">
                        </div>
                        <div>
                            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">担当者携帯電話番号</label>
                            <input type="text" name="mobile_number" value="<?= htmlspecialchars($project_info['mobile_number'] ?? '', ENT_QUOTES) ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;" placeholder="例: 090-1234-5678">
                        </div>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:4px;">お見積書・ご請求書宛先名称</label>
                        <input type="text" name="billing_company_name" value="<?= htmlspecialchars($project_info['billing_company_name'] ?? '', ENT_QUOTES) ?>" placeholder="※変更がある場合のみ入力（空欄時は会社名＋担当者名）" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                    </div>
                    
                    <div style="margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="email_notifications" id="email_notifications" value="1" <?= ($project_info['email_notifications'] ?? 1) == 1 ? 'checked' : '' ?> style="cursor:pointer; width:16px; height:16px;">
                        <label for="email_notifications" style="font-weight:bold; font-size:12px; cursor:pointer; user-select:none;">チャットや成果物の登録時にメール通知を受け取る</label>
                    </div>
                    
                    <div class="modal-btns">
                        <button type="button" onclick="document.getElementById('editInfoModal').classList.remove('active')" style="padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:6px; cursor:pointer;">キャンセル</button>
                        <button type="submit" style="padding:8px 20px; background:#0056b3; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">保存</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- replaceModal removed -->
    </div>
</div>
