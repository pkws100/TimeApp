<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Timesheets\PauseAssessmentCalculator;
use App\Domain\Timesheets\PausePolicyService;
use App\Domain\Users\UserService;
use App\Domain\WorkTime\WorkTimeLocalTime;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminPausePolicyRenderer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AdminPausePolicyController
{
    public function __construct(
        private PausePolicyService $policies,
        private AuthService $auth,
        private CsrfService $csrf,
        private UserService $users
    ) {
    }

    public function canView(): bool
    {
        return $this->auth->hasPermission(PausePolicyService::VIEW) || $this->auth->hasPermission(PausePolicyService::MANAGE);
    }

    public function section(Request $request): string
    {
        if (!$this->canView()) {
            return '';
        }
        if (!$this->policies->available()) {
            return '<section class="card stack" id="pausenmodelle"><h2>Pausenmodelle</h2><p>Die Datenbankmigration für Pausenmodelle fehlt.</p></section>';
        }
        $userId = max(0, (int) filter_var($request->query('user_id'), FILTER_VALIDATE_INT));
        $beforeId = (int) filter_var($request->query('pause_history_before'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return (new AdminPausePolicyRenderer())->render(
            $this->policies->listTemplates(),
            $this->policies->listAssignments($userId ?: null),
            $this->policies->listDefaults(),
            $this->policies->listAudit($userId ?: null, $beforeId ?: PHP_INT_MAX),
            $this->users->list('active'),
            $userId,
            $this->auth->hasPermission(PausePolicyService::MANAGE),
            $this->csrf->token(),
            $request->query('pause_saved') === '1',
            is_string($request->query('pause_error')) ? $request->query('pause_error') : ''
        );
    }

    public function saveTemplate(Request $request): Response
    {
        return $this->mutate($request, function (int $actor) use ($request): ?int {
            $policy = $this->policyInput($request);
            $selected = $this->field($request, 'template_id');
            if ($selected === '') {
                $this->policies->createTemplate(['name' => $this->field($request, 'name'), 'policy' => $policy], $actor);
            } else {
                $id = $this->requiredId($selected, 'Vorlage');
                $this->policies->addVersion($id, $policy, $actor);
            }
            return null;
        });
    }

    public function archiveTemplate(Request $request, array $params): Response
    {
        return $this->mutate($request, function (int $actor) use ($params): ?int {
            $this->policies->archiveTemplate($this->requiredId($params['id'] ?? null, 'Vorlage'), $actor);
            return null;
        });
    }

    public function assign(Request $request): Response
    {
        return $this->mutate($request, function (int $actor) use ($request): int {
            $record = $this->policies->assign($this->scheduleInput($request) + [
                'user_id' => $request->input('user_id'),
                'overrides' => $this->policyInput($request, 'override_'),
            ], $actor);
            return $record['user_id'];
        });
    }

    public function scheduleDefault(Request $request): Response
    {
        return $this->mutate($request, function (int $actor) use ($request): ?int {
            if ($request->input('confirm_global') !== '1') {
                throw new InvalidArgumentException('Bitte die betriebsweite Aktivierung ausdrücklich bestätigen.');
            }
            $this->policies->scheduleDefault($this->scheduleInput($request), $actor);
            return null;
        });
    }

    public function endSchedule(Request $request, array $params): Response
    {
        return $this->mutate($request, function (int $actor) use ($request, $params): ?int {
            $scope = $params['scope'] ?? '';
            if (!in_array($scope, ['defaults', 'assignments'], true)) {
                throw new InvalidArgumentException('Der Geltungsbereich wurde nicht gefunden.');
            }
            return $this->policies->endAssignment(
                $this->requiredId($params['id'] ?? null, 'Zuordnung'),
                $scope === 'defaults',
                $this->field($request, 'effective_to'),
                $this->field($request, 'reason'),
                $actor
            );
        });
    }

    public function preview(Request $request): Response
    {
        if (!$this->canView() || !$this->validCsrf($request)) {
            return Response::json(['ok' => false, 'message' => 'Bitte die Seite neu laden und die Berechtigung prüfen.'], 403);
        }
        try {
            $versionId = $this->requiredId($request->input('policy_version_id'), 'Regelversion');
            $policy = $this->policies->policyForVersion($versionId);
            $workDate = $this->field($request, 'example_date');
            $startTime = $this->field($request, 'example_start');
            $endTime = $this->field($request, 'example_end');
            $workStart = WorkTimeLocalTime::fromInput($workDate . ' ' . $startTime);
            $overnight = $endTime < $startTime;
            $endDate = $overnight ? $workStart->modify('+1 day')->format('Y-m-d') : $workDate;
            $workEnd = WorkTimeLocalTime::fromInput($endDate . ' ' . $endTime);
            if ($workStart >= $workEnd) {
                throw new InvalidArgumentException('Der Beispielarbeitstag benötigt eine positive Dauer.');
            }
            $breaks = [];
            for ($index = 1; $index <= 2; ++$index) {
                $from = $this->field($request, 'example_break_start_' . $index);
                $to = $this->field($request, 'example_break_end_' . $index);
                if ($from === '' && $to === '') {
                    continue;
                }
                if ($from === '' || $to === '') {
                    throw new InvalidArgumentException('Jede Beispielpause benötigt Beginn und Ende.');
                }
                $breakStart = WorkTimeLocalTime::fromInput(($overnight && $from < $startTime ? $endDate : $workDate) . ' ' . $from);
                $pauseEndDate = $to < $from ? $breakStart->modify('+1 day')->format('Y-m-d') : $breakStart->format('Y-m-d');
                $breakEnd = WorkTimeLocalTime::fromInput($pauseEndDate . ' ' . $to);
                if ($breakStart < $workStart || $breakEnd > $workEnd || $breakStart >= $breakEnd) {
                    throw new InvalidArgumentException('Beispielpausen müssen vollständig innerhalb der Arbeitsbuchung liegen.');
                }
                $breaks[] = ['break_started_at' => $breakStart->format('Y-m-d H:i:s'), 'break_ended_at' => $breakEnd->format('Y-m-d H:i:s')];
            }
            $result = (new PauseAssessmentCalculator())->assess([[
                'work_date' => $workDate, 'start_time' => $startTime, 'end_time' => $endTime, 'breaks' => $breaks,
            ]], $policy);
            return Response::json(['ok' => true, 'data' => $result]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function mutate(Request $request, callable $operation): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null || !$this->auth->hasPermission(PausePolicyService::MANAGE) || !$this->validCsrf($request)) {
            return Response::json(['ok' => false, 'message' => 'Bitte die Seite neu laden und die Berechtigung prüfen.'], 403);
        }
        $json = str_contains((string) $request->server('HTTP_ACCEPT', ''), 'application/json');
        try {
            $userId = $operation((int) $actor['id']);
            $location = '/admin/work-time?pause_saved=1' . ($userId ? '&user_id=' . $userId : '') . '#pausenmodelle';
            return $json ? Response::json(['ok' => true, 'location' => $location]) : Response::redirect($location);
        } catch (InvalidArgumentException $exception) {
            return $json
                ? Response::json(['ok' => false, 'message' => $exception->getMessage()], 422)
                : Response::redirect('/admin/work-time?pause_error=' . rawurlencode($exception->getMessage()) . '#pausenmodelle');
        }
    }

    private function validCsrf(Request $request): bool
    {
        $token = $request->input('csrf_token');
        return is_string($token) && $this->csrf->isValid($token);
    }

    private function scheduleInput(Request $request): array
    {
        return [
            'policy_version_id' => $request->input('policy_version_id'),
            'effective_from' => $request->input('effective_from'),
            'effective_to' => $request->input('effective_to'),
            'reason' => $request->input('reason'),
            'replace_previous' => $request->input('replace_previous'),
        ];
    }

    private function policyInput(Request $request, string $prefix = ''): array
    {
        $result = [];
        foreach (['capture_mode', 'minimum_over_six_minutes', 'minimum_over_nine_minutes', 'additional_minutes'] as $key) {
            $value = $this->field($request, $prefix . $key);
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        $windows = $this->field($request, $prefix . 'planned_windows');
        if ($prefix === '' || $windows !== '' || $request->input($prefix . 'apply_windows') === '1') {
            $result['planned_windows'] = [];
            foreach ($windows === '' ? [] : explode(',', $windows) as $window) {
                if (!preg_match('/^\s*(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})\s*$/D', $window, $parts)) {
                    throw new InvalidArgumentException('Pausenfenster im Format 12:00-12:30 angeben. Mehrere Fenster mit Komma trennen.');
                }
                $result['planned_windows'][] = ['start' => $parts[1], 'end' => $parts[2]];
            }
        }
        return $result;
    }

    private function field(Request $request, string $key): string
    {
        $value = $request->input($key, '');
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Bitte gültige Formularwerte eingeben.');
        }
        return trim((string) $value);
    }

    private function requiredId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Bitte ' . $label . ' auswählen.');
        }
        return $id;
    }
}
