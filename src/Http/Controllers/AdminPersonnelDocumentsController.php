<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelAdminQueryService;
use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Personnel\Compliance\PersonnelFileUnavailable;
use App\Http\PersonnelAdminFileTransfer;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Presentation\Admin\PersonnelDocumentsView;
use InvalidArgumentException;
use Throwable;

final class AdminPersonnelDocumentsController
{
    private const ROOT = '/admin/personnel-documents';
    private const METADATA = ['document_type_id', 'employment_context_id', 'document_requirement_id', 'period_from', 'period_until', 'issued_on', 'received_on'];
    private const OPERATIONS = ['create', 'upload', 'review', 'hold', 'archive', 'correct', 'evidence'];
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin',
        'Content-Security-Policy' => "base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'",
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(
        private AuthService $auth,
        private CsrfService $csrf,
        private PersonnelAccessService $access,
        private PersonnelDocumentService $documents,
        private PersonnelAdminQueryService $queries,
        private PersonnelAdminFileTransfer $transfer,
        private PersonnelDocumentsView $view,
        private string $origin,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get(self::ROOT, $this->protected($this->overview(...)));
        $router->post(self::ROOT . '/search', $this->protected($this->overview(...)));
        $router->get(self::ROOT . '/users/{id}', $this->protected($this->employee(...)));
        $router->post(self::ROOT . '/users/{id}/as-of', $this->protected($this->employee(...)));
        $router->get(self::ROOT . '/users/{id}/audit', $this->protected($this->audit(...)));
        $router->post(self::ROOT . '/users/{id}/audit', $this->protected($this->audit(...)));
        $router->post(self::ROOT . '/users/{id}/documents', $this->protected($this->create(...), true));
        $router->get(self::ROOT . '/{id}', $this->protected($this->document(...)));
        $router->get(self::ROOT . '/{id}/correction', $this->protected($this->correction(...), true));
        $router->post(self::ROOT . '/{id}/correction-target', $this->protected($this->correction(...), true));
        $router->post(self::ROOT . '/{id}/upload', $this->protected($this->upload(...), true, multipart: true));
        $router->post(self::ROOT . '/{id}/download', $this->protected($this->download(...)));
        foreach (['review', 'hold', 'archive', 'correct', 'evidence'] as $action) {
            $router->post(self::ROOT . '/{id}/' . $action, $this->protected(
                fn (Request $request, array $params): Response => $this->change($request, $params, $action),
                true,
            ));
        }
    }

    private function protected(callable $handler, bool $manage = false, bool $multipart = false): callable
    {
        return function (Request $request, array $params = []) use ($handler, $manage, $multipart): Response {
            try {
                $response = $this->authorize($request, $manage, $multipart) ?? $handler($request, $params);
            } catch (PersonnelAccessDenied) {
                $response = $this->error($request, 404, 'Die angeforderte Personalaktenansicht ist nicht verfügbar.');
            } catch (PersonnelConflict $conflict) {
                $response = $this->error($request, 409, $conflict->getMessage());
            } catch (InvalidArgumentException) {
                $response = $this->error($request, 422, 'Bitte prüfen Sie die eingegebenen Felder und die gewählte Zuordnung.');
            } catch (PersonnelFileUnavailable $failure) {
                [$status, $message] = $this->fileError($failure);
                $response = $this->error($request, $status, $message);
            } catch (Throwable) {
                $response = $this->error($request, 503, 'Die Personalakte konnte momentan nicht sicher verarbeitet werden.');
            }

            return $response->withHeaders(self::PRIVATE_HEADERS);
        };
    }

    private function authorize(Request $request, bool $manage, bool $multipart): ?Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return $this->wantsJson($request)
                ? $this->error($request, 401, 'Die Sitzung ist abgelaufen. Melden Sie sich erneut an.')
                : Response::redirect('/admin/login?next=' . rawurlencode(self::ROOT), $request->method() === 'POST' ? 303 : 302);
        }
        try {
            $this->access->requirePermission(PersonnelAccessService::VIEW);
            if ($manage) {
                $this->access->requirePermission(PersonnelAccessService::MANAGE);
            }
        } catch (PersonnelAccessDenied) {
            $this->access->auditAccess('admin.personnel.http', false);
            return $this->error($request, 403, 'Für diese Aktion fehlt die Personalaktenberechtigung.');
        }
        if ($request->method() !== 'POST') {
            return null;
        }
        $origin = $request->server('HTTP_ORIGIN');
        if (($origin !== null && $origin !== $this->origin) || $request->server('HTTP_SEC_FETCH_SITE') === 'cross-site'
            || !$this->csrf->isValid($request->csrfToken(), $this->csrfContext((int) $user['id']))) {
            return $this->error($request, 403, 'Die Anfrage konnte nicht der aktiven Sitzung zugeordnet werden.');
        }
        $contentType = strtolower(trim(explode(';', (string) $request->server('CONTENT_TYPE', ''), 2)[0]));
        if ($contentType !== ($multipart ? 'multipart/form-data' : 'application/x-www-form-urlencoded')) {
            return $this->error($request, 415, 'Das Anfrageformat wird für diese Aktion nicht unterstützt.');
        }

        return null;
    }

    private function overview(Request $request): Response
    {
        $search = $this->text($request, 'search');
        $filter = $this->text($request, 'filter', 'active');
        $page = $this->integer($request->input('page', '1'));
        $data = $this->queries->overview($search, $filter, $page);

        return Response::html($this->view->index($data, $this->state() + ['filters' => ['search' => $search, 'filter' => $filter, 'page' => $page]]));
    }

    private function employee(Request $request, array $params): Response
    {
        return $this->employeePage($this->integer($params['id']), $this->optionalText($request, 'as_of'));
    }

    private function employeePage(int $id, ?string $asOf = null, array $state = [], int $status = 200): Response
    {
        $data = $this->queries->employee($id, $asOf);
        if (!empty($data['can_manage'])) {
            $data += $this->queries->assignmentOptions($id);
        }

        return Response::html($this->view->employee($data, $state + $this->state()), $status);
    }

    private function audit(Request $request, array $params): Response
    {
        $data = $this->queries->employeeAudit($this->integer($params['id']), $this->integer($request->input('page', '1')));

        return Response::html($this->view->auditPage($data, $this->state()));
    }

    private function create(Request $request, array $params): Response
    {
        $id = $this->integer($params['id']);
        try {
            $this->allowFields($request, self::METADATA);
            $created = $this->documents->create($id, $this->metadata($request), $this->requestId($request));
        } catch (InvalidArgumentException | PersonnelConflict $failure) {
            [$status, $state] = $this->formError($request, 'create', $failure);

            return $this->employeePage($id, state: $state, status: $status);
        }

        return $this->redirect((int) $created['document_id'], 'Dokumentmetadaten angelegt.');
    }

    private function document(Request $request, array $params): Response
    {
        return $this->documentPage($this->integer($params['id']));
    }

    private function correction(Request $request, array $params): Response
    {
        $target = $this->optionalInteger($request, 'replacement_employee_id');
        $id = $this->integer($params['id']);
        $options = $this->queries->correctionOptions($id, $target, $this->text($request, 'search'), $this->integer($request->input('page', '1')));

        return $this->documentPage($id, ['active_form' => 'correct'], correction: $options);
    }

    private function documentPage(int $id, array $state = [], int $status = 200, ?array $correction = null): Response
    {
        $data = $this->queries->document($id);
        $data['evidence'] = $data['evidence_history'];
        if ($correction !== null) {
            $data['correction'] = $correction;
        }

        return Response::html($this->view->document($data, $state + $this->state()), $status);
    }

    private function upload(Request $request, array $params): Response
    {
        $id = $this->integer($params['id']);
        $this->allowFields($request, ['expected_predecessor', 'expected_revision']);
        try {
            $this->transfer->upload($request, $id, $this->integer($request->input('expected_predecessor'), 0),
                $this->integer($request->input('expected_revision')), $this->requestId($request));
        } catch (PersonnelFileUnavailable $failure) {
            if ($this->wantsJson($request)) {
                throw $failure;
            }
            [$status, $message] = $this->fileError($failure);

            return $this->documentPage($id, ['active_form' => 'upload', 'error' => $message], $status);
        }
        $message = 'Neue Dateiversion gespeichert.';
        if ($this->wantsJson($request)) {
            return Response::json(['ok' => true, 'message' => $message, 'redirect' => self::ROOT . '/' . $id]);
        }

        return $this->redirect($id, $message);
    }

    private function download(Request $request, array $params): Response
    {
        $this->allowFields($request, ['version_id']);

        return $this->transfer->download($this->integer($params['id']), $this->integer($request->input('version_id')));
    }

    private function change(Request $request, array $params, string $action): Response
    {
        $id = $this->integer($params['id']);
        $target = null;
        try {
            $this->allowFields($request, ['expected_revision', 'reason', ...match ($action) {
                'review' => ['status'],
                'hold' => ['hold', 'confirm'],
                'archive' => ['confirm'],
                'correct' => ['confirm', 'replacement_employee_id', ...self::METADATA],
                'evidence' => ['version_id', 'outcome', 'original_kind', 'paper_status', 'legible', 'matches_record'],
            }]);
            $revision = $this->integer($request->input('expected_revision'));
            $reason = $this->text($request, 'reason');
            $requestId = $this->requestId($request);
            $hold = $action === 'hold' ? $this->integer($request->input('hold'), 0, 1) === 1 : null;
            if (in_array($action, ['archive', 'correct'], true) || $hold === false) {
                $this->integer($request->input('confirm'), 1, 1);
            }
            if ($action === 'correct') {
                $target = $this->integer($request->input('replacement_employee_id'));
            }
            $result = match ($action) {
                'review' => $this->documents->changeStatus($id, $this->text($request, 'status'), $revision, $reason, $requestId),
                'hold' => $this->documents->setRetentionHold($id, (bool) $hold, $revision, $reason, $requestId),
                'archive' => $this->documents->archive($id, $revision, $reason, $requestId),
                'correct' => $this->documents->correctAssignment($id, $target, $this->metadata($request), $revision, $reason, $requestId),
                'evidence' => $this->documents->recordEvidenceReview($id, $this->integer($request->input('version_id')), $revision, [
                    'outcome' => $this->text($request, 'outcome'), 'original_kind' => $this->text($request, 'original_kind'),
                    'paper_status' => $this->text($request, 'paper_status'), 'reason' => $reason,
                    'legible' => $this->checkbox($request, 'legible'), 'matches_record' => $this->checkbox($request, 'matches_record'),
                ], $requestId),
            };
        } catch (InvalidArgumentException | PersonnelConflict $failure) {
            [$status, $state] = $this->formError($request, $action, $failure);
            $options = $action === 'correct' ? $this->queries->correctionOptions($id, $target) : null;

            return $this->documentPage($id, $state, $status, $options);
        }

        return $this->redirect((int) ($result['replacement_document_id'] ?? $id), match ($action) {
            'review' => 'Fachlicher Status gespeichert.',
            'hold' => $hold ? 'Aufbewahrungssperre gesetzt.' : 'Aufbewahrungssperre aufgehoben.',
            'archive' => 'Dokument archiviert.',
            'correct' => 'Die neue Zuordnung wurde angelegt. Die bisherige Akte wurde archiviert.',
            'evidence' => 'Nachweis für die gewählte Version erfasst.',
        });
    }

    private function metadata(Request $request): array
    {
        return [
            'document_type_id' => $this->integer($request->input('document_type_id')),
            'employment_context_id' => $this->optionalInteger($request, 'employment_context_id'),
            'document_requirement_id' => $this->optionalInteger($request, 'document_requirement_id'),
            'period_from' => $this->optionalText($request, 'period_from'),
            'period_until' => $this->optionalText($request, 'period_until'),
            'issued_on' => $this->optionalText($request, 'issued_on'),
            'received_on' => $this->optionalText($request, 'received_on'),
        ];
    }

    private function allowFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->input()), ['csrf_token', 'request_id', ...$allowed]) !== []) {
            throw new InvalidArgumentException('Das Formular enthält nicht zugelassene Felder.');
        }
    }

    private function text(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);
        if (!is_string($value) || strlen($value) > 240 || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Ungültiges Formularfeld.');
        }

        return trim($value);
    }

    private function optionalText(Request $request, string $key): ?string
    {
        $value = $this->text($request, $key);

        return $value === '' ? null : $value;
    }

    private function integer(mixed $value, int $minimum = 1, int $maximum = 4294967295): int
    {
        if (!is_string($value) || preg_match('/\A[0-9]{1,10}\z/', $value) !== 1 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new InvalidArgumentException('Ungültige Formularreferenz.');
        }

        return (int) $value;
    }

    private function optionalInteger(Request $request, string $key): ?int
    {
        $value = $this->optionalText($request, $key);

        return $value === null ? null : $this->integer($value);
    }

    private function checkbox(Request $request, string $key): bool
    {
        return $this->integer($request->input($key, '0'), 0, 1) === 1;
    }

    private function requestId(Request $request): string
    {
        $id = $this->text($request, 'request_id');
        if (preg_match('/\A[a-f0-9]{48}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Ungültige Auftragskennung.');
        }

        return $id;
    }

    private function state(): array
    {
        $ids = [];
        foreach (self::OPERATIONS as $operation) {
            $ids[$operation] = bin2hex(random_bytes(24));
        }
        $notice = $_SESSION['personnel_documents_notice'] ?? null;
        unset($_SESSION['personnel_documents_notice']);

        return [
            'csrf' => $this->csrf->token($this->csrfContext((int) ($this->auth->currentUser()['id'] ?? 0))),
            'request_ids' => $ids,
            'notice' => $notice,
        ];
    }

    private function csrfContext(int $actorId): string
    {
        return 'personnel-admin:' . session_id() . ':' . $actorId;
    }

    private function formError(Request $request, string $action, InvalidArgumentException | PersonnelConflict $failure): array
    {
        $old = [];
        foreach ([...self::METADATA, 'replacement_employee_id', 'reason', 'status', 'hold', 'version_id', 'outcome', 'original_kind', 'paper_status', 'legible', 'matches_record'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && strlen($value) <= 240 && preg_match('//u', $value) === 1) {
                $old[$key] = $value;
            }
        }
        $state = $this->state() + ['active_form' => $action, 'old' => $old];
        $requestId = $request->input('request_id');
        if (is_string($requestId) && preg_match('/\A[a-f0-9]{48}\z/', $requestId) === 1) {
            $state['request_ids'][$action] = $requestId;
        }
        $conflict = $failure instanceof PersonnelConflict;
        $state['error'] = $conflict ? $failure->getMessage() : 'Bitte prüfen Sie die Formulareingaben, den Zeitraum und die gewählte Zuordnung.';

        return [$conflict ? 409 : 422, $state];
    }

    private function redirect(int $id, string $notice): Response
    {
        $_SESSION['personnel_documents_notice'] = $notice;

        return Response::redirect(self::ROOT . '/' . $id, 303);
    }

    private function fileError(PersonnelFileUnavailable $failure): array
    {
        return match ($failure->reason) {
            'size' => [413, 'Zulässig sind Dateien bis 16 MiB.'],
            'transfer' => [422, 'Die Datei wurde nicht vollständig übertragen. Wählen Sie die Datei erneut aus.'],
            'format' => [422, 'Das Dokumentformat konnte nicht bestätigt werden. Wählen Sie eine gültige PDF-, JPEG- oder PNG-Datei.'],
            'fixture' => [422, 'In dieser Testinstanz sind ausschließlich vorbereitete synthetische Dateien zugelassen.'],
            'scan' => [422, 'Die Schadsoftwareprüfung hat die Datei nicht freigegeben.'],
            default => [503, 'Die Datei ist momentan nicht sicher verfügbar.'],
        };
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json(['ok' => false, 'message' => $message], $status);
        }

        return Response::html('<main><h1>Digitale Personalakte</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p><a href="' . self::ROOT . '">Zur Personalaktenübersicht</a></main>', $status);
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->server('HTTP_ACCEPT', '')), 'application/json');
    }
}
