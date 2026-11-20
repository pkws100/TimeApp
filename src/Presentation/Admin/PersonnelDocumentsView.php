<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

final class PersonnelDocumentsView
{
    private const ROOT = '/admin/personnel-documents';

    private const EMPLOYMENT_TYPES = [
        'unknown' => 'Ungeklärt',
        'regular' => 'Reguläre Beschäftigung',
        'marginal' => 'Minijob',
        'short_term' => 'Kurzfristige Beschäftigung',
        'vocational_training' => 'Berufsausbildung',
        'other' => 'Sonstige Beschäftigung',
    ];

    private const EVIDENCE_OUTCOMES = [
        'open' => 'Nachweis offen',
        'objected' => 'Nachweis beanstandet',
        'confirmed_internal' => 'Interne Sichtprüfung dokumentiert',
    ];

    private const ORIGINAL_KINDS = [
        'unknown' => 'Ungeklärt',
        'paper' => 'Papieroriginal',
        'digital_original' => 'Originär elektronisch',
        'digital_copy' => 'Digitalisierte Kopie',
        'digitized_copy' => 'Digitalisierte Kopie',
        'paper_original_retained' => 'Papieroriginal im Bestand vermerkt',
        'not_applicable' => 'Nicht anwendbar',
    ];

    private const PAPER_STATES = [
        'unknown' => 'Ungeklärt',
        'seen' => 'Papieroriginal eingesehen',
        'retained' => 'Aufbewahrung des Papieroriginals dokumentiert',
        'missing' => 'Papieroriginal fehlt',
        'not_applicable' => 'Nicht anwendbar',
    ];

    private const REASONS = [
        'initial_record' => 'Ersterfassung', 'change_of_circumstances' => 'Änderung ab Stichtag',
        'carried_forward' => 'Früherer Abschnitt fortgeschrieben', 'correction' => 'Historische Korrektur',
        'employment_end' => 'Beschäftigungsende', 'legacy_unclassified' => 'Historischer Grund ungeklärt',
        'metadata_registered' => 'Manuell erfasst', 'metadata_recorded' => 'Metadaten erfasst',
        'metadata_revised' => 'Metadaten ergänzt', 'technical_checks_passed' => 'Technische Prüfungen bestanden',
        'metadata_invalid' => 'Metadaten fehlerhaft', 'incorrect_assignment' => 'Zuordnung fehlerhaft',
        'insufficient_evidence' => 'Nachweis unzureichend', 'metadata_corrected' => 'Metadaten berichtigt',
        'reassessment_requested' => 'Erneute Prüfung angefordert', 'legal_hold' => 'Aufbewahrung erforderlich',
        'retention_review' => 'Aufbewahrung wird geprüft', 'hold_resolved' => 'Sperrgrund geklärt',
        'duplicate' => 'Doppelter Vorgang', 'superseded' => 'Durch neuen Vorgang ersetzt',
        'withdrawn' => 'Vorgang zurückgezogen', 'form_unresolved' => 'Formanforderung ungeklärt',
        'original_missing' => 'Original fehlt', 'signature_unresolved' => 'Signatur ungeklärt',
        'documentation_only' => 'Prüfstand dokumentieren', 'wrong_assignment' => 'Inhalt falsch zugeordnet',
        'illegible' => 'Inhalt nicht lesbar', 'incomplete' => 'Inhalt unvollständig',
        'visual_checks_confirmed' => 'Sichtprüfung dokumentiert', 'assignment' => 'Aktenbereich zugeteilt',
        'revocation' => 'Aktenbereich entzogen',
    ];

    private const ACTIONS = [
        'document_created' => 'Dokument angelegt', 'document_archived' => 'Dokument archiviert',
        'document_assignment_corrected' => 'Zuordnung korrigiert', 'version_created' => 'Metadatenversion angelegt',
        'file_version_created' => 'Dateiversion angelegt', 'review_pending_to_rejected' => 'Beanstandet',
        'review_rejected_to_pending' => 'Zur erneuten Prüfung geöffnet', 'retention_hold_set' => 'Aufbewahrungssperre gesetzt',
        'retention_hold_released' => 'Aufbewahrungssperre aufgehoben', 'document.read' => 'Dokumentansicht',
        'document.list' => 'Dokumentenliste', 'document.versions' => 'Versionsliste',
        'document.version' => 'Versionsansicht', 'document.history' => 'Änderungshistorie', 'document.employee_history' => 'Änderungshistorie der Mitarbeiterakte',
        'document.download_file' => 'Datei zur Auslieferung freigegeben', 'employment.history' => 'Beschäftigungshistorie',
        'employment.as_of' => 'Beschäftigung zum Stichtag', 'admin.personnel.employee' => 'Mitarbeiterakte',
        'admin.personnel.document' => 'Dokumentansicht', 'admin.personnel.employee_audit' => 'Protokoll der Mitarbeiterakte',
        'admin.personnel.correction_options' => 'Zuordnungsoptionen', 'admin.personnel.assignment_options' => 'Dokumenttypen und Regelrevisionen',
        'document.evidence_recorded' => 'Fachlicher Nachweis erfasst', 'evidence_recorded' => 'Fachlicher Nachweis erfasst',
        'document.evidence.history' => 'Fachliche Nachweise', 'document.evidence_history' => 'Fachliche Nachweise',
    ];

    public function __construct(
        private readonly AdminView $layout,
        private readonly bool $synthetic = true,
    ) {
    }

    /** @param array{csrf?:string, error?:string, notice?:string, old?:array, active_form?:string, request_ids?:array} $state */
    public function index(array $result, array $state): string
    {
        $path = self::ROOT;
        $search = $this->e($result['search'] ?? '');
        $filter = (string) ($result['filter'] ?? 'active');
        $filterSelect = $this->select('filter', 'Beschäftigte', ['active' => 'Aktive', 'archived' => 'Archivierte', 'all' => 'Alle'], $filter);
        $csrf = $this->hidden('csrf_token', $state['csrf'] ?? '');
        $rows = [];
        foreach ($result['employees'] ?? [] as $employee) {
            $id = (int) $employee['id'];
            $name = $this->employeeName($employee);
            $rows[] = [
                '<strong>' . $this->e($name) . '</strong><span class="muted">' . $this->employeeNumber($employee) . '</span>',
                $this->employmentStatus($employee),
                (string) (int) ($employee['document_count'] ?? 0),
                $this->reviewCounts($employee),
                !empty($employee['ambiguous']) ? $this->badge('Klärungsbedarf', 'warn') : 'Kein ungeklärter Widerspruch',
                '<a class="button button-secondary" href="' . $path . '/users/' . $id . '">Akte öffnen</a>',
            ];
        }
        $table = $this->table(['Mitarbeiter', 'Status', 'Dokumente', 'Prüfstand', 'Beschäftigung', 'Akte'], $rows, 'Im gewählten Bereich wurden keine Mitarbeiterakten gefunden.');
        $paging = $this->paging($result, $csrf);

        $content = <<<HTML
<section class="card stack" aria-labelledby="personnel-overview-heading">
    <h2 id="personnel-overview-heading">Mitarbeiterakten</h2>
    <form method="post" action="{$path}/search" class="form-grid personnel-search">
        {$csrf}
        <label><span>Name oder Personalnummer</span><input type="search" name="search" value="{$search}" maxlength="100" autocomplete="off"></label>
        {$filterSelect}
        <div class="personnel-form-actions"><button type="submit" class="button">Suchen</button></div>
    </form>
    {$table}
    {$paging}
</section>
HTML;

        return $this->screen('Digitale Personalakte', $path, 'Digitale Personalakte', '', $state, $content);
    }

    public function employee(array $data, array $state): string
    {
        $employee = $data['employee'];
        $path = self::ROOT . '/users/' . (int) $employee['id'];
        $history = $this->employmentHistory($data, $state, $path);
        $documents = $this->employeeDocuments($data['documents'] ?? []);
        $employeeAudit = '<section class="card stack" id="audit" aria-labelledby="personnel-audit-link-heading">'
            . '<h2 id="personnel-audit-link-heading">Audit</h2><div class="personnel-form-actions"><a class="button button-secondary" href="'
            . $path . '/audit">Protokoll der Mitarbeiterakte öffnen</a></div></section>';
        $mayCreate = !empty($data['can_manage']) && empty($employee['is_deleted']) && ($employee['employment_status'] ?? '') === 'active';
        $create = $mayCreate ? $this->createForm($data, $state, $path) : '';
        $links = ['#beschaeftigung' => 'Beschäftigung', '#dokumente' => 'Dokumente'];
        if ($mayCreate) {
            $links['#neues-dokument'] = 'Dokument anlegen';
        }
        $links['#audit'] = 'Audit';
        $facts = $this->facts([
            'Personalnummer' => $this->employeeNumber($employee),
            'Beschäftigungsstatus' => $this->employmentStatus($employee),
            'Mitarbeiterbezug' => '#' . (int) $employee['id'],
        ]);
        $navigation = $this->anchors($links);

        return $this->screen('Mitarbeiterakte', $path, $this->employeeName($employee), $this->breadcrumb(), $state,
            '<section class="card personnel-summary" aria-label="Mitarbeiterdaten">' . $facts . '</section>'
            . $navigation . $history . $documents . $create . $employeeAudit);
    }

    public function document(array $data, array $state): string
    {
        $document = $data['document'];
        $employee = $data['employee'];
        $id = (int) $document['id'];
        $path = self::ROOT . '/' . $id;
        $employeePath = self::ROOT . '/users/' . (int) $employee['id'];
        $versions = $data['versions'] ?? [];
        $title = (string) ($document['type_label'] ?? 'Dokument');
        $mayManage = !empty($data['can_manage']);
        $canUpload = $mayManage && empty($document['archived_at']) && ($document['review_status'] ?? '') === 'pending'
            && empty($employee['is_deleted']) && ($employee['employment_status'] ?? '') === 'active';
        $fileAvailable = (bool) ($state['file_available'] ?? $data['file_available'] ?? true);
        $links = ['#versionen' => 'Versionen', '#nachweise' => 'Fachlicher Nachweis'];
        if ($mayManage) {
            $links['#aktionen'] = 'Aktionen';
        }
        $links['#audit'] = 'Audit';
        $facts = $this->facts([
            'Mitarbeiter' => '<a href="' . $employeePath . '">' . $this->e($this->employeeName($employee)) . '</a>',
            'Zeitraum' => $this->period($document, 'period_from', 'period_until'),
            'Ausgestellt' => $this->date($document['issued_on'] ?? null),
            'Eingegangen' => $this->date($document['received_on'] ?? null),
            'Erfasst' => $this->e($this->source($document['source'] ?? '')),
            'Zuordnung' => empty($document['employment_context_id']) ? 'Ohne Beschäftigungskontext' : 'Beschäftigungskontext #' . (int) $document['employment_context_id'],
            'Regelbezug' => $this->ruleLabel($document['rule'] ?? $data['rule'] ?? null),
            'Fachstatus' => $this->documentStatus($document),
            'Aufbewahrung' => $this->retentionStatus($document),
            'Archivierung' => $this->archiveStatus($document),
        ]);
        $summary = '<section class="card stack personnel-summary" aria-labelledby="personnel-document-facts"><h2 id="personnel-document-facts">Dokument #' . $id . '</h2>' . $facts . '</section>';
        $upload = $canUpload ? $this->uploadForm($versions, (int) $document['lock_version'], $path, $state, $fileAvailable) : '';
        $versionList = $this->versions($versions, $path, $state, $fileAvailable);
        $evidence = $this->evidence($data['evidence'] ?? $data['evidence_history'] ?? [], $versions, $document, $path, $state, $mayManage);
        $actions = $mayManage ? $this->documentActions($data, $state, $path) : '';
        $audit = $this->audit($data['change_audit'] ?? $data['history'] ?? [], $data['access_audit'] ?? $data['access_history'] ?? [],
            fullAuditPath: $employeePath . '/audit');

        return $this->screen('Personalakten-Dokument', $path, $title, $this->breadcrumb($employeePath), $state,
            $summary . $this->anchors($links) . $upload . $versionList . $evidence . $actions . $audit);
    }

    private function employmentHistory(array $data, array $state, string $path): string
    {
        $asOf = $this->e($data['as_of'] ?? '');
        $currentIds = array_map(static fn (array $entry): int => (int) $entry['id'], $data['employment_current'] ?? []);
        $history = $data['employment_history'] ?? [];
        $ambiguous = $data['ambiguous_employment_keys'] ?? [];
        $csrf = $this->hidden('csrf_token', $state['csrf'] ?? '');
        $entries = '';
        foreach ($history as $entry) {
            $id = (int) $entry['id'];
            $key = (string) $entry['employment_key'];
            $label = $this->e($this->label(self::EMPLOYMENT_TYPES, $entry['employment_type'] ?? null));
            $revision = (int) $entry['revision'];
            $range = $this->period($entry, 'effective_from', 'effective_until');
            $classification = match (true) {
                in_array($id, $currentIds, true) => $this->badge('Wirksam am Stichtag', 'ok'),
                !empty($entry['superseded']) => $this->badge('Historische Revision', 'neutral'),
                in_array($key, $ambiguous, true) => $this->badge('Klärungsbedarf', 'warn'),
                default => $this->badge('Anderer Zeitraum', 'neutral'),
            };
            $facts = $this->facts([
                'Beschäftigungsschlüssel' => $this->e($key), 'Revisions-ID' => '#' . $id,
                'Erfassungsgrund' => $this->reason($entry['revision_reason'] ?? null),
                'Fortsetzung' => empty($entry['continues_context_id']) ? '—' : 'Kontext #' . (int) $entry['continues_context_id'],
                'Ersetzt' => empty($entry['supersedes_context_id']) ? '—' : 'Kontext #' . (int) $entry['supersedes_context_id'],
                'Studium' => $this->trait($entry['student_status'] ?? null),
                'Rentenversicherung' => $this->trait($entry['pension_insurance_status'] ?? null),
                'Grenzüberschreitender Status' => $this->trait($entry['cross_border_status'] ?? null),
                'Arbeitserlaubnis' => $this->trait($entry['work_authorization_status'] ?? null),
            ]);
            $entries .= '<details class="personnel-disclosure"><summary><span><strong>' . $label . '</strong><small class="muted">' . $range . ' · Revision ' . $revision . '</small></span>' . $classification . '</summary>' . $facts . '</details>';
        }
        $warnings = $ambiguous === [] ? '' : '<p class="notice warn">Zum gewählten Stichtag ist mindestens ein Beschäftigungsverhältnis fachlich ungeklärt.</p>';
        $entries = $entries !== '' ? $entries : '<p class="table-empty">Zu dieser Akte sind keine Beschäftigungsrevisionen erfasst.</p>';

        return <<<HTML
<section class="card stack" id="beschaeftigung" aria-labelledby="personnel-employment-heading">
    <h2 id="personnel-employment-heading">Beschäftigungshistorie</h2>
    <form method="post" action="{$path}/as-of" class="form-grid personnel-date-filter">
        {$csrf}
        <label><span>Stichtag</span><input type="date" name="as_of" value="{$asOf}" required></label>
        <div class="personnel-form-actions"><button type="submit" class="button button-secondary">Stichtag anzeigen</button></div>
    </form>
    {$warnings}
    {$entries}
</section>
HTML;
    }

    private function employeeDocuments(array $documents): string
    {
        $rows = [];
        foreach ($documents as $document) {
            $id = (int) $document['id'];
            $rows[] = [
                '<strong>' . $this->e($document['type_label'] ?? 'Dokument') . '</strong><span class="muted">#' . $id . '</span>',
                $this->period($document, 'period_from', 'period_until'),
                (string) (int) ($document['version_count'] ?? 0),
                $this->time($document['updated_at'] ?? $document['created_at'] ?? null),
                (int) ($document['file_version_count'] ?? 0) > 0 ? $this->badge('Dateiversion vorhanden', 'ok') : $this->badge('Ohne Datei'),
                $this->documentStatus($document),
                $this->retentionStatus($document) . '<span class="personnel-cell-status">' . $this->archiveStatus($document) . '</span>',
                '<a class="button button-secondary" href="' . self::ROOT . '/' . $id . '">Dokument öffnen</a>',
            ];
        }
        $table = $this->table(['Dokument', 'Zeitraum', 'Versionen', 'Letzte Änderung', 'Ablage', 'Prüfung', 'Aufbewahrung', 'Öffnen'], $rows, 'Zu dieser Akte sind keine Dokumente erfasst.');

        return '<section class="card stack" id="dokumente" aria-labelledby="personnel-documents-heading"><h2 id="personnel-documents-heading">Dokumente</h2>' . $table . '</section>';
    }

    private function createForm(array $data, array $state, string $path): string
    {
        $fields = $this->metadataFields($data, $this->old($state, 'create'));
        $tokens = $this->commandFields($state, 'create');
        return <<<HTML
<section class="card stack" id="neues-dokument" aria-labelledby="personnel-create-heading">
    <h2 id="personnel-create-heading">Dokument anlegen</h2>
    <form method="post" action="{$path}/documents" class="form-grid" data-personnel-assignment>
        {$tokens}
        {$fields}
        <div class="personnel-form-actions full-span"><button type="submit" class="button">Dokument anlegen</button></div>
    </form>
</section>
HTML;
    }

    private function metadataFields(array $options, array $old): string
    {
        $types = [];
        foreach ($options['types'] ?? $options['assignment_options']['types'] ?? [] as $type) {
            $types[(int) $type['id']] = (string) $type['label'];
        }
        $contexts = [];
        foreach ($options['employment_history'] ?? $options['contexts'] ?? [] as $entry) {
            $contextId = (int) $entry['id'];
            $contexts[$contextId] = (string) $entry['employment_key'] . ' · Revision ' . (int) $entry['revision']
                . ' · ' . $this->period($entry, 'effective_from', 'effective_until');
        }
        $ruleOptions = '<option value="">Ohne Regelbezug</option>';
        $selectedRule = (int) ($old['document_requirement_id'] ?? 0);
        foreach ($options['rules'] ?? $options['assignment_options']['rules'] ?? [] as $rule) {
            $id = (int) $rule['id'];
            $selected = $id === $selectedRule ? ' selected' : '';
            $ruleLabel = $this->ruleLabel($rule);
            $ruleOptions .= '<option value="' . $id . '" data-personnel-rule-type="' . (int) $rule['document_type_id'] . '"' . $selected . '>' . $ruleLabel . '</option>';
        }
        $type = $this->select('document_type_id', 'Dokumenttyp', ['' => 'Bitte wählen'] + $types, $old['document_type_id'] ?? '', true);
        $context = $this->select('employment_context_id', 'Beschäftigungskontext', ['' => 'Ohne Beschäftigungskontext'] + $contexts, $old['employment_context_id'] ?? '');
        $dates = '';
        foreach (['period_from' => 'Zeitraum ab', 'period_until' => 'Zeitraum bis', 'issued_on' => 'Ausstellungsdatum', 'received_on' => 'Eingangsdatum'] as $key => $label) {
            $dates .= '<label><span>' . $label . '</span><input type="date" name="' . $key . '" value="' . $this->e($old[$key] ?? '') . '"></label>';
        }

        return $type . $context
            . '<label class="full-span"><span>Gesetzliche Regelrevision</span><select name="document_requirement_id">' . $ruleOptions . '</select></label>'
            . '<p class="muted full-span personnel-form-help">Manuelle Erfassung. Die Regelzuordnung erfordert eine fachliche Prüfung.</p>'
            . $dates;
    }

    private function uploadForm(array $versions, int $revision, string $path, array $state, bool $fileAvailable): string
    {
        $latest = 0;
        foreach ($versions as $version) {
            $latest = max($latest, (int) $version['version_number']);
        }
        $tokens = $this->commandFields($state, 'upload', $revision) . $this->hidden('expected_predecessor', $latest);
        $disabled = $fileAvailable ? '' : ' disabled';
        $readinessMessage = 'Die Dateiablage ist ' . ($this->synthetic ? 'für diesen Testlauf ' : '') . 'zurzeit gesperrt.';
        $readiness = $fileAvailable ? '' : '<p class="notice warn">' . $readinessMessage . '</p>';
        $uploadGuidance = $this->synthetic ? 'Nur vorbereitete synthetische Testdateien. ' : '';

        return <<<HTML
<section class="card stack" id="upload" aria-labelledby="personnel-upload-heading">
    <h2 id="personnel-upload-heading">Datei hochladen</h2>
    {$readiness}
    <form method="post" action="{$path}/upload" enctype="multipart/form-data" class="stack personnel-upload" data-personnel-upload>
        {$tokens}
        <label class="personnel-upload-field">
            <span>Lokale Datei</span>
            <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="personnel-upload-requirements personnel-file-description" required{$disabled}>
        </label>
        <p class="muted personnel-form-help" id="personnel-upload-requirements">{$uploadGuidance}PDF, JPEG oder PNG · höchstens 16 MiB. Eine erfolgreiche Übertragung legt eine neue Version an.</p>
        <p id="personnel-file-description" class="muted personnel-form-help" data-personnel-file-description aria-live="polite"></p>
        <progress data-personnel-upload-progress max="100" aria-label="Dateiübertragung" hidden></progress>
        <p data-personnel-upload-status role="status" tabindex="-1" hidden></p>
        <p data-personnel-upload-login class="personnel-form-actions" hidden>
            <a href="/admin/login" target="_blank" rel="noopener noreferrer">Anmeldung in einem neuen Tab öffnen</a>
            <a href="{$path}">Akte nach der Anmeldung erneut öffnen</a>
        </p>
        <div class="personnel-form-actions">
            <button type="submit" class="button"{$disabled}>Datei hochladen</button>
            <button type="button" class="button button-secondary" data-personnel-reload-versions hidden>Aktualisierte Versionen laden</button>
        </div>
    </form>
</section>
HTML;
    }

    private function versions(array $versions, string $path, array $state, bool $fileAvailable): string
    {
        $cards = '';
        foreach (array_reverse($versions) as $version) {
            $id = (int) $version['id'];
            $number = (int) $version['version_number'];
            $stored = !empty($version['file_stored']);
            $format = $this->checkStatus($version['format_verified_at'] ?? null);
            $malware = $this->checkStatus($version['malware_scanned_at'] ?? null);
            $canDownload = $fileAvailable && $stored && !empty($version['format_verified_at']) && !empty($version['malware_scanned_at']);
            $versionFacts = $this->facts([
                'Erstellt' => $this->time($version['created_at'] ?? null),
                'Ersteller' => $this->actor($version, 'created_by'),
                'Dateityp' => $stored ? $this->e($version['mime_type'] ?? 'Ungeklärt') : '—',
                'Größe' => $stored ? $this->size($version['size_bytes'] ?? 0) : '—',
                'Formatprüfung' => $format,
                'Malwareprüfung' => $malware,
                'Dateiintegrität' => $stored ? 'Prüfung bei jedem Abruf' : '—',
                'Originalstatus' => $this->e($this->label(self::ORIGINAL_KINDS, $version['original_status'] ?? null)),
                'Signaturstatus' => 'Ungeklärt',
                'Vorgänger' => empty($version['supersedes_version_number']) ? '—' : 'Version ' . (int) $version['supersedes_version_number'],
            ]);
            $download = '';
            if ($canDownload) {
                $tokens = $this->hidden('csrf_token', $state['csrf'] ?? '') . $this->hidden('version_id', $id);
                $download = '<form method="post" action="' . $path . '/download" class="personnel-form-actions">'
                    . $tokens . '<button type="submit" class="button button-secondary">Version herunterladen</button></form>';
            } elseif ($stored) {
                $download = '<p class="muted">Download derzeit gesperrt.</p>';
            }
            $storage = $stored ? $this->badge('Datei gespeichert', 'ok') : $this->badge('Nur Metadaten');
            $cards .= '<article class="personnel-version stack"><header><h3>Version ' . $number . '</h3>' . $storage . '</header>'
                . $versionFacts . $download . '</article>';
        }
        $cards = $cards !== '' ? $cards : '<p class="table-empty">Zu diesem Dokument liegen keine Versionen vor.</p>';

        return '<section class="card stack" id="versionen" aria-labelledby="personnel-versions-heading"><h2 id="personnel-versions-heading">Versionen</h2>' . $cards . '</section>';
    }

    private function evidence(array $records, array $versions, array $document, string $path, array $state, bool $mayManage): string
    {
        $latest = null;
        foreach ($versions as $version) {
            if ($latest === null || (int) $version['version_number'] > (int) $latest['version_number']) {
                $latest = $version;
            }
        }
        $eligible = !empty($latest['file_stored']) && !empty($latest['format_verified_at']) && !empty($latest['malware_scanned_at'])
            ? [(int) $latest['id'] => 'Version ' . (int) $latest['version_number']]
            : [];
        $entries = [];
        foreach ($records as $entry) {
            $entries[] = [
                'Version ' . (int) ($entry['version_number'] ?? 0),
                $this->time($entry['created_at'] ?? null) . '<span class="muted">' . $this->actor($entry) . '</span>',
                $this->e($this->label(self::EVIDENCE_OUTCOMES, $entry['outcome'] ?? null)) . '<span class="muted">' . $this->reason($entry['reason'] ?? null) . '</span>',
                $this->e($this->label(self::ORIGINAL_KINDS, $entry['original_kind'] ?? null))
                    . '<span class="muted">' . $this->e($this->label(self::PAPER_STATES, $entry['paper_status'] ?? null)) . '</span>',
                '<span>Manuelle Sichtprüfung · Verfahren ' . (int) ($entry['procedure_version'] ?? 1) . '</span><span class="muted">Signatur ungeklärt</span>',
            ];
        }
        $table = $this->table(['Version', 'Prüfung', 'Ergebnis', 'Original', 'Verfahren'], $entries,
            'Zu diesem Dokument ist kein fachlicher Nachweis erfasst.');
        $form = $mayManage && $eligible !== [] && empty($document['archived_at']) && ($document['review_status'] ?? '') === 'pending'
            ? $this->evidenceForm($eligible, (int) $document['lock_version'], $path, $state)
            : '';

        return <<<HTML
<section class="card stack" id="nachweise" aria-labelledby="personnel-evidence-heading">
    <h2 id="personnel-evidence-heading">Fachlicher Nachweis</h2>
    <p class="muted personnel-form-help">Die manuelle Sichtprüfung dokumentiert Lesbarkeit und Zuordnung. Originalnachweis und Signaturprüfung werden unabhängig davon ausgewiesen.</p>
    {$table}
    {$form}
</section>
HTML;
    }

    private function evidenceForm(array $versions, int $revision, string $path, array $state): string
    {
        $old = $this->old($state, 'evidence');
        $open = $this->isActive($state, 'evidence') ? ' open' : '';
        $tokens = $this->commandFields($state, 'evidence', $revision);
        $version = $this->select('version_id', 'Dokumentversion', ['' => 'Bitte wählen'] + $versions, $old['version_id'] ?? '', true);
        $outcome = $this->select('outcome', 'Prüfergebnis', self::EVIDENCE_OUTCOMES, $old['outcome'] ?? 'open');
        $original = $this->select('original_kind', 'Art des Originals', array_intersect_key(self::ORIGINAL_KINDS, array_flip(['unknown', 'paper', 'digital_original', 'digital_copy'])), $old['original_kind'] ?? 'unknown');
        $paper = $this->select('paper_status', 'Papieroriginal', self::PAPER_STATES, $old['paper_status'] ?? 'unknown');
        $reasons = [
            'open' => ['form_unresolved', 'original_missing', 'signature_unresolved', 'documentation_only'],
            'objected' => ['wrong_assignment', 'illegible', 'original_missing', 'incomplete'],
            'confirmed_internal' => ['visual_checks_confirmed'],
        ];
        $reasonOptions = '<option value="">Bitte wählen</option>';
        foreach ($reasons as $outcomeKey => $reasonKeys) {
            $reasonOptions .= '<optgroup label="' . self::EVIDENCE_OUTCOMES[$outcomeKey] . '" data-personnel-evidence-outcome="' . $outcomeKey . '">';
            foreach ($reasonKeys as $key) {
                $current = ($old['reason'] ?? '') === $key && ($old['outcome'] ?? '') === $outcomeKey ? ' selected' : '';
                $reasonOptions .= '<option value="' . $key . '"' . $current . '>' . $this->reason($key) . '</option>';
            }
            $reasonOptions .= '</optgroup>';
        }
        $legible = !empty($old['legible']) ? ' checked' : '';
        $matches = !empty($old['matches_record']) ? ' checked' : '';

        return <<<HTML
<details class="personnel-disclosure"{$open}>
    <summary>Prüfstand dokumentieren</summary>
    <form method="post" action="{$path}/evidence" class="form-grid" data-personnel-evidence>
        {$tokens}
        {$version}
        {$outcome}
        {$original}
        {$paper}
        <label class="full-span"><span>Prüfbegründung</span><select name="reason" required>{$reasonOptions}</select></label>
        <fieldset class="personnel-checklist full-span" data-personnel-checklist>
            <legend>Checkliste für die interne Sichtprüfung</legend>
            <label class="checkbox-item"><input type="checkbox" name="legible" value="1"{$legible}><span>Inhalt vollständig lesbar</span></label>
            <label class="checkbox-item"><input type="checkbox" name="matches_record" value="1"{$matches}><span>Inhalt dem Eintrag eindeutig zugeordnet</span></label>
        </fieldset>
        <div class="personnel-form-actions full-span"><button type="submit" class="button">Prüfstand speichern</button></div>
    </form>
</details>
HTML;
    }

    private function documentActions(array $data, array $state, string $path): string
    {
        $document = $data['document'];
        $revision = (int) $document['lock_version'];
        $held = !empty($document['retention_hold']);
        $holdReasons = $held ? ['hold_resolved'] : ['legal_hold', 'retention_review'];
        $holdTitle = $held ? 'Sperre aufheben' : 'Sperre setzen';
        $hold = $this->actionForm('hold', $holdTitle, $state, $revision, $path,
            $this->hidden('hold', (int) !$held) . $this->chooseReason($holdReasons, $this->old($state, 'hold'))
            . ($held ? $this->confirmation('Die Aufhebung der Aufbewahrungssperre wurde geprüft.') : ''));
        $archive = '';
        $review = '';
        $correction = '';
        if (empty($document['archived_at'])) {
            $status = $document['review_status'] ?? '';
            if (in_array($status, ['pending', 'rejected'], true)) {
                $reject = $status === 'pending';
                $statusReasons = $reject ? ['metadata_invalid', 'incorrect_assignment', 'insufficient_evidence'] : ['metadata_corrected', 'reassessment_requested'];
                $review = $this->actionForm('review', $reject ? 'Dokument beanstanden' : 'Zur erneuten Prüfung öffnen', $state, $revision, $path,
                    $this->hidden('status', $reject ? 'rejected' : 'pending') . $this->chooseReason($statusReasons, $this->old($state, 'review')));
            }
            if (!$held) {
                $archive = $this->actionForm('archive', 'Dokument archivieren', $state, $revision, $path,
                    $this->chooseReason(['duplicate', 'superseded', 'withdrawn', 'incorrect_assignment'], $this->old($state, 'archive'))
                    . $this->confirmation('Dokument und Versionshistorie bewusst archivieren.'));
                $options = $data['correction'] ?? $data['correction_options'] ?? $data['correctionOptions'] ?? null;
                $correction = is_array($options)
                    ? $this->correctionForm($options, $document, $state, $path)
                    : '<div class="personnel-form-actions"><a class="button button-secondary" href="' . $path . '/correction">Zuordnung korrigieren</a></div>';
            }
        }

        return <<<HTML
<section class="card stack" id="aktionen" aria-labelledby="personnel-actions-heading">
    <h2 id="personnel-actions-heading">Dokumentenaktionen</h2>
    {$review}
    {$hold}
    {$archive}
    {$correction}
</section>
HTML;
    }

    private function correctionForm(array $options, array $document, array $state, string $path): string
    {
        $old = $this->old($state, 'correct');
        $employee = $options['employee'] ?? null;
        $employees = [];
        foreach ($options['eligible_employees'] ?? [] as $candidate) {
            $employees[(int) $candidate['id']] = $this->employeeName($candidate);
        }
        $target = $this->select('replacement_employee_id', 'Mitarbeiter für die neue Zuordnung', ['' => 'Bitte wählen'] + $employees, $employee['id'] ?? '');
        $selectionCsrf = $this->hidden('csrf_token', $state['csrf'] ?? '');
        $paging = $this->pageButtons($path . '/correction-target', max(1, (int) ($options['page'] ?? 1)), !empty($options['has_more']),
            $selectionCsrf . $this->hidden('search', $options['search'] ?? '') . $this->hidden('lookup', 1));
        $search = $this->e($options['search'] ?? '');
        $selection = <<<HTML
<form method="post" action="{$path}/correction-target" class="form-grid">
    {$selectionCsrf}
    <label><span>Name oder Personalnummer</span><input type="search" name="search" value="{$search}" maxlength="100" autocomplete="off"></label>
    {$target}
    <div class="personnel-form-actions full-span">
        <button type="submit" class="button button-secondary" name="lookup" value="1">Mitarbeiter suchen</button>
        <button type="submit" class="button">Mitarbeiter übernehmen</button>
    </div>
</form>
HTML;
        $correction = '';
        if (is_array($employee)) {
            $defaults = array_intersect_key($document, array_flip(['document_type_id', 'document_requirement_id', 'period_from', 'period_until', 'issued_on', 'received_on']));
            if ((int) $employee['id'] === (int) $document['user_id']) {
                $defaults['employment_context_id'] = $document['employment_context_id'] ?? null;
            }
            $fields = $this->metadataFields($options, array_replace($defaults, $old));
            $tokens = $this->commandFields($state, 'correct', (int) $document['lock_version'])
                . $this->hidden('replacement_employee_id', (int) $employee['id']) . $this->hidden('reason', 'incorrect_assignment');
            $confirmation = $this->confirmation('Altes Dokument archivieren und die korrigierte Zuordnung als neues Dokument anlegen.');
            $selectedEmployee = $this->e($this->employeeName($employee));
            $correction = <<<HTML
<form method="post" action="{$path}/correct" class="form-grid" data-personnel-assignment>
    {$tokens}
    <p class="full-span personnel-form-help"><strong>Neue Akte</strong> {$selectedEmployee}</p>
    {$fields}
    {$confirmation}
    <div class="personnel-form-actions full-span"><button class="button" type="submit">Korrigierte Zuordnung speichern</button></div>
</form>
HTML;
        }

        return <<<HTML
<section class="personnel-disclosure stack" aria-labelledby="personnel-correct-heading">
    <h3 id="personnel-correct-heading">Zuordnung korrigieren</h3>
    <p class="muted personnel-form-help">Dateien und Versionen bleiben beim archivierten Dokument. Für die neue Zuordnung können anschließend eigene Dateien hochgeladen werden.</p>
    {$selection}
    {$paging}
    {$correction}
</section>
HTML;
    }

    private function actionForm(string $action, string $title, array $state, int $revision, string $path, string $fields): string
    {
        $open = $this->isActive($state, $action) ? ' open' : '';
        $tokens = $this->commandFields($state, $action, $revision);
        $title = $this->e($title);
        $style = $action === 'archive' ? ' button-danger' : '';

        return <<<HTML
<details class="personnel-disclosure"{$open}>
    <summary>{$title}</summary>
    <form method="post" action="{$path}/{$action}" class="form-grid">
        {$tokens}
        {$fields}
        <div class="personnel-form-actions full-span"><button type="submit" class="button{$style}">{$title}</button></div>
    </form>
</details>
HTML;
    }

    public function auditPage(array $data, array $state): string
    {
        $employee = $data['employee'];
        $employeePath = self::ROOT . '/users/' . (int) $employee['id'];
        $page = max(1, (int) ($data['page'] ?? 1));
        $path = $employeePath . '/audit';
        $hasMore = !empty($data['access_has_more']) || !empty($data['change_has_more']) || !empty($data['scope_has_more']) || !empty($data['employment_has_more']);
        $pages = $this->pageButtons($path, $page, $hasMore, $this->hidden('csrf_token', $state['csrf'] ?? ''));
        $contextChanges = [];
        foreach ($data['employment_history'] ?? [] as $event) {
            $contextChanges[] = [
                $this->time($event['created_at'] ?? null), $this->actor($event, 'created_by'),
                $this->e($event['employment_key'] ?? '') . '<span class="muted">Revision ' . (int) $event['revision'] . '</span>',
                $this->reason($event['revision_reason'] ?? null),
            ];
        }
        $employment = '<section class="card stack" aria-labelledby="personnel-employment-audit"><h2 id="personnel-employment-audit">Beschäftigungsrevisionen</h2>'
            . $this->table(['Zeitpunkt', 'Akteur', 'Beschäftigungsverhältnis', 'Begründung'], $contextChanges, 'Keine sichtbaren Beschäftigungsrevisionen.') . '</section>';
        $content = '<p class="muted">' . $this->e($this->employeeName($employee)) . '</p>'
            . $this->audit($data['change_audit'] ?? [], $data['access_audit'] ?? [], $data['scope_history'] ?? []) . $employment . $pages;

        return $this->screen('Protokoll der Mitarbeiterakte', $path, 'Protokoll der Mitarbeiterakte', $this->breadcrumb($employeePath), $state, $content);
    }

    private function audit(array $changes, array $accesses, array $scopes = [], ?string $fullAuditPath = null): string
    {
        $changeRows = [];
        foreach ($changes as $event) {
            $reference = $this->auditReference($event);
            if (!empty($event['related_document_id'])) {
                $related = (int) $event['related_document_id'];
                $reference .= '<span class="muted"><a href="' . self::ROOT . '/' . $related . '">Verknüpftes Dokument #' . $related . '</a></span>';
            }
            $changeRows[] = [
                $this->time($event['created_at'] ?? null), $this->actor($event), $this->action($event['action'] ?? null),
                $reference, $this->reason($event['reason'] ?? null),
            ];
        }
        $accessRows = [];
        foreach ($accesses as $event) {
            $allowed = ($event['outcome'] ?? '') === 'allowed';
            $accessRows[] = [
                $this->time($event['created_at'] ?? null), $this->actor($event), $this->action($event['action'] ?? null),
                $this->auditReference($event), $this->badge($allowed ? 'Erlaubt' : 'Abgelehnt', $allowed ? 'ok' : 'warn'),
            ];
        }
        $scopeRows = [];
        foreach ($scopes as $event) {
            $scopeRows[] = [
                $this->time($event['created_at'] ?? null), $this->actor($event),
                $this->e($event['grantee_name'] ?? ('Benutzer #' . (int) ($event['grantee_user_id'] ?? 0))),
                $this->badge(!empty($event['is_granted']) ? 'Zugewiesen' : 'Entzogen'),
                $this->reason($event['reason_code'] ?? null),
            ];
        }
        $changesTable = $this->table(['Zeitpunkt', 'Akteur', 'Aktion', 'Bezug', 'Begründung'], $changeRows, 'Keine sichtbaren Änderungsereignisse.');
        $accessTable = $this->table(['Zeitpunkt', 'Akteur', 'Aktion', 'Bezug', 'Ergebnis'], $accessRows, 'Keine sichtbaren Zugriffsereignisse.');
        $scopeRecords = $scopeRows === [] ? '' : '<h3>Mitarbeiterbereich</h3>'
            . $this->table(['Zeitpunkt', 'Akteur', 'Berechtigte Person', 'Änderung', 'Begründung'], $scopeRows, 'Keine sichtbaren Zuweisungsereignisse.');
        $fullAudit = $fullAuditPath !== null ? '<div class="personnel-form-actions"><a class="button button-secondary" href="' . $fullAuditPath . '">Protokoll der Mitarbeiterakte öffnen</a></div>' : '';

        return <<<HTML
<section class="card stack" id="audit" aria-labelledby="personnel-audit-heading">
    <h2 id="personnel-audit-heading">Audit</h2>
    {$fullAudit}
    <h3>Fachliche Änderungen</h3>
    {$changesTable}
    <h3>Dokumentierte Zugriffe</h3>
    {$accessTable}
    {$scopeRecords}
</section>
HTML;
    }

    private function screen(string $title, string $path, string $heading, string $breadcrumb, array $state, string $content): string
    {
        $heading = $this->e($heading);
        $path = $this->e($path);
        $activeForm = $this->e($state['active_form'] ?? '');
        $testMode = $this->synthetic ? '<span class="badge neutral">Synthetischer Testbetrieb</span>' : '';
        $notice = '';
        if (!empty($state['error'])) {
            $notice = '<p class="notice error" role="alert" tabindex="-1" data-personnel-error>' . $this->e($state['error']) . '</p>';
        } elseif (!empty($state['notice'])) {
            $notice = '<p class="notice success" role="status">' . $this->e($state['notice']) . '</p>';
        }
        $html = <<<HTML
<link rel="stylesheet" href="/assets/css/admin-personnel-documents.css">
<div class="personnel-records stack" data-personnel-records data-canonical-url="{$path}" data-active-form="{$activeForm}">
    {$breadcrumb}
    <header class="page-header personnel-header">
        <h1>{$heading}</h1>
        {$testMode}
    </header>
    {$notice}
    {$content}
</div>
HTML;

        return $this->layout->render($title, $html, '<script src="/assets/js/admin-personnel-documents.js" defer></script>');
    }

    private function breadcrumb(?string $employeePath = null): string
    {
        $trail = '<a href="' . self::ROOT . '">Digitale Personalakte</a>';
        if ($employeePath !== null) {
            $trail .= '<a href="' . $employeePath . '">Mitarbeiterakte</a>';
        }

        return '<nav class="personnel-breadcrumb" aria-label="Übergeordnete Seiten">' . $trail . '</nav>';
    }

    private function anchors(array $links): string
    {
        $items = '';
        foreach ($links as $target => $label) {
            $items .= '<a class="scope-link" href="' . $this->e($target) . '">' . $this->e($label) . '</a>';
        }

        return '<nav class="scope-switch personnel-page-nav" aria-label="Bereiche dieser Seite">' . $items . '</nav>';
    }

    private function table(array $headings, array $rows, string $empty): string
    {
        if ($rows === []) {
            return '<p class="table-empty">' . $this->e($empty) . '</p>';
        }
        $header = '';
        foreach ($headings as $heading) {
            $header .= '<th scope="col" role="columnheader">' . $this->e($heading) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr role="row">';
            foreach ($row as $index => $value) {
                $body .= '<td role="cell"><span aria-hidden="true" class="personnel-mobile-label">'
                    . $this->e($headings[$index]) . '</span><div>' . $value . '</div></td>';
            }
            $body .= '</tr>';
        }

        return '<table class="personnel-table" role="table"><thead role="rowgroup"><tr role="row">' . $header
            . '</tr></thead><tbody role="rowgroup">' . $body . '</tbody></table>';
    }

    private function facts(array $items): string
    {
        $facts = '';
        foreach ($items as $label => $value) {
            $facts .= '<div><dt>' . $this->e($label) . '</dt><dd>' . $value . '</dd></div>';
        }

        return '<dl class="personnel-facts">' . $facts . '</dl>';
    }

    private function paging(array $result, string $csrf): string
    {
        $csrf .= $this->hidden('search', $result['search'] ?? '') . $this->hidden('filter', $result['filter'] ?? 'active');

        return $this->pageButtons(self::ROOT . '/search', max(1, (int) ($result['page'] ?? 1)), !empty($result['has_more']), $csrf);
    }

    private function pageButtons(string $path, int $page, bool $hasMore, string $fields): string
    {
        if ($page <= 1 && !$hasMore) {
            return '';
        }
        $previous = $page > 1 ? '<button class="button button-secondary" type="submit" name="page" value="' . ($page - 1) . '">Vorherige Seite</button>' : '';
        $next = $hasMore ? '<button class="button button-secondary" type="submit" name="page" value="' . ($page + 1) . '">Nächste Seite</button>' : '';

        return '<form class="personnel-form-actions" action="' . $path . '" method="post" aria-label="Seitennavigation">' . $fields
            . $previous . '<span class="muted">Seite ' . $page . '</span>' . $next . '</form>';
    }

    private function select(string $name, string $label, array $options, mixed $selected, bool $required = false): string
    {
        $html = '';
        foreach ($options as $value => $option) {
            $selection = (string) $value === (string) $selected ? ' selected' : '';
            $html .= '<option value="' . $this->e($value) . '"' . $selection . '>' . $this->e($option) . '</option>';
        }

        return '<label><span>' . $this->e($label) . '</span><select name="' . $this->e($name) . '"' . ($required ? ' required' : '') . '>' . $html . '</select></label>';
    }

    private function hidden(string $name, mixed $value): string
    {
        return '<input type="hidden" name="' . $this->e($name) . '" value="' . $this->e($value) . '">';
    }

    private function commandFields(array $state, string $form, ?int $revision = null): string
    {
        return $this->hidden('csrf_token', $state['csrf'] ?? '')
            . $this->hidden('request_id', $state['request_ids'][$form] ?? '')
            . ($revision === null ? '' : $this->hidden('expected_revision', $revision));
    }

    private function chooseReason(array $codes, array $old): string
    {
        $reasons = ['' => 'Bitte wählen'];
        foreach ($codes as $code) {
            $reasons[$code] = self::REASONS[$code];
        }

        return $this->select('reason', 'Begründung', $reasons, $old['reason'] ?? '', true);
    }

    private function confirmation(string $label): string
    {
        return '<label class="checkbox-item full-span"><input type="checkbox" name="confirm" value="1" required><span>' . $this->e($label) . '</span></label>';
    }

    private function old(array $state, string $form): array
    {
        return $this->isActive($state, $form) ? ($state['old'] ?? []) : [];
    }

    private function isActive(array $state, string $form): bool
    {
        return ($state['active_form'] ?? null) === $form;
    }

    private function employeeName(array $employee): string
    {
        $name = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));

        return $name === '' ? 'Mitarbeiter #' . (int) ($employee['id'] ?? 0) : $name;
    }

    private function employeeNumber(array $employee): string
    {
        $number = (string) ($employee['employee_number'] ?? '');

        return $number === '' ? '—' : $this->e($number);
    }

    private function employmentStatus(array $employee): string
    {
        if (!empty($employee['is_deleted'])) {
            return $this->badge('Archiviert');
        }
        $status = $employee['employment_status'] ?? null;

        return match ($status) {
            'active' => $this->badge('Aktiv', 'ok'),
            'inactive' => $this->badge('Inaktiv'),
            'archived', 'terminated' => $this->badge('Ausgeschieden'),
            default => $this->badge('Status ungeklärt', 'warn'),
        };
    }

    private function documentStatus(array $document): string
    {
        $status = match ($document['review_status'] ?? null) {
            'pending' => $this->badge('Fachlich offen', 'warn'),
            'rejected' => $this->badge('Beanstandet', 'warn'),
            'reviewed' => $this->badge('Prüfstatus im Altbestand', 'warn'),
            default => $this->badge('Prüfstatus ungeklärt', 'warn'),
        };
        $evidence = $document['latest_evidence_outcome'] ?? null;
        if (is_string($evidence) && isset(self::EVIDENCE_OUTCOMES[$evidence])) {
            $status .= '<span class="personnel-cell-status muted">' . $this->e(self::EVIDENCE_OUTCOMES[$evidence]) . '</span>';
        }

        return $status;
    }

    private function reviewCounts(array $employee): string
    {
        $statuses = [
            'pending_count' => 'Fachlich offen', 'rejected_count' => 'Beanstandet',
            'reviewed_count' => 'Interne Sichtprüfung', 'legacy_reviewed_count' => 'Historischer Prüfstatus',
        ];
        $items = '';
        foreach ($statuses as $key => $label) {
            $count = (int) ($employee[$key] ?? 0);
            if ($count > 0) {
                $items .= '<span>' . $this->e($label) . ' ' . $count . '</span>';
            }
        }

        return $items === '' ? '—' : '<div class="personnel-counts">' . $items . '</div>';
    }

    private function retentionStatus(array $document): string
    {
        return !empty($document['retention_hold']) ? $this->badge('Gesperrt', 'warn') : 'Keine Sperre';
    }

    private function archiveStatus(array $document): string
    {
        return empty($document['archived_at']) ? 'Aktiver Bestand' : $this->badge('Archiviert') . ' ' . $this->date($document['archived_at']);
    }

    private function ruleLabel(?array $rule): string
    {
        if ($rule === null) {
            return 'Ohne Regelbezug';
        }
        $labels = [$this->e($rule['rule_code'] ?? '') . ' · Revision ' . (int) ($rule['revision'] ?? 0)];
        if (!empty($rule['citation'])) {
            $labels[] = $this->e($rule['citation']);
        }
        $labels[] = $this->period($rule, 'valid_from', 'valid_until');
        if (($rule['applicability_mode'] ?? '') === 'manual_review') {
            $labels[] = 'Manuelle Prüfung';
        }

        return implode(' · ', $labels);
    }

    private function auditReference(array $record): string
    {
        $ids = ['employee_user_id' => 'Mitarbeiter #', 'document_id' => 'Dokument #', 'document_version_id' => 'Versions-ID #'];
        $parts = [];
        foreach ($ids as $key => $label) {
            if (!empty($record[$key])) {
                $parts[] = $label . (int) $record[$key];
            }
        }

        return $parts === [] ? 'Aktuelle Akte' : $this->e(implode(' · ', $parts));
    }

    private function actor(array $record, string $prefix = 'actor'): string
    {
        $id = (int) ($record[$prefix . '_user_id'] ?? $record['changed_by_user_id'] ?? 0);
        $name = $record[$prefix . '_name'] ?? null;

        return is_string($name) && $name !== '' ? $this->e($name) : ($id > 0 ? 'Benutzer #' . $id : '—');
    }

    private function action(mixed $code): string
    {
        return $this->e($this->label(self::ACTIONS, $code, 'Historisches Ereignis'));
    }

    private function reason(mixed $code): string
    {
        return $this->e($this->label(self::REASONS, $code, '—'));
    }

    private function source(mixed $code): string
    {
        return $this->label(['manual' => 'Manuell', 'legacy' => 'Altbestand'], $code);
    }

    private function trait(mixed $code): string
    {
        return $this->e($this->label([
            'not_applicable' => 'Nicht anwendbar', 'working_student' => 'Werkstudent', 'mandatory' => 'Pflichtversichert',
            'exempt' => 'Befreit', 'coordination_required' => 'Abklärung erforderlich', 'not_required' => 'Nicht erforderlich',
            'evidence_required' => 'Nachweis erforderlich', 'other' => 'Sonstige',
        ], $code));
    }

    private function label(array $labels, mixed $code, string $fallback = 'Ungeklärt'): string
    {
        return is_string($code) ? ($labels[$code] ?? $fallback) : $fallback;
    }

    private function checkStatus(mixed $timestamp): string
    {
        return is_string($timestamp) && $timestamp !== '' ? $this->badge('Bestanden', 'ok') . ' ' . $this->time($timestamp) : 'Ausstehend';
    }

    private function badge(string $label, string $tone = 'neutral'): string
    {
        return '<span class="badge ' . $tone . '">' . $this->e($label) . '</span>';
    }

    private function period(array $entry, string $fromKey, string $untilKey): string
    {
        $from = $this->date($entry[$fromKey] ?? null);
        $until = $this->date($entry[$untilKey] ?? null);

        return match (true) {
            $from !== '—' && $until !== '—' => $from . ' – ' . $until,
            $from !== '—' => 'Ab ' . $from,
            $until !== '—' => 'Bis ' . $until,
            default => '—',
        };
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/', $value, $date) !== 1) {
            return '—';
        }

        return $date[3] . '.' . $date[2] . '.' . $date[1];
    }

    private function time(mixed $value): string
    {
        $date = $this->date($value);
        if ($date === '—' || preg_match('/[ T](\d{2}:\d{2}:\d{2})/', (string) $value, $time) !== 1) {
            return $date;
        }

        return $date . ' · ' . $time[1] . ' UTC';
    }

    private function size(mixed $value): string
    {
        $size = (int) $value;

        return match (true) {
            $size >= 1048576 => number_format($size / 1048576, 1, ',', '.') . ' MiB',
            $size >= 1024 => number_format($size / 1024, 1, ',', '.') . ' KiB',
            $size > 0 => $size . ' Byte',
            default => '—',
        };
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
