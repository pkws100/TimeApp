<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class CompanySettingsService
{
    private const SINGLETON_ID = 1;

    public function __construct(
        private DatabaseConnection $connection,
        private array $uploadConfig
    ) {
    }

    public function current(): array
    {
        if ($this->connection->tableExists('company_settings')) {
            $record = $this->connection->fetchOne(
                'SELECT * FROM company_settings WHERE id = :id LIMIT 1',
                ['id' => self::SINGLETON_ID]
            );

            if (is_array($record)) {
                return $record;
            }
        }

        return $this->defaults();
    }

    public function save(array $payload, array $files = []): array
    {
        $current = $this->current();
        $data = $this->normalize($payload, $current);
        $this->validate($data);

        if (!$this->connection->tableExists('company_settings')) {
            throw new RuntimeException('Die Settings-Tabelle ist noch nicht verfuegbar. Bitte zuerst die Migration ausfuehren.');
        }

        $this->ensureRowExists();
        $data = $this->applyUploads($data, $current, $files);

        $bindings = $this->databaseBindings($data);
        $assignments = [];

        foreach (array_keys($bindings) as $column) {
            if ($column === 'id') {
                continue;
            }

            $assignments[] = $column . ' = :' . $column;
        }

        $saved = $this->connection->execute(
            'UPDATE company_settings SET ' . implode(', ', $assignments) . ', updated_at = NOW() WHERE id = :id',
            $bindings
        );

        if (!$saved) {
            throw new RuntimeException('Die Settings konnten nicht gespeichert werden.');
        }

        return $this->current();
    }

    public function saveLogo(array $file): array
    {
        return $this->saveFileColumns(
            'logo',
            $file,
            'logo',
            ['png', 'jpg', 'jpeg', 'webp'],
            ['image/png', 'image/jpeg', 'image/webp']
        );
    }

    public function saveAgbText(string $text): array
    {
        return $this->saveTextColumn('agb_text', $text);
    }

    public function saveDatenschutzText(string $text): array
    {
        return $this->saveTextColumn('datenschutz_text', $text);
    }

    public function saveAgbPdf(array $file): array
    {
        return $this->saveFileColumns(
            'agb_pdf',
            $file,
            'agb',
            ['pdf'],
            ['application/pdf']
        );
    }

    public function saveDatenschutzPdf(array $file): array
    {
        return $this->saveFileColumns(
            'datenschutz_pdf',
            $file,
            'datenschutz',
            ['pdf'],
            ['application/pdf']
        );
    }

    public function saveSmtpSettings(array $payload): array
    {
        if (!$this->connection->tableExists('company_settings')) {
            throw new RuntimeException('Die Settings-Tabelle ist noch nicht verfuegbar. Bitte zuerst die Migration ausfuehren.');
        }

        $current = $this->current();
        $data = $this->normalizeSmtp($payload, $current);
        $this->validateSmtp($data);
        $this->ensureRowExists();

        $saved = $this->connection->execute(
            'UPDATE company_settings
             SET smtp_host = :smtp_host,
                 smtp_port = :smtp_port,
                 smtp_username = :smtp_username,
                 smtp_password = :smtp_password,
                 smtp_encryption = :smtp_encryption,
                 smtp_from_name = :smtp_from_name,
                 smtp_from_email = :smtp_from_email,
                 smtp_reply_to_email = :smtp_reply_to_email,
                 smtp_last_tested_at = NULL,
                 smtp_last_test_status = :smtp_last_test_status,
                 smtp_last_test_message = :smtp_last_test_message,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => self::SINGLETON_ID,
                ...$data,
                'smtp_last_test_status' => 'untested',
                'smtp_last_test_message' => 'SMTP-Konfiguration gespeichert. Bitte Test-E-Mail senden.',
            ]
        );

        if (!$saved) {
            throw new RuntimeException('Die SMTP-Settings konnten nicht gespeichert werden.');
        }

        return $this->current();
    }

    public function saveGeoSettings(array $payload): array
    {
        if (!$this->connection->tableExists('company_settings')) {
            throw new RuntimeException('Die Settings-Tabelle ist noch nicht verfuegbar. Bitte zuerst die Migration ausfuehren.');
        }

        $data = $this->normalizeGeo($payload, $this->current(), true);
        $this->validateGeo($data);
        $this->ensureRowExists();

        $saved = $this->connection->execute(
            'UPDATE company_settings
             SET geo_capture_enabled = :geo_capture_enabled,
                 geo_notice_text = :geo_notice_text,
                 geo_requires_acknowledgement = :geo_requires_acknowledgement,
                 geo_company_latitude = :geo_company_latitude,
                 geo_company_longitude = :geo_company_longitude,
                 geo_company_location_label = :geo_company_location_label,
                 geo_company_geocoded_at = :geo_company_geocoded_at,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => self::SINGLETON_ID,
                ...$data,
            ]
        );

        if (!$saved) {
            throw new RuntimeException('Die GEO-Settings konnten nicht gespeichert werden.');
        }

        return $this->current();
    }

    public function publicLogoUrl(): ?string
    {
        $logo = $this->publicLogoFile();

        if ($logo === null) {
            return null;
        }

        $version = rawurlencode((string) ($logo['stored_name'] ?? $logo['size_bytes'] ?? filemtime((string) $logo['path']) ?: 'logo'));

        return '/api/v1/settings/company/logo?v=' . $version;
    }

    public function publicLogoFile(): ?array
    {
        $settings = $this->current();
        $descriptor = $this->fileDescriptor($settings, 'logo');

        if ($descriptor === null) {
            return null;
        }

        $path = (string) ($descriptor['path'] ?? '');
        $mimeType = (string) ($descriptor['mime_type'] ?? '');

        if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        $root = rtrim((string) ($this->uploadConfig['root'] ?? storage_path('app/uploads')), '/');
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false || !is_file($realPath)) {
            return null;
        }

        $normalizedRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($realPath, $normalizedRoot)) {
            return null;
        }

        return [
            ...$descriptor,
            'path' => $realPath,
            'mime_type' => $mimeType,
        ];
    }

    public function recordSmtpTest(bool $ok, string $message): void
    {
        if (!$this->connection->tableExists('company_settings')) {
            return;
        }

        $this->ensureRowExists();

        $this->connection->execute(
            'UPDATE company_settings
             SET smtp_last_tested_at = NOW(),
                 smtp_last_test_status = :status,
                 smtp_last_test_message = :message,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => self::SINGLETON_ID,
                'status' => $ok ? 'success' : 'error',
                'message' => $message,
            ]
        );
    }

    public function publicProfile(): array
    {
        $settings = $this->current();

        return [
            'app_display_name' => $settings['app_display_name'] ?? '',
            'company_name' => $settings['company_name'] ?? '',
            'legal_form' => $settings['legal_form'] ?? '',
            'street' => $settings['street'] ?? '',
            'house_number' => $settings['house_number'] ?? '',
            'postal_code' => $settings['postal_code'] ?? '',
            'city' => $settings['city'] ?? '',
            'country' => $settings['country'] ?? '',
            'email' => $settings['email'] ?? '',
            'phone' => $settings['phone'] ?? '',
            'website' => $settings['website'] ?? '',
            'managing_director' => $settings['managing_director'] ?? '',
            'register_court' => $settings['register_court'] ?? '',
            'commercial_register' => $settings['commercial_register'] ?? '',
            'vat_id' => $settings['vat_id'] ?? '',
            'tax_number' => $settings['tax_number'] ?? '',
            'logo' => $this->publicFileDescriptor($settings, 'logo', $this->publicLogoUrl()),
            'agb_text' => $settings['agb_text'] ?? '',
            'agb_pdf' => $this->publicFileDescriptor($settings, 'agb_pdf'),
            'datenschutz_text' => $settings['datenschutz_text'] ?? '',
            'datenschutz_pdf' => $this->publicFileDescriptor($settings, 'datenschutz_pdf'),
            'geo_capture_enabled' => (bool) ($settings['geo_capture_enabled'] ?? false),
            'geo_notice_text' => $settings['geo_notice_text'] ?? '',
            'geo_requires_acknowledgement' => (bool) ($settings['geo_requires_acknowledgement'] ?? false),
        ];
    }

    public function hasSmtpConfiguration(): bool
    {
        $settings = $this->current();

        return trim((string) ($settings['smtp_host'] ?? '')) !== ''
            && trim((string) ($settings['smtp_from_email'] ?? '')) !== '';
    }

    private function ensureRowExists(): void
    {
        $saved = $this->connection->execute(
            'INSERT IGNORE INTO company_settings (id, company_name, country, smtp_port, smtp_encryption, smtp_last_test_status, geo_capture_enabled, geo_requires_acknowledgement, created_at, updated_at)
             VALUES (:id, :company_name, :country, :smtp_port, :smtp_encryption, :smtp_last_test_status, :geo_capture_enabled, :geo_requires_acknowledgement, NOW(), NOW())',
            [
                'id' => self::SINGLETON_ID,
                'company_name' => '',
                'country' => 'Deutschland',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_last_test_status' => 'untested',
                'geo_capture_enabled' => 0,
                'geo_requires_acknowledgement' => 0,
            ]
        );

        if (!$saved) {
            throw new RuntimeException('Die Settings konnten nicht vorbereitet werden.');
        }
    }

    private function normalize(array $payload, array $current): array
    {
        return [
            'app_display_name' => $this->nullableString($payload['app_display_name'] ?? null),
            'company_name' => trim((string) ($payload['company_name'] ?? '')),
            'legal_form' => $this->nullableString($payload['legal_form'] ?? null),
            'street' => $this->nullableString($payload['street'] ?? null),
            'house_number' => $this->nullableString($payload['house_number'] ?? null),
            'postal_code' => $this->nullableString($payload['postal_code'] ?? null),
            'city' => $this->nullableString($payload['city'] ?? null),
            'country' => $this->filledStringOrFallback($payload['country'] ?? null, 'Deutschland'),
            'email' => $this->nullableString($payload['email'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'website' => $this->nullableString($payload['website'] ?? null),
            'managing_director' => $this->nullableString($payload['managing_director'] ?? null),
            'register_court' => $this->nullableString($payload['register_court'] ?? null),
            'commercial_register' => $this->nullableString($payload['commercial_register'] ?? null),
            'vat_id' => $this->nullableString($payload['vat_id'] ?? null),
            'tax_number' => $this->nullableString($payload['tax_number'] ?? null),
            'agb_text' => array_key_exists('agb_text', $payload)
                ? trim((string) $payload['agb_text'])
                : ($current['agb_text'] ?? ''),
            'datenschutz_text' => array_key_exists('datenschutz_text', $payload)
                ? trim((string) $payload['datenschutz_text'])
                : ($current['datenschutz_text'] ?? ''),
            ...$this->normalizeSmtp($payload, $current),
            ...$this->normalizeGeo($payload, $current),
            'logo_original_name' => $current['logo_original_name'] ?? null,
            'logo_stored_name' => $current['logo_stored_name'] ?? null,
            'logo_mime_type' => $current['logo_mime_type'] ?? null,
            'logo_path' => $current['logo_path'] ?? null,
            'logo_size_bytes' => $current['logo_size_bytes'] ?? null,
            'agb_pdf_original_name' => $current['agb_pdf_original_name'] ?? null,
            'agb_pdf_stored_name' => $current['agb_pdf_stored_name'] ?? null,
            'agb_pdf_mime_type' => $current['agb_pdf_mime_type'] ?? null,
            'agb_pdf_path' => $current['agb_pdf_path'] ?? null,
            'agb_pdf_size_bytes' => $current['agb_pdf_size_bytes'] ?? null,
            'datenschutz_pdf_original_name' => $current['datenschutz_pdf_original_name'] ?? null,
            'datenschutz_pdf_stored_name' => $current['datenschutz_pdf_stored_name'] ?? null,
            'datenschutz_pdf_mime_type' => $current['datenschutz_pdf_mime_type'] ?? null,
            'datenschutz_pdf_path' => $current['datenschutz_pdf_path'] ?? null,
            'datenschutz_pdf_size_bytes' => $current['datenschutz_pdf_size_bytes'] ?? null,
            'smtp_last_test_status' => $current['smtp_last_test_status'] ?? 'untested',
            'smtp_last_test_message' => $current['smtp_last_test_message'] ?? null,
            'smtp_last_tested_at' => $current['smtp_last_tested_at'] ?? null,
        ];
    }

    private function normalizeSmtp(array $payload, array $current): array
    {
        $smtpPassword = trim((string) ($payload['smtp_password'] ?? ''));
        $smtpPort = (int) ($payload['smtp_port'] ?? ($current['smtp_port'] ?? 587));

        return [
            'smtp_host' => array_key_exists('smtp_host', $payload)
                ? $this->nullableString($payload['smtp_host'])
                : ($current['smtp_host'] ?? null),
            'smtp_port' => $smtpPort > 0 ? $smtpPort : 587,
            'smtp_username' => array_key_exists('smtp_username', $payload)
                ? $this->nullableString($payload['smtp_username'])
                : ($current['smtp_username'] ?? null),
            'smtp_password' => $smtpPassword !== '' ? $smtpPassword : ($current['smtp_password'] ?? null),
            'smtp_encryption' => array_key_exists('smtp_encryption', $payload)
                ? $this->filledStringOrFallback($payload['smtp_encryption'], 'tls')
                : (string) ($current['smtp_encryption'] ?? 'tls'),
            'smtp_from_name' => array_key_exists('smtp_from_name', $payload)
                ? $this->nullableString($payload['smtp_from_name'])
                : ($current['smtp_from_name'] ?? null),
            'smtp_from_email' => array_key_exists('smtp_from_email', $payload)
                ? $this->nullableString($payload['smtp_from_email'])
                : ($current['smtp_from_email'] ?? null),
            'smtp_reply_to_email' => array_key_exists('smtp_reply_to_email', $payload)
                ? $this->nullableString($payload['smtp_reply_to_email'])
                : ($current['smtp_reply_to_email'] ?? null),
        ];
    }

    private function normalizeGeo(array $payload, array $current, bool $targetedSave = false): array
    {
        $hasSubmittedLatitude = array_key_exists('geo_company_latitude', $payload);
        $hasSubmittedLongitude = array_key_exists('geo_company_longitude', $payload);
        $rawLatitude = trim((string) ($payload['geo_company_latitude'] ?? ''));
        $rawLongitude = trim((string) ($payload['geo_company_longitude'] ?? ''));

        if (($targetedSave || $hasSubmittedLatitude || $hasSubmittedLongitude)
            && (($rawLatitude === '') !== ($rawLongitude === ''))
        ) {
            throw new RuntimeException('Bitte fuer den Firmenstandort Latitude und Longitude gemeinsam angeben.');
        }

        $latitude = $this->nullableCoordinate($payload['geo_company_latitude'] ?? ($current['geo_company_latitude'] ?? null));
        $longitude = $this->nullableCoordinate($payload['geo_company_longitude'] ?? ($current['geo_company_longitude'] ?? null));
        $label = $this->nullableString($payload['geo_company_location_label'] ?? ($current['geo_company_location_label'] ?? null));

        if (($latitude === null || $longitude === null) && ($latitude !== $longitude)) {
            $latitude = null;
            $longitude = null;
            $label = null;
        }

        return [
            'geo_capture_enabled' => array_key_exists('geo_capture_enabled', $payload)
                ? ((string) $payload['geo_capture_enabled'] === '1' ? 1 : 0)
                : ($targetedSave ? 0 : (int) ($current['geo_capture_enabled'] ?? 0)),
            'geo_notice_text' => array_key_exists('geo_notice_text', $payload)
                ? trim((string) $payload['geo_notice_text'])
                : (string) ($current['geo_notice_text'] ?? ''),
            'geo_requires_acknowledgement' => array_key_exists('geo_requires_acknowledgement', $payload)
                ? ((string) $payload['geo_requires_acknowledgement'] === '1' ? 1 : 0)
                : ($targetedSave ? 0 : (int) ($current['geo_requires_acknowledgement'] ?? 0)),
            'geo_company_latitude' => $latitude,
            'geo_company_longitude' => $longitude,
            'geo_company_location_label' => $latitude !== null && $longitude !== null ? $label : null,
            'geo_company_geocoded_at' => $latitude === null || $longitude === null
                ? null
                : ($label !== null ? date('Y-m-d H:i:s') : ($current['geo_company_geocoded_at'] ?? null)),
        ];
    }

    private function applyUploads(array $data, array $current, array $files): array
    {
        $uploadMap = [
            'logo' => [
                'subdir' => 'logo',
                'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
                'mime_types' => ['image/png', 'image/jpeg', 'image/webp'],
                'prefix' => 'logo',
            ],
            'agb_pdf' => [
                'subdir' => 'agb',
                'extensions' => ['pdf'],
                'mime_types' => ['application/pdf'],
                'prefix' => 'agb_pdf',
            ],
            'datenschutz_pdf' => [
                'subdir' => 'datenschutz',
                'extensions' => ['pdf'],
                'mime_types' => ['application/pdf'],
                'prefix' => 'datenschutz_pdf',
            ],
        ];

        foreach ($uploadMap as $field => $rules) {
            $file = $files[$field] ?? null;

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $stored = $this->storeSettingsFile($file, $rules['subdir'], $rules['extensions'], $rules['mime_types']);

            $prefix = $rules['prefix'];
            $data[$prefix . '_original_name'] = $stored['original_name'];
            $data[$prefix . '_stored_name'] = $stored['stored_name'];
            $data[$prefix . '_mime_type'] = $stored['mime_type'];
            $data[$prefix . '_path'] = $stored['path'];
            $data[$prefix . '_size_bytes'] = $stored['size_bytes'];
        }

        return $data;
    }

    private function saveTextColumn(string $column, string $text): array
    {
        if (!$this->connection->tableExists('company_settings')) {
            throw new RuntimeException('Die Settings-Tabelle ist noch nicht verfuegbar. Bitte zuerst die Migration ausfuehren.');
        }

        if (!in_array($column, ['agb_text', 'datenschutz_text'], true)) {
            throw new RuntimeException('Ungueltiges Settings-Feld.');
        }

        $this->ensureRowExists();
        $saved = $this->connection->execute(
            'UPDATE company_settings SET ' . $column . ' = :value, updated_at = NOW() WHERE id = :id',
            [
                'id' => self::SINGLETON_ID,
                'value' => trim($text),
            ]
        );

        if (!$saved) {
            throw new RuntimeException('Die Settings konnten nicht gespeichert werden.');
        }

        return $this->current();
    }

    private function saveFileColumns(
        string $prefix,
        array $file,
        string $subdir,
        array $extensions,
        array $mimeTypes
    ): array {
        if (!$this->connection->tableExists('company_settings')) {
            throw new RuntimeException('Die Settings-Tabelle ist noch nicht verfuegbar. Bitte zuerst die Migration ausfuehren.');
        }

        if (!in_array($prefix, ['logo', 'agb_pdf', 'datenschutz_pdf'], true)) {
            throw new RuntimeException('Ungueltiges Settings-Dateifeld.');
        }

        $this->ensureRowExists();
        $stored = $this->storeSettingsFile($file, $subdir, $extensions, $mimeTypes);

        $saved = $this->connection->execute(
            'UPDATE company_settings
             SET ' . $prefix . '_original_name = :original_name,
                 ' . $prefix . '_stored_name = :stored_name,
                 ' . $prefix . '_mime_type = :mime_type,
                 ' . $prefix . '_path = :path,
                 ' . $prefix . '_size_bytes = :size_bytes,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => self::SINGLETON_ID,
                'original_name' => $stored['original_name'],
                'stored_name' => $stored['stored_name'],
                'mime_type' => $stored['mime_type'],
                'path' => $stored['path'],
                'size_bytes' => $stored['size_bytes'],
            ]
        );

        if (!$saved) {
            if (is_file((string) $stored['path'])) {
                @unlink((string) $stored['path']);
            }

            throw new RuntimeException('Die Datei wurde hochgeladen, konnte aber nicht in den Settings gespeichert werden.');
        }

        return $this->current();
    }

    private function validate(array $data): void
    {
        if (trim((string) ($data['company_name'] ?? '')) === '') {
            throw new RuntimeException('Bitte einen Firmennamen hinterlegen.');
        }

        foreach (['email'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Bitte gueltige E-Mail-Adressen in den Settings hinterlegen.');
            }
        }

        $this->validateSmtp($data);
        $this->validateGeo($data);
    }

    private function validateSmtp(array $data): void
    {
        foreach (['smtp_from_email', 'smtp_reply_to_email'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Bitte gueltige E-Mail-Adressen in den SMTP-Settings hinterlegen.');
            }
        }

        $smtpEncryption = (string) ($data['smtp_encryption'] ?? 'tls');

        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('Die SMTP-Verschluesselung ist ungueltig.');
        }

        $smtpPort = (int) ($data['smtp_port'] ?? 0);

        if ($smtpPort < 1 || $smtpPort > 65535) {
            throw new RuntimeException('Bitte einen gueltigen SMTP-Port zwischen 1 und 65535 angeben.');
        }
    }

    private function validateGeo(array $data): void
    {
        $latitude = $data['geo_company_latitude'] ?? null;
        $longitude = $data['geo_company_longitude'] ?? null;

        if (($latitude === null) !== ($longitude === null)) {
            throw new RuntimeException('Bitte fuer den Firmenstandort Latitude und Longitude gemeinsam angeben.');
        }

        if ($latitude !== null && ((float) $latitude < -90 || (float) $latitude > 90)) {
            throw new RuntimeException('Bitte eine gueltige Latitude zwischen -90 und 90 angeben.');
        }

        if ($longitude !== null && ((float) $longitude < -180 || (float) $longitude > 180)) {
            throw new RuntimeException('Bitte eine gueltige Longitude zwischen -180 und 180 angeben.');
        }
    }

    private function storeSettingsFile(array $file, string $subdir, array $extensions, array $mimeTypes): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Datei-Upload fehlgeschlagen.');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mimeType = $this->resolveMimeType($file, $mimeTypes);
        $size = (int) ($file['size'] ?? 0);

        if (!in_array($extension, $extensions, true)) {
            throw new RuntimeException('Dateityp ist fuer dieses Feld nicht erlaubt.');
        }

        if (!in_array($mimeType, $mimeTypes, true)) {
            throw new RuntimeException('MIME-Typ ist fuer dieses Feld nicht erlaubt.');
        }

        $maxFilesize = (int) ($this->uploadConfig['max_filesize'] ?? 0);

        if ($maxFilesize > 0 && $size > $maxFilesize) {
            throw new RuntimeException('Datei ist zu gross.');
        }

        $root = rtrim((string) ($this->uploadConfig['root'] ?? storage_path('app/uploads')), '/');
        $directory = $this->ensureUploadDirectory($root, $subdir);

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '-', strtolower((string) $file['name'])) ?: 'upload.bin';
        $storedName = uniqid('settings_', true) . '-' . $safeName;
        $targetPath = $directory . '/' . $storedName;

        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $targetPath)
            : rename((string) $file['tmp_name'], $targetPath);

        if (!$moved) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        return [
            'original_name' => (string) $file['name'],
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'path' => $targetPath,
            'size_bytes' => $size,
        ];
    }

    private function ensureUploadDirectory(string $root, string $subdir): string
    {
        if ($root === '') {
            throw new RuntimeException('Das Upload-Verzeichnis ist nicht konfiguriert.');
        }

        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Das Upload-Basisverzeichnis konnte nicht angelegt werden.');
        }

        if (!is_writable($root)) {
            throw new RuntimeException('Das Upload-Basisverzeichnis ist nicht beschreibbar.');
        }

        $directory = $root . '/settings/company/' . $subdir;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Das Upload-Verzeichnis konnte nicht angelegt werden.');
        }

        if (!is_writable($directory)) {
            throw new RuntimeException('Das Upload-Verzeichnis ist nicht beschreibbar.');
        }

        return $directory;
    }

    private function resolveMimeType(array $file, array $allowedMimeTypes): string
    {
        $reportedMimeType = trim((string) ($file['type'] ?? ''));
        $detectedMimeType = null;
        $tmpName = trim((string) ($file['tmp_name'] ?? ''));

        if ($tmpName !== '' && is_file($tmpName) && function_exists('finfo_open')) {
            $resource = finfo_open(FILEINFO_MIME_TYPE);

            if ($resource !== false) {
                $detected = finfo_file($resource, $tmpName);
                finfo_close($resource);

                if (is_string($detected) && trim($detected) !== '') {
                    $detectedMimeType = trim($detected);
                }
            }
        }

        if ($detectedMimeType !== null) {
            return $detectedMimeType;
        }

        if ($reportedMimeType !== '' && in_array($reportedMimeType, $allowedMimeTypes, true)) {
            return $reportedMimeType;
        }

        return $detectedMimeType ?? $reportedMimeType ?: 'application/octet-stream';
    }

    private function databaseBindings(array $data): array
    {
        return ['id' => self::SINGLETON_ID, ...$data];
    }

    private function fileDescriptor(array $settings, string $prefix): ?array
    {
        $path = $settings[$prefix . '_path'] ?? null;

        if ($path === null || $path === '') {
            return null;
        }

        return [
            'original_name' => $settings[$prefix . '_original_name'] ?? null,
            'stored_name' => $settings[$prefix . '_stored_name'] ?? null,
            'mime_type' => $settings[$prefix . '_mime_type'] ?? null,
            'path' => $path,
            'size_bytes' => $settings[$prefix . '_size_bytes'] ?? null,
        ];
    }

    private function publicFileDescriptor(array $settings, string $prefix, ?string $url = null): ?array
    {
        $descriptor = $this->fileDescriptor($settings, $prefix);

        if ($descriptor === null) {
            return null;
        }

        return [
            'original_name' => $descriptor['original_name'] ?? null,
            'mime_type' => $descriptor['mime_type'] ?? null,
            'size_bytes' => $descriptor['size_bytes'] ?? null,
            'url' => $url,
        ];
    }

    private function defaults(): array
    {
        return [
            'id' => self::SINGLETON_ID,
            'app_display_name' => '',
            'company_name' => '',
            'legal_form' => '',
            'street' => '',
            'house_number' => '',
            'postal_code' => '',
            'city' => '',
            'country' => 'Deutschland',
            'email' => '',
            'phone' => '',
            'website' => '',
            'managing_director' => '',
            'register_court' => '',
            'commercial_register' => '',
            'vat_id' => '',
            'tax_number' => '',
            'logo_original_name' => null,
            'logo_stored_name' => null,
            'logo_mime_type' => null,
            'logo_path' => null,
            'logo_size_bytes' => null,
            'agb_text' => '',
            'agb_pdf_original_name' => null,
            'agb_pdf_stored_name' => null,
            'agb_pdf_mime_type' => null,
            'agb_pdf_path' => null,
            'agb_pdf_size_bytes' => null,
            'datenschutz_text' => '',
            'datenschutz_pdf_original_name' => null,
            'datenschutz_pdf_stored_name' => null,
            'datenschutz_pdf_mime_type' => null,
            'datenschutz_pdf_path' => null,
            'datenschutz_pdf_size_bytes' => null,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'smtp_from_name' => '',
            'smtp_from_email' => '',
            'smtp_reply_to_email' => '',
            'smtp_last_tested_at' => null,
            'smtp_last_test_status' => 'untested',
            'smtp_last_test_message' => null,
            'geo_capture_enabled' => 0,
            'geo_notice_text' => '',
            'geo_requires_acknowledgement' => 0,
            'geo_company_latitude' => null,
            'geo_company_longitude' => null,
            'geo_company_location_label' => null,
            'geo_company_geocoded_at' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableCoordinate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException('Bitte gueltige numerische Koordinaten fuer den Firmenstandort angeben.');
        }

        return number_format((float) $value, 7, '.', '');
    }

    private function filledStringOrFallback(mixed $value, string $fallback): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? $fallback : $value;
    }
}
