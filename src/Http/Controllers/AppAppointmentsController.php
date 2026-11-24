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
use InvalidArgumentException;
use RuntimeException;

final class AppAppointmentsController
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
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
        return $this->authorized(function (array $actor) use ($params): Response {
            $appointment = $this->appointments->findOwn($actor, $this->appointmentId($params));
            if ($appointment === null) {
                throw new AppointmentNotFoundException();
            }

            return $this->json(['ok' => true, 'data' => $appointment, 'csrf_token' => $this->csrf->token($this->context($actor))]);
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

            return $this->json(['ok' => true, 'data' => $this->appointments->acknowledge($actor, $this->appointmentId($params), $version)]);
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
