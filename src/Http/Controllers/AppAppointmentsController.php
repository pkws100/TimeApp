<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\AppointmentAccessDeniedException;
use App\Domain\Appointments\AppointmentCalendarExport;
use App\Domain\Appointments\AppointmentConflictException;
use App\Domain\Appointments\AppointmentNotFoundException;
use App\Domain\Appointments\AppointmentService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;
use RuntimeException;

final class AppAppointmentsController
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly string $installationUrl = 'http://localhost',
        private readonly bool $shareCalendarProject = false,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->authorized(function (array $actor) use ($request): Response {
            $filter = $request->query('filter', 'upcoming');
            $page = $this->id($request->query('page', 1));
            if (!is_string($filter) || $page === null) {
                throw new InvalidArgumentException('Bitte wählen Sie einen gültigen Terminfilter und eine gültige Seite.');
            }
            $list = $this->appointments->listOwn($actor, $filter, $page);

            return $this->json(['ok' => true, 'data' => $list['items'], 'page' => $list['page'], 'has_more' => $list['has_more']]);
        });
    }

    public function show(Request $request, array $params): Response
    {
        return $this->authorized(fn (array $actor): Response => $this->json([
            'ok' => true,
            'data' => $this->withGoogleCalendarUrl($this->ownAppointment($actor, $params)),
            'csrf_token' => $this->csrf->token($this->context($actor)),
        ]));
    }

    public function calendar(Request $request, array $params): Response
    {
        return $this->authorized(function (array $actor) use ($request, $params): Response {
            $appointment = $this->ownAppointment($actor, $params);
            $reminders = $request->query('reminders', '0');
            if (!in_array($reminders, ['0', '1'], true)) {
                throw new InvalidArgumentException('Die Auswahl für Kalendererinnerungen ist ungültig.');
            }

            $calendar = AppointmentCalendarExport::render(
                $appointment, $this->installationUrl, $this->shareCalendarProject, $reminders === '1',
            );

            return new Response($calendar, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="timeapp-termin.ics"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    public function acknowledge(Request $request, array $params): Response
    {
        return $this->authorized(function (array $actor) use ($request, $params): Response {
            if (!$this->csrf->isValid($request->csrfToken(), $this->context($actor))) {
                return $this->error(403, 'csrf_invalid', 'Bitte öffnen Sie den Termin erneut und bestätigen Sie ihn dann.');
            }
            $version = $this->id($request->input('version'));
            if ($version === null) {
                throw new InvalidArgumentException('Die Terminversion ist ungültig.');
            }

            $appointment = $this->appointments->acknowledge($actor, $this->appointmentId($params), $version);

            return $this->json(['ok' => true, 'data' => $this->withGoogleCalendarUrl($appointment)]);
        });
    }

    private function authorized(callable $action): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null) {
            return $this->error(401, 'auth_required', 'Bitte erneut anmelden.');
        }
        try {
            return $action($actor);
        } catch (AppointmentNotFoundException) {
            return $this->error(404, 'appointment_unavailable', 'Der Termin ist nicht verfügbar.');
        } catch (AppointmentConflictException $exception) {
            return $this->error(409, 'appointment_changed', $exception->getMessage());
        } catch (AppointmentAccessDeniedException) {
            return $this->error(403, 'forbidden', 'Für diese Aktion fehlt die Berechtigung.');
        } catch (InvalidArgumentException $exception) {
            return $this->error(422, 'invalid_input', $exception->getMessage());
        } catch (RuntimeException) {
            return $this->error(503, 'appointments_unavailable', 'Die Termine sind gerade nicht erreichbar. Bitte versuchen Sie es erneut.');
        }
    }

    private function appointmentId(array $params): int
    {
        return $this->id($params['id'] ?? null) ?? throw new AppointmentNotFoundException();
    }

    private function ownAppointment(array $actor, array $params): array
    {
        return $this->appointments->findOwn($actor, $this->appointmentId($params)) ?? throw new AppointmentNotFoundException();
    }

    private function withGoogleCalendarUrl(array $appointment): array
    {
        $appointment['google_calendar_url'] = AppointmentCalendarExport::googleUrl($appointment);

        return $appointment;
    }

    private function id(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);

        return $id === false ? null : $id;
    }

    private function context(array $actor): string
    {
        return 'app-appointments:' . (int) $actor['id'];
    }

    private function error(int $status, string $code, string $message): Response
    {
        return $this->json(['ok' => false, 'code' => $code, 'message' => $message], $status);
    }

    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
