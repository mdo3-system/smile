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
        if (($project['req_permit'] ?? 0) == 1 || ($project['req_opt_kisohari'] ?? 0) == 1) {
            $schedulesToRender[] = [
                'steps' => getScheduleSteps($base_days),
                'actuals_col' => 'schedule_actuals'
            ];
        }
        if (($project['req_wall'] ?? 0) == 1) {
            $schedulesToRender[] = [
                'steps' => getScheduleStepsWall($base_days),
                'actuals_col' => 'schedule_actuals_wall'
            ];
        }
        if (($project['req_skin'] ?? 0) == 1) {
            $schedulesToRender[] = [
                'steps' => getScheduleStepsSkin($base_days),
                'actuals_col' => 'schedule_actuals_skin'
            ];
        }
        if (($project['req_sky'] ?? 0) == 1) {
            $schedulesToRender[] = [
                'steps' => getScheduleStepsSky($base_days),
                'actuals_col' => 'schedule_actuals_sky'
            ];
        }

        if (empty($schedulesToRender)) {
            $schedulesToRender[] = [
                'steps' => getScheduleSteps($base_days),
                'actuals_col' => 'schedule_actuals'
            ];
        }

        foreach ($schedulesToRender as $sched) {
            $actuals = json_decode($project[$sched['actuals_col']] ?? '{}', true) ?: [];
            $calc_date = $primaryDueDate;

            foreach ($sched['steps'] as $idx => $step) {
                if ($idx == 0) continue; // Skip initial doc reception (day 0)

                if ($idx == 1) {
                    $calc_date = $primaryDueDate;
                } else {
                    if ($step['type'] == 'biz') {
                        $calc_date = addBusinessDays($calc_date, $step['days']);
                    } elseif ($step['type'] == 'cal') {
                        $calc_date = date('Y-m-d', strtotime($calc_date . " +{$step['days']} days"));
                    }
                }

                $actual_date = $actuals[$idx] ?? '';
                $is_done = !empty($actual_date);
                $event_date = $is_done ? $actual_date : $calc_date;

                // Create Event title
                $title = "[{$projectName}] {$step['name']}";
                if ($is_done) {
                    $title .= " (完了)";
                }

                // Insert event as All-Day event
                try {
                    $event = new Event();
                    $event->setSummary($title);
                    $event->setDescription("担当: " . ($step['actor'] === 'designer' ? '設計サポート' : ($step['actor'] === 'client' ? '依頼主' : '審査・待機')));

                    $start = new EventDateTime();
                    $start->setDate($event_date);
                    $event->setStart($start);

                    $end = new EventDateTime();
                    // All day events end date must be exclusive (+1 day)
                    $end_date = date('Y-m-d', strtotime($event_date . ' +1 day'));
                    $end->setDate($end_date);
                    $event->setEnd($end);

                    $this->service->events->insert($this->calendarId, $event);
                } catch (Exception $e) {
                    file_put_contents(__DIR__ . '/../../debug_api.txt', date('[Y-m-d H:i:s] ') . "Calendar insert failed: " . $e->getMessage() . "\n", FILE_APPEND);
                }

                // Update calculation reference date to actual date if completed
                if ($is_done) {
                    $calc_date = $actual_date;
                }
            }
        }
    }
}
