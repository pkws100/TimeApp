<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Initial catalog snapshot used by the seed migration. Publish changes as new revisions.
 */
final class InitialPersonnelComplianceCatalog
{
    private const COVERED_EMPLOYMENT = 'Beschäftigung im Anwendungsbereich des § 28f Abs. 1 SGB IV. Privathaushalte und zugelassene landwirtschaftliche Ausnahmen prüfen.';

    public static function seed(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT initial_personnel_compliance_catalog');
        }

        try {
            $sourceIds = [];
            foreach (self::sources() as $source) {
                $sourceIds[$source['code']] = self::insertOrVerify(
                    $pdo,
                    'personnel_compliance_sources',
                    ['code', 'version'],
                    $source
                );
            }

            foreach (self::rules() as ['type' => $type, 'source' => $source, 'requirement' => $requirement]) {
                $typeId = self::insertOrVerify($pdo, 'personnel_document_types', ['code'], $type);
                self::insertOrVerify($pdo, 'personnel_document_requirements', ['rule_code', 'revision'], [
                    ...$requirement,
                    'document_type_id' => $typeId,
                    'source_id' => $sourceIds[$source],
                ]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT initial_personnel_compliance_catalog');
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT initial_personnel_compliance_catalog');
                    $pdo->exec('RELEASE SAVEPOINT initial_personnel_compliance_catalog');
                }
            }

            throw $error;
        }
    }

    /**
     * @param list<string> $keyColumns
     * @param array<string, int|string|null> $row
     */
    private static function insertOrVerify(PDO $pdo, string $table, array $keyColumns, array $row): int
    {
        $columns = array_keys($row);
        $columnList = implode(', ', $columns);
        $keyConditions = implode(' AND ', array_map(static fn (string $column): string => "$column = :$column", $keyColumns));
        $select = $pdo->prepare("SELECT id, $columnList FROM $table WHERE $keyConditions FOR UPDATE");
        $key = array_intersect_key($row, array_flip($keyColumns));
        $select->execute($key);
        $existing = $select->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false) {
            foreach ($row as $column => $value) {
                $stored = $existing[$column];
                if (($stored === null) !== ($value === null) || (string) $stored !== (string) $value) {
                    throw new RuntimeException(sprintf(
                        'Die Katalogversion %s (%s) weicht von den veröffentlichten Initialdaten ab.',
                        $table,
                        implode(', ', $key)
                    ));
                }
            }

            return (int) $existing['id'];
        }

        $placeholders = ':' . implode(', :', $columns);
        $insert = $pdo->prepare("INSERT INTO $table ($columnList) VALUES ($placeholders)");
        $insert->execute($row);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, array<string, string|null>> */
    public static function sources(): array
    {
        return [
            'BVV-8' => [
                'code' => 'BVV-8',
                'version' => '2026-07-01',
                'title' => '§ 8 BVV – Entgeltunterlagen',
                'url' => 'https://rvrecht.deutsche-rentenversicherung.de/SharedDocs/rvRecht/05_Normen_und_Vertraege/02_A-B/BVV/0008/0008_2026_07_01.html?nn=1506186',
                'published_on' => null,
                'effective_from' => '2026-07-01',
                'checked_on' => '2026-11-18',
            ],
            'BVV-9' => [
                'code' => 'BVV-9',
                'version' => '2025-01-01',
                'title' => '§ 9 BVV – Beitragsabrechnung',
                'url' => 'https://www.gesetze-im-internet.de/beitrvv/__9.html',
                'published_on' => null,
                'effective_from' => '2025-01-01',
                'checked_on' => '2026-11-18',
            ],
            'BVV-9A-GG' => [
                'code' => 'BVV-9A-GG',
                'version' => '2022-04-01',
                'title' => 'Gemeinsame Grundsätze nach § 9a BVV vom 18.03.2022, genehmigt am 31.03.2022',
                'url' => 'https://www.deutsche-rentenversicherung.de/SharedDocs/Downloads/DE/Fachliteratur_Kommentare_Gesetzestexte/summa_summarum/rundschreiben/2022/gem_grundsaetze_entgeltunterlagen_beitragsabrechng.pdf?__blob=publicationFile&v=5',
                'published_on' => null,
                'effective_from' => '2022-04-01',
                'checked_on' => '2026-11-18',
            ],
            'SGB-IV-28F' => [
                'code' => 'SGB-IV-28F',
                'version' => 'Stand-2026-11-18',
                'title' => '§ 28f SGB IV – Aufzeichnungspflicht, Beitragsabrechnung und Beitragszahlung',
                'url' => 'https://www.gesetze-im-internet.de/sgb_4/__28f.html',
                'published_on' => null,
                'effective_from' => null,
                'checked_on' => '2026-11-18',
            ],
            'MILOG-17' => [
                'code' => 'MILOG-17',
                'version' => 'Stand-2026-11-18',
                'title' => '§ 17 MiLoG – Erstellen und Bereithalten von Dokumenten',
                'url' => 'https://www.gesetze-im-internet.de/milog/__17.html',
                'published_on' => null,
                'effective_from' => null,
                'checked_on' => '2026-11-18',
            ],
            'AENTG-19' => [
                'code' => 'AENTG-19',
                'version' => 'Stand-2026-11-18',
                'title' => '§ 19 AEntG – Erstellen und Bereithalten von Dokumenten',
                'url' => 'https://www.gesetze-im-internet.de/aentg_2009/__19.html',
                'published_on' => null,
                'effective_from' => null,
                'checked_on' => '2026-11-18',
            ],
        ];
    }

    /**
     * @return array<string, array{type: array<string, string>, source: string, requirement: array<string, int|string|null>}>
     */
    public static function rules(): array
    {
        $sources = self::sources();
        $rules = [];
        $groups = [
            [
                'prefix' => 'BVV-8-1-',
                'source' => 'BVV-8',
                'citation' => '§ 8 Abs. 1 S. 1 Nr. %s BVV',
                'entries' => self::recordEntries(),
            ],
            [
                'prefix' => 'BVV-8-2-',
                'source' => 'BVV-8',
                'citation' => '§ 8 Abs. 2 S. 1 Nr. %s BVV',
                'entries' => self::documentEntries(),
            ],
            [
                'prefix' => '',
                'source' => null,
                'citation' => null,
                'entries' => self::additionalEntries(),
            ],
        ];

        foreach ($groups as $group) {
            foreach ($group['entries'] as $number => $entry) {
                $code = $group['prefix'] . $number;
                $source = $entry['source'] ?? $group['source'];
                $position = strtolower(ltrim(explode('-', (string) $number, 2)[0], '0'));
                $citation = $entry['citation'] ?? sprintf($group['citation'], $position);
                $retention = $entry['retention'] ?? [
                    'end_of_year_of_last_social_audit',
                    1,
                    '§ 28f Abs. 1 SGB IV: bis zum Ende des auf die letzte einschlägige Betriebsprüfung folgenden Kalenderjahres. Andere Aufbewahrungsgründe und Sperren im Einzelfall prüfen.',
                ];
                $rules[$code] = [
                    'type' => [
                        'code' => $entry['type'],
                        'label' => $entry['label'],
                        'category' => $entry['category'],
                    ],
                    'source' => $source,
                    'requirement' => [
                        'rule_code' => $code,
                        'revision' => 1,
                        'citation' => $citation,
                        'valid_from' => $sources[$source]['effective_from'],
                        'valid_until' => null,
                        'legal_force' => 'statutory',
                        'record_kind' => $entry['kind'],
                        'record_frequency' => $entry['frequency'],
                        'applicability_mode' => 'manual_review',
                        'employment_scope' => $entry['scope'],
                        'evidence_rule' => $entry['evidence'],
                        'signature_rule' => $entry['signature'] ?? 'Keine Aussage zur Unterschrift aus dieser Fundstelle. Einschlägige Formvorgaben im Einzelfall prüfen.',
                        'retention_trigger' => $retention[0],
                        'retention_years' => $retention[1],
                        'retention_rule' => $retention[2],
                    ],
                ];
            }
        }

        return $rules;
    }

    private static function recordEntries(): array
    {
        return [
            '01' => self::entry(
                'employment_identity', 'Name und betriebliches Ordnungsmerkmal', 'employment', 'master_data', 'on_change',
                self::COVERED_EMPLOYMENT,
                'Vor- und Familienname, gegebenenfalls betriebliches Ordnungsmerkmal. Mehrteilige Unterlagen damit verknüpfen (§ 8 Abs. 1 S. 2 BVV).'
            ),
            '02' => self::entry(
                'birth_date', 'Geburtsdatum', 'employment', 'master_data', 'on_change',
                self::COVERED_EMPLOYMENT,
                'Geburtsdatum als Angabe im führenden Stammdatensystem.'
            ),
            '03' => self::entry(
                'third_country_residence_data', 'Staatsangehörigkeit und Aufenthaltstitel', 'cross_border', 'master_data', 'on_change',
                'Beschäftigte aus Staaten außerhalb des EWR. Aufenthaltsrechtlichen Status und Sonderfälle im konkreten Zeitraum prüfen.',
                'Staatsangehörigkeit und Aufenthaltstitel. Zweckgebundener Nachweis nach Abs. 2 S. 1 Nr. 1.'
            ),
            '04' => self::entry(
                'employment_address', 'Anschrift', 'employment', 'master_data', 'on_change',
                self::COVERED_EMPLOYMENT,
                'Anschrift aus dem führenden Stammdatensystem.'
            ),
            '05' => self::entry(
                'employment_dates', 'Beginn und Ende der Beschäftigung', 'employment', 'master_data', 'on_change',
                self::COVERED_EMPLOYMENT,
                'Tatsächlicher Beginn und Ende des Beschäftigungsverhältnisses.'
            ),
            '06' => self::entry(
                'partial_retirement_dates', 'Beginn und Ende der Altersteilzeit', 'payroll', 'payroll_data', 'on_change',
                'Nur bei Altersteilzeitarbeit.',
                'Beginn und Ende der Altersteilzeit, zeitlich von der Beschäftigung abgegrenzt.'
            ),
            '07-A' => self::entry(
                'flexible_work_value_credit', 'Wertguthaben aus flexibler Arbeitszeit', 'payroll', 'payroll_data', 'event_and_payroll_month',
                'Nur bei einschlägigem Wertguthaben. Rechtsnatur der Vereinbarung prüfen.',
                'Wertguthaben mit Zu- und Abgängen, Abrechnungsmonat der ersten Gutschrift und jeder Änderung. Bei Übertragung auch Kennzeichnung beim Dritten.'
            ),
            '07-B' => self::entry(
                'value_credit_insolvency_proof', 'Insolvenzsicherung des Wertguthabens', 'payroll', 'third_party_evidence', 'on_change',
                'Wenn für ein Wertguthaben Vorkehrungen zum Insolvenzschutz nachzuweisen sind.',
                'Nachweis über die Sicherungsvorkehrungen. Elektronisches Vorhalten gemäß Gemeinsamen Grundsätzen, Ziff. 2.1.'
            ),
            '08' => self::entry(
                'employment_classification', 'Beschäftigungsart', 'employment', 'master_data', 'on_change',
                self::COVERED_EMPLOYMENT,
                'Zeitbezogene Einordnung der Beschäftigungsart mit fachlich geprüfter Quelle.'
            ),
            '09' => self::entry(
                'insurance_status_data', 'Gründe für Versicherungsfreiheit oder Befreiung', 'social_insurance', 'payroll_data', 'on_change',
                'Nur wenn Versicherungsfreiheit oder Befreiung von der Versicherungspflicht relevant ist. Versicherungszweige und Geltungszeiträume separat prüfen.',
                'Maßgebende Angaben, Grund und Wirksamkeit. Sachbezogener Nachweis nach Abs. 2 S. 1 Nr. 1.'
            ),
            '10' => self::entry(
                'payroll_gross_remuneration', 'Arbeitsentgelt', 'payroll', 'payroll_data', 'payroll_period',
                self::COVERED_EMPLOYMENT,
                'Arbeitsentgelt nach § 14 SGB IV mit Zusammensetzung und zeitlicher Zuordnung. Ausnahme für Sachbezüge oder Belegschaftsrabatte ohne einkommensteuerliche Aufzeichnungspflicht.'
            ),
            '11' => self::entry(
                'payroll_contributory_remuneration', 'Beitragspflichtiges Arbeitsentgelt', 'payroll', 'payroll_data', 'payroll_period',
                'Soweit beitragspflichtiges Arbeitsentgelt vorliegt.',
                'Beitragspflichtiges Entgelt bis zur Beitragsbemessungsgrenze der Rentenversicherung, seine Zusammensetzung und zeitliche Zuordnung. Für Meldungen summieren.'
            ),
            '11A' => self::entry(
                'accident_insurance_remuneration', 'Unfallversicherungsentgelt und Gefahrtarif', 'payroll', 'payroll_data', 'payroll_period',
                'Soweit beitragspflichtiges Arbeitsentgelt in der Unfallversicherung vorliegt.',
                'Beitragspflichtiges Unfallversicherungsentgelt, Gefahrtarifstelle und zeitliche Zuordnung.'
            ),
            '12' => self::entry(
                'partial_retirement_amount', 'Altersteilzeitbetrag', 'payroll', 'payroll_data', 'payroll_period',
                'Nur bei einschlägiger Altersteilzeit.',
                'Betrag nach § 3 Abs. 1 Nr. 1 Buchst. b AltTZG. Für Meldungen summieren.'
            ),
            '13' => self::entry(
                'contribution_group_code', 'Beitragsgruppenschlüssel', 'social_insurance', 'payroll_data', 'payroll_period',
                self::COVERED_EMPLOYMENT,
                'Gültiger Beitragsgruppenschlüssel für den Abrechnungszeitraum.'
            ),
            '14' => self::entry(
                'contribution_collection_agency', 'Einzugsstelle', 'social_insurance', 'payroll_data', 'payroll_period',
                'Soweit eine Einzugsstelle für den Gesamtsozialversicherungsbeitrag zuständig ist.',
                'Zeitlich zugeordnetes Kennzeichen der zuständigen Einzugsstelle für den Gesamtsozialversicherungsbeitrag.'
            ),
            '15' => self::entry(
                'employee_contribution_shares', 'Beschäftigtenanteile am Sozialversicherungsbeitrag', 'payroll', 'payroll_data', 'payroll_period',
                'Soweit Beschäftigtenanteile geschuldet werden.',
                'Vom Beschäftigten getragene Anteile, nach Beitragsgruppen getrennt.'
            ),
            '16' => self::entry(
                'supplementary_reporting_data', 'Ergänzende Meldedaten', 'social_insurance', 'payroll_data', 'event',
                'Nur erforderliche Meldedaten, die in § 8 Abs. 1 S. 1 Nr. 1–14 fehlen.',
                'Erforderliche zusätzliche Eingabedaten und ihre Zuordnung zum Meldeverfahren.'
            ),
            '17' => self::entry(
                'posting_data', 'Angaben zur Entsendung', 'cross_border', 'master_data', 'event',
                'Nur bei einer einschlägigen Entsendung.',
                'Eigenart und zeitliche Begrenzung. Sachbezogener Nachweis nach Abs. 2 S. 1 Nr. 1.'
            ),
            '18' => self::entry(
                'short_time_work_payments', 'Kurzarbeitergeld', 'payroll', 'payroll_data', 'payroll_period',
                'Nur soweit Kurzarbeitergeld gezahlt wird.',
                'Gezahltes Kurzarbeitergeld und die darauf entfallenden beitragspflichtigen Einnahmen.'
            ),
            '19' => self::entry(
                'legacy_accident_insurance_value_credit', 'Historisches Wertguthaben in der Unfallversicherung', 'payroll', 'payroll_data', 'event',
                'Altfall mit Wertguthaben bis zum 31.12.2009 und noch geschuldeten Unfallversicherungsbeiträgen.',
                'Relevantes historisches Wertguthaben und Bezug zu den ausstehenden Beiträgen.'
            ),
        ];
    }

    private static function documentEntries(): array
    {
        return [
            '01-A' => self::entry(
                'third_country_residence_proof', 'Nachweis zu Staatsangehörigkeit und Aufenthaltstitel', 'cross_border', 'third_party_evidence', 'on_change',
                'Nur wenn die Angaben nach Abs. 1 S. 1 Nr. 3 erforderlich sind.',
                'Nachweis der erforderlichen Angaben. Zulässigen Umfang und geeignete Nachweisform fallbezogen bestimmen.'
            ),
            '01-B' => self::entry(
                'insurance_status_proof', 'Nachweis über Versicherungsfreiheit oder Befreiung', 'social_insurance', 'third_party_evidence', 'on_change',
                'Wenn Angaben zu Versicherungsfreiheit oder einer Befreiung nach Abs. 1 S. 1 Nr. 9 erforderlich sind.',
                'Unterlage, aus der der Grund und gegebenenfalls der Zeitraum hervorgehen, etwa ein einschlägiger Befreiungsbescheid.'
            ),
            '01-C' => self::entry(
                'student_enrollment_proof', 'Nachweis des Studierendenstatus', 'social_insurance', 'third_party_evidence', 'applicable_academic_term',
                'Nur wenn der Studierendenstatus die sozialversicherungsrechtliche Beurteilung tatsächlich trägt. Beschäftigungsumfang und Mehrfachbeschäftigung prüfen.',
                'Zeitlich einschlägiger Nachweis für die Angaben nach Abs. 1 S. 1 Nr. 9, etwa Immatrikulationsbescheinigung nach den Beispielen der Verfahrensbeschreibung zu § 9a BVV.'
            ),
            '01-D' => self::entry(
                'posting_scope_proof', 'Nachweis zu Art und Dauer der Entsendung', 'cross_border', 'document', 'event',
                'Wenn Angaben nach Abs. 1 S. 1 Nr. 17 erforderlich sind.',
                'Unterlagen zu Eigenart und zeitlicher Begrenzung der Beschäftigung. Weitere Bescheinigungsdaten gesondert nach Nr. 18 prüfen.'
            ),
            '02' => self::entry(
                'short_term_quota_work_authorization', 'Arbeitsberechtigung für kurzzeitige kontingentierte Beschäftigung', 'cross_border', 'third_party_evidence', 'event',
                'Beschäftigung nach § 15d BeschV. Die passende Berechtigung für den konkreten Fall prüfen.',
                'Arbeitserlaubnis der Bundesagentur für Arbeit oder Aufenthaltstitel nach § 19c Abs. 1 AufenthG in Verbindung mit § 15d BeschV.'
            ),
            '03' => self::entry(
                'employer_social_insurance_reports', 'Erstattete Meldungen', 'social_insurance', 'employer_report', 'event',
                'Je erstatteter einschlägiger Meldung.',
                'Vom Arbeitgeber erstattete Meldedaten einschließlich Bezug zu Korrektur oder Stornierung.'
            ),
            '03A' => self::entry(
                'health_insurance_incoming_reports', 'Beitragsrelevante Krankenkassenmeldungen', 'social_insurance', 'third_party_data', 'event',
                'Nur tatsächlich übermittelte Krankenkassenmeldungen, soweit sie die Beitragsberechnung beeinflussen.',
                'Von der Krankenkasse übermittelte Daten. Datensatz nach Gemeinsamen Grundsätzen Ziff. 2.1.2 zulässig.'
            ),
            '04' => self::entry(
                'minijob_legacy_insurance_waiver', 'Verzicht auf Rentenversicherungsfreiheit im Minijob-Altfall', 'social_insurance', 'employee_declaration', 'event',
                'Bei einschlägiger Versicherungsfreiheit in einer geringfügigen Beschäftigung und erklärtem Verzicht. Übergangsfall nach § 230 Abs. 8 SGB VI prüfen.',
                'Erklärung des Beschäftigten gegenüber dem Arbeitgeber.',
                'Schriftliche oder elektronische Erklärung gemäß § 230 Abs. 8 SGB VI. Konkrete Formanforderung zum Erklärungszeitpunkt prüfen.'
            ),
            '04A' => self::entry(
                'minijob_insurance_exemption_application', 'Antrag auf Rentenversicherungsbefreiung im Minijob', 'social_insurance', 'employee_declaration', 'event',
                'Nur bei einschlägigem Befreiungsantrag nach § 6 Abs. 1b SGB VI. Mehrfachbeschäftigung und Bindung gesondert prüfen.',
                'Befreiungsantrag mit dokumentiertem Eingangsdatum beim Arbeitgeber.',
                '§ 6 Abs. 1b S. 2 SGB VI erlaubt einen schriftlichen oder elektronischen Antrag. Konkretes Nachweisniveau mit Blick auf ältere Grundsätze und den Einzelfall prüfen.'
            ),
            '04B' => self::entry(
                'minijob_insurance_exemption_cancellation', 'Antrag auf Aufhebung der Rentenversicherungsbefreiung im Minijob', 'social_insurance', 'employee_declaration', 'event',
                'Seit 01.07.2026 einmaliger Aufhebungsantrag bei einschlägiger vorheriger Befreiung nach § 6 Abs. 1b SGB VI.',
                'Antrag nach § 6 Abs. 6 SGB VI mit dokumentiertem Eingangsdatum beim Arbeitgeber. Wirksamkeit gesondert prüfen.',
                '§ 6 Abs. 6 S. 2 in Verbindung mit Abs. 1b S. 2 SGB VI erlaubt einen schriftlichen oder elektronischen Antrag. Konkrete Formanforderung prüfen.'
            ),
            '06-A' => self::entry(
                'employment_terms_evidence', 'Nachweis wesentlicher Arbeitsbedingungen', 'employment', 'document', 'on_change',
                'Soweit ein Nachweis nach § 2 NachwG einschlägig ist. § 2a SchwarzArbG, insbesondere Baugewerbe, und zeitlichen Rechtsstand prüfen.',
                'Kopie der Niederschrift beziehungsweise des einschlägigen Nachweises nach § 2 NachwG.',
                '§ 2 NachwG differenziert nach Papierform und zulässiger elektronischer Textform mit Zugangsvoraussetzungen. Für Branchen nach § 2a SchwarzArbG gelten Sondervorgaben.'
            ),
            '06-B' => self::entry(
                'seafarer_employment_contract', 'Heuervertrag', 'sector_specific', 'document', 'on_change',
                'Nur Seefahrtbetriebe für einschlägige Beschäftigung.',
                'Kopie des Heuervertrags nach § 28 SeeArbG.'
            ),
            '07-A' => self::entry(
                'short_term_multiple_jobs_declaration', 'Erklärung zu weiteren kurzfristigen Beschäftigungen', 'social_insurance', 'employee_declaration', 'calendar_year_and_change',
                'Kurzfristig geringfügig Beschäftigte.',
                'Erklärung über weitere kurzfristige Beschäftigungen im Kalenderjahr und Bestätigung der Anzeigepflicht für neue Beschäftigungen.'
            ),
            '07-B' => self::entry(
                'low_pay_multiple_jobs_declaration', 'Erklärung zu weiteren Beschäftigungen im Minijob', 'social_insurance', 'employee_declaration', 'on_change',
                'Geringfügig entlohnte Beschäftigte.',
                'Erklärung über weitere Beschäftigungen und Bestätigung der Anzeigepflicht für neue Beschäftigungen.'
            ),
            '07A' => self::entry(
                'short_term_health_insurance_proof', 'Krankenversicherungsschutz bei kurzfristiger Beschäftigung', 'social_insurance', 'third_party_evidence', 'event',
                'Soweit der Krankenversicherungsschutz nach § 28a Abs. 9a SGB IV zu melden ist.',
                'Nachweis über bestehenden Krankenversicherungsschutz für den einschlägigen Beschäftigungszeitraum.'
            ),
            '08-A' => self::entry(
                'employment_status_application', 'Antrag auf Statusfeststellung und Entscheidungsunterlagen', 'social_insurance', 'document', 'event',
                'Bei einem einschlägigen Statusfeststellungsverfahren.',
                'Kopie des Antrags nach § 7a Abs. 1 SGB IV mit den für die Entscheidung benötigten Unterlagen.'
            ),
            '08-B' => self::entry(
                'employment_status_decision', 'Bescheid zur Statusfeststellung', 'social_insurance', 'third_party_evidence', 'event',
                'Wenn ein Bescheid nach § 7a Abs. 2 SGB IV ergeht.',
                'Bescheid der Deutschen Rentenversicherung Bund mit Verfahrensbezug.'
            ),
            '08-C' => self::entry(
                'employment_status_group_opinion', 'Gutachterliche Äußerung zum Erwerbsstatus', 'social_insurance', 'third_party_evidence', 'event',
                'Bei einer gutachterlichen Äußerung nach § 7a Abs. 4b SGB IV.',
                'Gutachterliche Äußerung der Deutschen Rentenversicherung Bund.'
            ),
            '08-D' => self::entry(
                'employment_status_group_opinion_distribution', 'Aushändigung der gutachterlichen Äußerung', 'social_insurance', 'employer_record', 'event',
                'Wenn Kopien einer gutachterlichen Äußerung nach § 7a Abs. 4b S. 4 SGB IV ausgehändigt werden.',
                'Dokumentation, welchen Auftragnehmern eine Kopie ausgehändigt wurde.'
            ),
            '09' => self::entry(
                'collection_agency_insurance_decision', 'Bescheid der Einzugsstelle zur Versicherungspflicht', 'social_insurance', 'third_party_evidence', 'event',
                'Wenn die Einzugsstelle nach § 28h Abs. 2 SGB IV über die Versicherungspflicht entscheidet.',
                'Bescheid der zuständigen Einzugsstelle.'
            ),
            '10' => self::entry(
                'tuition_tax_decision', 'Steuerentscheidung zu Studiengebühren', 'payroll', 'third_party_evidence', 'event',
                'Wenn der Arbeitgeber Studiengebühren trägt oder übernimmt und eine einschlägige Entscheidung der Finanzbehörden vorliegt.',
                'Entscheidung, dass die Studiengebühren steuerrechtlich keinen Arbeitslohn darstellen.'
            ),
            '11' => self::entry(
                'care_contribution_parenthood_evidence', 'Elterneigenschaft und Kinderzahl für die Pflegeversicherung', 'social_insurance', 'third_party_data', 'on_change',
                'Soweit Elterneigenschaft oder Kinderzahl für die Beitragsbemessung nach § 55 Abs. 3 SGB XI erheblich ist.',
                'Zweckgebundene Nachweise und Zeitraum. Ergebnisse des automatisierten Verfahrens nach § 55a SGB XI sowie ergänzende Nachweise fallbezogen berücksichtigen.'
            ),
            '12' => self::entry(
                'remuneration_payment_waiver', 'Erklärung über den Auszahlungsverzicht', 'payroll', 'employee_declaration', 'event',
                'Nur bei einem tatsächlich erklärten Auszahlungsverzicht auf zustehende Entgeltansprüche. Zulässigkeit separat prüfen.',
                'Kopie der Erklärung über den Auszahlungsverzicht.',
                'Schriftform in den Gemeinsamen Grundsätzen Ziff. 2.1.3 aufgeführt. Original oder zugelassene elektronische Form und gesetzliche Aufbewahrung gesondert prüfen.'
            ),
            '13-A' => self::entry(
                'minimum_wage_worktime_record', 'Arbeitszeitaufzeichnungen nach MiLoG', 'working_time', 'working_time_record', 'work_day',
                'Einschlägige Fälle nach § 17 Abs. 1 MiLoG. Branche, Geringfügigkeit, Entleiher, Haushaltsausnahme und einschlägige Verordnungen prüfen.',
                'Aufzeichnungen zu Beginn, Ende und Dauer der täglichen Arbeitszeit. Frist und abweichende Regeln nach MiLoG und Verordnungen prüfen.'
            ),
            '13-B' => self::entry(
                'posted_workers_worktime_record', 'Arbeitszeitaufzeichnungen nach AEntG', 'working_time', 'working_time_record', 'work_day',
                'Einschlägige Arbeitsbedingungen und Tätigkeiten nach § 19 Abs. 1 AEntG. Entleiher und einschlägige Verordnungen berücksichtigen.',
                'Aufzeichnungen zu Beginn, Ende und Dauer der täglichen Arbeitszeit. Bei einschlägigen stundenbezogenen Zuschlägen auch Zuschlagsart und zuschlagsbezogene Arbeitszeit.'
            ),
            '14' => self::entry(
                'care_support_allowance_certificate', 'Bescheinigung zum Pflegeunterstützungsgeld', 'social_insurance', 'third_party_evidence', 'event',
                'Nur wenn die Beschäftigung wegen Bezugs von Pflegeunterstützungsgeld unterbrochen wird.',
                'Bescheinigung nach § 44a Abs. 5 SGB XI. Erforderliche Bescheinigung zweckgebunden halten.'
            ),
            '15' => self::entry(
                'care_leave_declaration', 'Erklärung zur Inanspruchnahme von Pflegezeit', 'employment', 'employee_declaration', 'event',
                'Bei Inanspruchnahme von Pflegezeit nach § 3 PflegeZG.',
                'Kopie der Erklärung des Beschäftigten zur Inanspruchnahme von Pflegezeit.',
                'Aktueller § 3 Abs. 3 PflegeZG verlangt Textform. Frühere Erklärungen nach ihrer Rechtslage prüfen. Die Schriftform-Liste der Gemeinsamen Grundsätze 2022 ist insoweit älter.'
            ),
            '16-A' => self::entry(
                'maritime_crew_and_logbook', 'Besatzungslisten und Seetagebücher', 'sector_specific', 'employer_record', 'event',
                'Nur Seefahrtbetriebe.',
                'Besatzungslisten und Seetagebücher nach § 22 SeeArbG in Kopie.'
            ),
            '16-B' => self::entry(
                'inland_navigation_certificate', 'Schiffsattest', 'sector_specific', 'third_party_evidence', 'on_change',
                'Nur für einschlägige Binnenschiffe.',
                'Schiffsattest in Kopie.'
            ),
            '16-C' => self::entry(
                'rhine_navigation_certificate', 'Rheinschifffahrtszugehörigkeitsurkunde', 'sector_specific', 'third_party_evidence', 'on_change',
                'Nur für einschlägige Schiffe der Rheinschifffahrt.',
                'Rheinschifffahrtszugehörigkeitsurkunde in Kopie.'
            ),
            '18' => self::entry(
                'cross_border_social_insurance_certificate_data', 'Daten grenzüberschreitender Sozialversicherungsbescheinigungen', 'cross_border', 'third_party_data', 'event',
                'Wenn eine einschlägige Bescheinigung nach §§ 106 bis 106c SGB IV übermittelt wird.',
                'Übermittelte Bescheinigungsdaten mit Zuordnung zum Beschäftigungskontext und Geltungszeitraum.'
            ),
            '18A' => self::entry(
                'cross_border_exception_agreement_declaration', 'Erklärung zur sozialversicherungsrechtlichen Ausnahmevereinbarung', 'cross_border', 'employee_declaration', 'event',
                'Nur bei einem einschlägigen Antrag auf Ausnahmevereinbarung nach Art. 16 VO (EG) Nr. 883/2004.',
                'Erklärung des Beschäftigten, dass die Vereinbarung über die Geltung deutschen Rechts in seinem Interesse liegt.'
            ),
            '19' => self::entry(
                'statutory_retirement_insurance_waiver', 'Verzicht auf Rentenversicherungsfreiheit bei Altersbezug', 'social_insurance', 'employee_declaration', 'event',
                'Nur bei einschlägigem Verzicht nach § 5 Abs. 4 S. 2 oder § 230 Abs. 9 S. 2 SGB VI.',
                'Erklärung mit dokumentiertem Eingangsdatum beim Arbeitgeber.',
                'Die aktuellen Vorschriften des SGB VI erlauben eine schriftliche oder elektronische Erklärung. Konkretes Nachweisniveau und Rechtsstand der Erklärung prüfen.'
            ),
            '20' => self::entry(
                'teaching_transition_consent', 'Zustimmung zur Übergangsregelung für Lehrtätigkeiten', 'employment', 'employee_declaration', 'event',
                'Ausschließlich einschlägige Fälle des § 127 Abs. 1 SGB IV. Voraussetzungen und zeitlichen Rechtsstand prüfen.',
                'Zustimmung zum Zeitpunkt des Eintritts der Versicherungspflicht im Sinne von § 127 Abs. 1 S. 1 Nr. 2 oder S. 2 SGB IV.'
            ),
        ];
    }

    private static function additionalEntries(): array
    {
        return [
            'BVV-9A-2-1-WERTGUTHABEN' => [
                ...self::entry(
                    'value_credit_insolvency_proof', 'Insolvenzsicherung des Wertguthabens', 'payroll', 'third_party_evidence', 'on_change',
                    'Wenn der Insolvenzschutz eines Wertguthabens nach § 8 Abs. 1 S. 1 Nr. 7 BVV nachzuweisen ist. Geltungszeitraum einer bewilligten Befreiung nach § 8 Abs. 3 BVV prüfen.',
                    'Insolvenzschutznachweis elektronisch vorhalten. Die Grundsätze unterscheiden neue Tatbestände ab 01.01.2022 und Fälle mit bewilligter Befreiung bis Ende 2026.'
                ),
                'source' => 'BVV-9A-GG',
                'citation' => 'Gemeinsame Grundsätze nach § 9a BVV, Ziff. 2.1 und 3',
            ],
            'BVV-9-1' => [
                ...self::entry(
                    'contribution_statement', 'Beitragsabrechnungsverzeichnis', 'payroll', 'payroll_data', 'payroll_period',
                    'Arbeitgeberbezogenes Verzeichnis je Abrechnungszeitraum. Sonderfälle einschließlich Übergangsbereich und Fälle ohne Beitragszahlung beachten.',
                    'Elektronisches, lesbares Verzeichnis in Sortierfolge der Entgeltunterlagen, getrennt nach Einzugsstellen beziehungsweise bei Unfallversicherung nach Unternehmernummern. Summen, Berichtigungen und Stornierungen aus der Lohnabrechnung übernehmen.'
                ),
                'source' => 'BVV-9',
                'citation' => '§ 9 Abs. 1 bis 4 BVV',
            ],
            'BVV-9-5-ORIGINAL' => [
                ...self::entry(
                    'paper_source_evidence', 'Originale papiergebundener Entgeltunterlagen', 'employment', 'original_evidence', 'event',
                    'Wenn ein Dokument nach § 8 Abs. 2 BVV in Papierform übermittelt wurde. Andere einschlägige Aufbewahrungsvorschriften fallbezogen prüfen.',
                    'Papierdokument elektronisch umwandeln. Zuordnung von elektronischer Fassung und Papieroriginal nachweisen.',
                    'Formanforderung des jeweiligen Dokuments und Übertragung nach den einschlägigen Vorgaben separat beurteilen.'
                ),
                'source' => 'BVV-9',
                'citation' => '§ 9 Abs. 5 S. 3 und 4 BVV',
                'retention' => [
                    'binding_completion_of_social_audit',
                    null,
                    'Papieroriginal bis zum bestandskräftigen Abschluss der Betriebsprüfung oder nach den weiteren gesetzlichen Vorgaben für das Dokument aufbewahren.',
                ],
            ],
            'SGB-IV-28F-1' => [
                ...self::entry(
                    'payroll_record_calendar_year', 'Kalenderjahresgliederung der Entgeltunterlagen', 'payroll', 'employer_record', 'calendar_year',
                    self::COVERED_EMPLOYMENT,
                    'Entgeltunterlagen pro Person, nach Kalenderjahren getrennt und in deutscher Sprache geordnet führen. Beitragsabrechnungen und Beitragsnachweise entsprechend aufbewahren.'
                ),
                'source' => 'SGB-IV-28F',
                'citation' => '§ 28f Abs. 1 SGB IV',
            ],
            'SGB-IV-28F-1A' => [
                ...self::entry(
                    'contract_payroll_attribution', 'Zuordnung zum Dienst- oder Werkvertrag', 'payroll', 'payroll_data', 'contract_period',
                    'Dienst- oder Werkaufträge im Baugewerbe und die im Gesetz geregelten Kurier-, Express- und Paketdienste. Gesetzlichen Ruhenstatbestand für KEP prüfen.',
                    'Nachvollziehbare Zuordnung von Beschäftigten, Arbeitsentgelt und darauf entfallendem Sozialversicherungsbeitrag zum Auftrag.'
                ),
                'source' => 'SGB-IV-28F',
                'citation' => '§ 28f Abs. 1a SGB IV',
            ],
            'MILOG-17-1' => [
                ...self::entry(
                    'minimum_wage_worktime_record', 'Arbeitszeitaufzeichnungen nach MiLoG', 'working_time', 'working_time_record', 'work_day',
                    'Geringfügige Beschäftigungen nach § 8 Abs. 1 SGB IV, Branchen nach § 2a SchwarzArbG sowie erfasste Entleiher. § 8a SGB IV und einschlägige Verordnungen prüfen.',
                    'Täglichen Arbeitsbeginn, Arbeitsende und Arbeitsdauer spätestens am siebten folgenden Kalendertag aufzeichnen, vorbehaltlich einschlägiger Abweichungen.'
                ),
                'source' => 'MILOG-17',
                'citation' => '§ 17 Abs. 1 MiLoG',
                'retention' => [
                    'relevant_recording_date',
                    2,
                    'Mindestens zwei Jahre ab dem für die Aufzeichnung maßgeblichen Zeitpunkt. § 8 Abs. 2 S. 1 Nr. 13 BVV und andere Aufbewahrungspflichten gesondert prüfen.',
                ],
            ],
            'AENTG-19-1' => [
                ...self::entry(
                    'posted_workers_worktime_record', 'Arbeitszeitaufzeichnungen nach AEntG', 'working_time', 'working_time_record', 'work_day',
                    'Wenn nach § 16 AEntG kontrollierte Arbeitsbedingungen gelten. Einschlägige Entleiher und Verordnungen berücksichtigen.',
                    'Täglichen Arbeitsbeginn, Arbeitsende und Arbeitsdauer und bei einschlägigen Stundenzuschlägen auch Zuschlagsart und zuschlagsbezogene Arbeitszeit spätestens am siebten folgenden Kalendertag aufzeichnen.'
                ),
                'source' => 'AENTG-19',
                'citation' => '§ 19 Abs. 1 AEntG',
                'retention' => [
                    'relevant_recording_date',
                    2,
                    'Mindestens zwei Jahre ab dem für die Aufzeichnung maßgeblichen Zeitpunkt. § 8 Abs. 2 S. 1 Nr. 13 BVV und andere Aufbewahrungspflichten gesondert prüfen.',
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    private static function entry(
        string $type,
        string $label,
        string $category,
        string $kind,
        string $frequency,
        string $scope,
        string $evidence,
        ?string $signature = null
    ): array {
        $entry = compact('type', 'label', 'category', 'kind', 'frequency', 'scope', 'evidence');
        if ($signature !== null) {
            $entry['signature'] = $signature;
        }

        return $entry;
    }
}
