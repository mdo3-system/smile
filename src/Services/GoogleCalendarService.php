<?php
namespace App\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use PDO;
use Exception;

class GoogleCalendarService
{
    private PDO $pdo;
    private ?Calendar $service = null;
    private ?string $calendarId = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->initService();
    }

    private function initService()
    {
        $credentials_path = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: 'credentials.json';
        if (!file_exists($credentials_path) && file_exists(__DIR__ . '/../../' . $credentials_path)) {
            $credentials_path = __DIR__ . '/../../' . $credentials_path;
        }

        if (!file_exists($credentials_path)) {
            return; // No Google credentials config
        }

        $client = new Client();
        $client->setAuthConfig($credentials_path);
        $client->addScope(Calendar::CALENDAR);

        // Check if service account
        $is_service_account = false;
        $credentials_data = json_decode(file_get_contents($credentials_path), true);
        if (is_array($credentials_data) && isset($credentials_data['type']) && $credentials_data['type'] === 'service_account') {
            $is_service_account = true;
        }

        if (!$is_service_account) {
            $client->setAccessType('offline');
            $token_path = __DIR__ . '/../../token.json';
            if (file_exists($token_path)) {
                $accessToken = json_decode(file_get_contents($token_path), true);
                if (is_array($accessToken) && isset($accessToken['access_token'])) {
                    $client->setAccessToken($accessToken);
                }
            }

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
                        return; // Fail silently or log
                    }
                } else {
                    return; // Fail silently
                }
            }
        }

        $this->service = new Calendar($client);
        $this->getOrCreateCalendar();
    }

    private function getOrCreateCalendar()
    {
        if (!$this->service) return;

        try {
            $calendarList = $this->service->calendarList->listCalendarList();
            foreach ($calendarList->getItems() as $calendarListEntry) {
                if ($calendarListEntry->getSummary() === '設計サポートカレンダー') {
                    $this->calendarId = $calendarListEntry->getId();
                    return;
                }
            }

            // Create new calendar if not found
            $newCalendar = new GoogleCalendar();
            $newCalendar->setSummary('設計サポートカレンダー');
            $newCalendar->setTimeZone('Asia/Tokyo');

            $createdCalendar = $this->service->calendars->insert($newCalendar);
            $this->calendarId = $createdCalendar->getId();
        } catch (Exception $e) {
            // Log calendar error
            file_put_contents(__DIR__ . '/../../debug_api.txt', date('[Y-m-d H:i:s] ') . "Calendar init failed: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    /**
     * Synchronize a project's forecast/actual milestones to Google Calendar.
     */
    public function syncProjectEvents(int $projectId)
    {
        if (!$this->service || !$this->calendarId) return;

        // Fetch project info
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :id");
        $stmt->execute(['id' => $projectId]);
        $project = $stmt->fetch();
        if (!$project) return;

        $projectName = $project['project_name'];
        $primaryDueDate = $project['primary_due_date'] ?? null;

        // Delete existing events for this project first to avoid duplicates
        try {
            $events = $this->service->events->listEvents($this->calendarId, [
                'q' => "[{$projectName}]"
            ]);
            foreach ($events->getItems() as $event) {
                $this->service->events->delete($this->calendarId, $event->getId());
            }
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/../../debug_api.txt', date('[Y-m-d H:i:s] ') . "Calendar clear failed: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        if (empty($primaryDueDate)) return;

        // Calculate schedule dates
        require_once __DIR__ . '/../../functions.php';
        $base_days = getScheduleBaseDays($project);

        $schedulesToRender = [];
        $is_koyou_or_kisohari = (($project['req_permit'] ?? 0) == 1 || ($project['req_opt_kisohari'] ?? 0) == 1);
        if ($is_koyou_or_kisohari) {
            $schedulesToRender['許可申請・基礎梁'] = [
                'steps' => getScheduleSteps($base_days, true),
                'actuals_col' => 'schedule_actuals'
            ];
        }
        if (($project['req_wall'] ?? 0) == 1) {
            $schedulesToRender['壁量計算'] = [
                'steps' => getScheduleStepsWall($base_days),
                'actuals_col' => 'schedule_actuals_wall'
            ];
        }
        if (($project['req_skin'] ?? 0) == 1) {
            $schedulesToRender['外皮計算'] = [
                'steps' => getScheduleStepsSkin($base_days),
                'actuals_col' => 'schedule_actuals_skin'
            ];
        }
        if (($project['req_sky'] ?? 0) == 1) {
            $schedulesToRender['天空率'] = [
                'steps' => getScheduleStepsSky($base_days),
                'actuals_col' => 'schedule_actuals_sky'
            ];
        }

        if (empty($schedulesToRender)) {
            $schedulesToRender['設計サポートスケジュール'] = [
                'steps' => getScheduleSteps($base_days, false),
                'actuals_col' => 'schedule_actuals'
            ];
        }

        $min_date = $primaryDueDate;
        $max_date = $primaryDueDate;
        $desc_lines = [];
        $designer_active_steps = [];

        foreach ($schedulesToRender as $title_type => $sched) {
            $actuals = json_decode($project[$sched['actuals_col']] ?? '{}', true) ?: [];
            $override_col = str_replace('actuals', 'overrides', $sched['actuals_col']);
            $overrides = json_decode($project[$override_col] ?? '{}', true) ?: [];
            $calc_date = $primaryDueDate;

            $desc_lines[] = "■ {$title_type}";
            $found_active_for_this_sched = false;
            $prev_step_done_date = null;

            foreach ($sched['steps'] as $idx => $step) {
                // Initial step (reception)
                if ($idx == 0) {
                    $actual_date = $actuals[$idx] ?? '';
                    $step_date = !empty($actual_date) ? $actual_date : $primaryDueDate;
                    $is_done = !empty($actual_date);
                    
                    $desc_lines[] = sprintf("  ・%s: %s%s", $step['name'], str_replace('-', '/', $step_date), $is_done ? ' (完了)' : '');
                    
                    if (strcmp($step_date, $min_date) < 0) {
                        $min_date = $step_date;
                    }
                    if (strcmp($step_date, $max_date) > 0) {
                        $max_date = $step_date;
                    }

                    if ($is_done) {
                        $prev_step_done_date = $actual_date;
                    } else {
                        if (!$found_active_for_this_sched && ($step['actor'] ?? '') === 'designer') {
                            $designer_active_steps[] = [
                                'name' => $step['name'],
                                'start_date' => $primaryDueDate,
                                'end_date' => date('Y-m-d')
                            ];
                            $found_active_for_this_sched = true;
                        }
                    }
                    continue;
                }

                if ($idx == 1) {
                    $calc_date = $overrides[$idx] ?? $primaryDueDate;
                } else {
                    if ($step['type'] == 'biz') {
                        $calc_date = addBusinessDays($calc_date, $step['days']);
                    } elseif ($step['type'] == 'cal') {
                        $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                    }
                    
                    if (!empty($overrides[$idx])) {
                        $calc_date = $overrides[$idx];
                    }
                }

                $actual_date = $actuals[$idx] ?? '';
                $is_done = !empty($actual_date);
                $step_date = $is_done ? $actual_date : $calc_date;

                $desc_lines[] = sprintf("  ・%s: %s%s", $step['name'], str_replace('-', '/', $step_date), $is_done ? ' (完了)' : '');

                if (strcmp($step_date, $min_date) < 0) {
                    $min_date = $step_date;
                }
                if (strcmp($step_date, $max_date) > 0) {
                    $max_date = $step_date;
                }

                if (!$is_done) {
                    if (!$found_active_for_this_sched) {
                        if (($step['actor'] ?? '') === 'designer') {
                            $start_d = !empty($prev_step_done_date) ? $prev_step_done_date : $primaryDueDate;
                            $today = date('Y-m-d');
                            $end_d = (strcmp($calc_date, $today) > 0) ? $calc_date : $today;
                            
                            $designer_active_steps[] = [
                                'name' => $step['name'],
                                'start_date' => $start_d,
                                'end_date' => $end_d
                            ];
                        }
                        $found_active_for_this_sched = true;
                    }
                } else {
                    $prev_step_done_date = $actual_date;
                }

                if ($is_done) {
                    $calc_date = $actual_date;
                }
            }
            $desc_lines[] = ""; // empty line
        }

        // Create contiguous All-Day event
        // End date is exclusive in Google Calendar All-day events, so add 1 day to max_date
        $start_date = $min_date;
        $end_date = date('Y-m-d', strtotime($max_date . ' +1 day'));

        $eventTitle = "[{$projectName}] 設計サポート";
        $eventDescription = "案件名: {$projectName}\n\n【スケジュール内訳】\n" . implode("\n", $desc_lines);

        try {
            $event = new Event();
            $event->setSummary($eventTitle);
            $event->setDescription($eventDescription);

            $start = new EventDateTime();
            $start->setDate($start_date);
            $event->setStart($start);

            $end = new EventDateTime();
            $end->setDate($end_date);
            $event->setEnd($end);

            $this->service->events->insert($this->calendarId, $event);
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/../../debug_api.txt', date('[Y-m-d H:i:s] ') . "Calendar insert failed: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        // Add daily 13:00 - 18:00 events for designer active steps, excluding Wednesday (3) and Sunday (0)
        foreach ($designer_active_steps as $active_step) {
            $current_date = $active_step['start_date'];
            $end_loop_date = $active_step['end_date'];

            while (strcmp($current_date, $end_loop_date) <= 0) {
                $day_of_week = date('w', strtotime($current_date));
                if ($day_of_week != 0 && $day_of_week != 3) {
                    try {
                        $event = new Event();
                        $event->setSummary("[{$projectName}] {$active_step['name']}（設計対応）");
                        $event->setDescription("案件名: {$projectName}\n工程: {$active_step['name']}\n設計サポートにて対応中のタスクです。");

                        $start = new EventDateTime();
                        $start->setDateTime("{$current_date}T13:00:00");
                        $start->setTimeZone('Asia/Tokyo');
                        $event->setStart($start);

                        $end = new EventDateTime();
                        $end->setDateTime("{$current_date}T18:00:00");
                        $end->setTimeZone('Asia/Tokyo');
                        $event->setEnd($end);

                        $this->service->events->insert($this->calendarId, $event);
                    } catch (Exception $e) {
                        file_put_contents(__DIR__ . '/../../debug_api.txt', date('[Y-m-d H:i:s] ') . "Calendar daily time event insert failed: " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                }
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
        }
    }
}
