<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\AppointmentAccessDeniedException;
use App\Domain\Appointments\AppointmentConflictException;
use App\Domain\Appointments\AppointmentNotFoundException;
use App\Domain\Appointments\AppointmentService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminAppointmentsView;
use DateTimeImmutable;
use InvalidArgumentException;

final class AdminAppointmentController
{
    private const ROOT = '/admin/appointments';
    private const FILTERS = ['period', 'from', 'to', 'user_id', 'project_id', 'status', 'q', 'page'];
    private const FIELDS = ['title', 'description', 'starts_at', 'ends_at', 'project_id', 'participant_ids', 'location', 'status', 'version'];

    public function __construct(
        private readonly AdminAppointmentsView $view,
        private readonly AppointmentService $appointments,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->handle(function (array $actor) use ($request): Response {
            $filters = array_intersect_key($request->query(), array_flip(self::FILTERS));
            $state = $this->notice($request);
            $status = 200;
            try {
                $result = $this->appointments->list($actor, $filters);
            } catch (InvalidArgumentException $error) {
                $result = ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
                $state = ['error' => $error->getMessage()];
                $status = 422;
            }

            return $this->html($this->view->index($result, $filters, $this->appointments->formOptions($actor),
                $this->auth->hasPermission('appointments.manage'), $this->canViewCalendar(), $state), $status);
        });
    }

    public function new(Request $request): Response
    {
        return $this->handle(function (array $actor) use ($request): Response {
            $date = $request->query('date');
            $start = is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            $start = $start !== false && $start->format('Y-m-d') === $date ? $start : new DateTimeImmutable('today');

            return $this->formPage($actor, null, [
                'starts_at' => $start->setTime(8, 0)->format('Y-m-d\TH:i'),
                'ends_at' => $start->setTime(9, 0)->format('Y-m-d\TH:i'),
            ]);
        });
    }

    public function show(Request $request, array $params): Response
    {
        return $this->handle(fn (array $actor): Response => $this->html($this->view->show(
            $this->find($actor, $params), $this->auth->hasPermission('appointments.manage'),
            $this->canViewCalendar(), $this->notice($request),
        )));
    }

    public function edit(Request $request, array $params): Response
    {
        return $this->handle(function (array $actor) use ($params): Response {
            $appointment = $this->find($actor, $params);
            if (!empty($appointment['archived_at'])) {
                return $this->html($this->view->show($appointment, false, $this->canViewCalendar(),
                    ['error' => 'Der archivierte Termin kann nur angezeigt werden.']), 409);
            }

            return $this->formPage($actor, $appointment);
        });
    }

    public function create(Request $request): Response
    {
        return $this->handle(function (array $actor) use ($request): Response {
            if (!$this->validCsrf($request, $actor)) {
                return $this->csrfError();
            }
            $data = $this->payload($request);
            try {
                $id = $this->appointments->create($actor, $data);
            } catch (InvalidArgumentException $error) {
                return $this->formPage($actor, null, $data, ['error' => $error->getMessage()], 422);
            }

            return Response::redirect(self::ROOT . '/' . $id . '?notice=created', 303);
        });
    }

    public function update(Request $request, array $params): Response
    {
        return $this->handle(function (array $actor) use ($request, $params): Response {
            if (!$this->validCsrf($request, $actor)) {
                return $this->csrfError();
            }
            $appointment = $this->find($actor, $params);
            $id = (int) $appointment['id'];
            $data = $this->payload($request);
            try {
                $this->appointments->update($actor, $id, $data);
            } catch (AppointmentConflictException) {
                return $this->formPage($actor, $appointment, $data,
                    ['error' => 'Der Termin wurde zwischenzeitlich geändert. Öffnen Sie den aktuellen Stand und prüfen Sie Ihre Eingaben.',
                        'conflict' => true], 409);
            } catch (InvalidArgumentException $error) {
                return $this->formPage($actor, $appointment, $data, ['error' => $error->getMessage()], 422);
            }

            return Response::redirect(self::ROOT . '/' . $id . '?notice=saved', 303);
        });
    }

    public function archive(Request $request, array $params): Response
    {
        return $this->handle(function (array $actor) use ($request, $params): Response {
            if (!$this->validCsrf($request, $actor)) {
                return $this->csrfError();
            }
            $appointment = $this->find($actor, $params);
            $version = filter_var($request->input('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($version === false) {
                return $this->html($this->view->error('Öffnen Sie die aktuelle Terminansicht und versuchen Sie es erneut.'), 422);
            }
            try {
                $this->appointments->archive($actor, (int) $appointment['id'], $version);
            } catch (AppointmentConflictException) {
                return $this->html($this->view->show($appointment, false, $this->canViewCalendar(),
                    ['error' => 'Der Termin wurde zwischenzeitlich geändert. Öffnen Sie die aktuelle Terminansicht.']), 409);
            }

            return Response::redirect(self::ROOT . '?notice=archived', 303);
        });
    }

    private function handle(callable $action): Response
    {
        try {
            return $action($this->auth->currentUser() ?? []);
        } catch (AppointmentAccessDeniedException) {
            return $this->html($this->view->error('Für diese Aktion fehlt die Terminberechtigung.'), 403);
        } catch (AppointmentNotFoundException) {
            return $this->html($this->view->error('Der Termin ist nicht verfügbar.'), 404);
        }
    }

    private function find(array $actor, array $params): array
    {
        $id = filter_var($params['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new AppointmentNotFoundException();
        }

        return $this->appointments->find($actor, $id) ?? throw new AppointmentNotFoundException();
    }

    private function formPage(array $actor, ?array $appointment, array $submitted = [], array $state = [], int $status = 200): Response
    {
        $original = $appointment ?? ['status' => 'planned'];
        $original['participant_ids'] = array_column($original['participants'] ?? [], 'id');
        $values = array_replace($original, $submitted);
        $options = $this->appointments->formOptions($actor, $appointment);

        return $this->html($this->view->form($appointment, $values, $options, $this->csrf->token($this->csrfContext($actor)), $state), $status);
    }

    private function payload(Request $request): array
    {
        $data = array_intersect_key($request->input(), array_flip(self::FIELDS));
        if ($request->input('participants_submitted') === '1') {
            $data += ['participant_ids' => []];
        }

        return $data;
    }

    private function notice(Request $request): array
    {
        $message = match ($request->query('notice')) {
            'created' => 'Termin angelegt.',
            'saved' => 'Änderungen gespeichert.',
            'archived' => 'Termin archiviert.',
            default => '',
        };

        return ['notice' => $message];
    }

    private function validCsrf(Request $request, array $actor): bool
    {
        return $this->csrf->isValid($request->csrfToken(), $this->csrfContext($actor));
    }

    private function csrfContext(array $actor): string
    {
        return 'admin-appointments:' . (int) ($actor['id'] ?? 0);
    }

    private function csrfError(): Response
    {
        return $this->html($this->view->error('Die Anfrage konnte nicht der aktiven Sitzung zugeordnet werden. Öffnen Sie das Formular neu.'), 403);
    }

    private function canViewCalendar(): bool
    {
        return $this->auth->hasPermission('timesheets.view') || $this->auth->hasPermission('appointments.view')
            || $this->auth->hasPermission('personnel.view');
    }

    private function html(string $content, int $status = 200): Response
    {
        return Response::html($content, $status, ['Cache-Control' => 'no-store']);
    }
}
