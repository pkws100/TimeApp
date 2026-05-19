<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class FileAttachmentService
{
    private const IMAGE_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ];

    public function __construct(private DatabaseConnection $connection, private array $config)
    {
    }

    public function listForProject(int $projectId, string $scope = 'active'): array
    {
        return $this->publicFiles($this->listByOwner('project_files', 'project_id', $projectId, $scope), 'project');
    }

    public function listForAsset(int $assetId, string $scope = 'active'): array
    {
        return $this->publicFiles($this->listByOwner('asset_files', 'asset_id', $assetId, $scope), 'asset');
    }

    public function listForTimesheet(int $timesheetId, string $scope = 'active'): array
    {
        return $this->publicFiles($this->listByOwner('timesheet_files', 'timesheet_id', $timesheetId, $scope), 'timesheet');
    }

    public function listForTimesheetsGrouped(array $timesheetIds, string $scope = 'active'): array
    {
        $timesheetIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $timesheetIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($timesheetIds === [] || !$this->connection->tableExists('timesheet_files')) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($timesheetIds as $index => $timesheetId) {
            $placeholder = 'id_' . $index;
            $placeholders[] = ':' . $placeholder;
            $bindings[$placeholder] = $timesheetId;
        }

        $rows = $this->connection->fetchAll(
            'SELECT timesheet_id, ' . $this->fileSelectColumns('timesheet_files') . '
             FROM timesheet_files
             ' . $this->documentStatusJoin('timesheet_files') . '
             WHERE timesheet_id IN (' . implode(', ', $placeholders) . ')
               AND ' . $this->scopeWhereClause($scope, 'timesheet_files') . '
             ORDER BY timesheet_files.timesheet_id ASC, timesheet_files.is_deleted ASC, timesheet_files.uploaded_at DESC',
            $bindings
        );

        $grouped = [];

        foreach ($rows as $row) {
            $timesheetId = (int) ($row['timesheet_id'] ?? 0);

            if ($timesheetId <= 0) {
                continue;
            }

            $grouped[$timesheetId][] = $this->publicFile($row, 'timesheet');
        }

        return $grouped;
    }

    public function listForTimesheetAdmin(int $timesheetId, string $scope = 'all'): array
    {
        return array_map(
            fn (array $file): array => $this->adminTimesheetFile($file),
            $this->listByOwner('timesheet_files', 'timesheet_id', $timesheetId, $scope)
        );
    }

    public function findProjectFile(int $fileId): ?array
    {
        return $this->findFile('project_files', $fileId);
    }

    public function findAssetFile(int $fileId): ?array
    {
        return $this->findFile('asset_files', $fileId);
    }

    public function findTimesheetFile(int $fileId): ?array
    {
        return $this->findFile('timesheet_files', $fileId);
    }

    public function storeProject(array $file, int $projectId, ?int $userId = null): array
    {
        return $this->storeGeneric('project_files', 'project_id', $projectId, 'project-' . $projectId, $file, $userId);
    }

    public function storeAsset(array $file, int $assetId, ?int $userId = null): array
    {
        return $this->storeGeneric('asset_files', 'asset_id', $assetId, 'asset-' . $assetId, $file, $userId);
    }

    public function storeTimesheet(array $file, int $timesheetId, ?int $userId = null): array
    {
        return $this->storeGeneric('timesheet_files', 'timesheet_id', $timesheetId, 'timesheet-' . $timesheetId, $file, $userId);
    }

    public function archiveProjectFile(int $fileId, ?int $deletedByUserId = null): bool
    {
        return $this->archiveFile('project_files', $fileId, $deletedByUserId);
    }

    public function archiveAssetFile(int $fileId, ?int $deletedByUserId = null): bool
    {
        return $this->archiveFile('asset_files', $fileId, $deletedByUserId);
    }

    public function archiveTimesheetFile(int $fileId, ?int $deletedByUserId = null): bool
    {
        return $this->archiveFile('timesheet_files', $fileId, $deletedByUserId);
    }

    public function updateProjectFileStatus(int $fileId, ?int $statusId): bool
    {
        return $this->updateFileStatus('project_files', $fileId, $statusId);
    }

    public function updateAssetFileStatus(int $fileId, ?int $statusId): bool
    {
        return $this->updateFileStatus('asset_files', $fileId, $statusId);
    }

    public function updateTimesheetFileStatus(int $fileId, ?int $statusId): bool
    {
        return $this->updateFileStatus('timesheet_files', $fileId, $statusId);
    }

    public function downloadableProjectFile(int $fileId): ?array
    {
        $file = $this->findProjectFile($fileId);

        return $file === null ? null : $this->downloadableFile($file);
    }

    public function downloadableTimesheetFile(int $fileId): ?array
    {
        $file = $this->findTimesheetFile($fileId);

        return $file === null ? null : $this->downloadableFile($file);
    }

    public function timesheetBelongsToUser(int $timesheetId, int $userId): bool
    {
        if (!$this->connection->tableExists('timesheets')) {
            return false;
        }

        $count = $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM timesheets WHERE id = :id AND user_id = :user_id LIMIT 1',
            [
                'id' => $timesheetId,
                'user_id' => $userId,
            ]
        );

        return (int) ($count ?? 0) > 0;
    }

    public function timesheetFileBelongsToUser(int $fileId, int $userId): bool
    {
        if (!$this->connection->tableExists('timesheet_files') || !$this->connection->tableExists('timesheets')) {
            return false;
        }

        $count = $this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM timesheet_files
             INNER JOIN timesheets ON timesheets.id = timesheet_files.timesheet_id
             WHERE timesheet_files.id = :id
               AND timesheets.user_id = :user_id
             LIMIT 1',
            [
                'id' => $fileId,
                'user_id' => $userId,
            ]
        );

        return (int) ($count ?? 0) > 0;
    }

    public function projectBelongsToUser(int $projectId, int $userId): bool
    {
        if (
            $projectId <= 0
            || $userId <= 0
            || !$this->connection->tableExists('projects')
            || !$this->connection->tableExists('project_memberships')
        ) {
            return false;
        }

        $count = $this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM project_memberships
             INNER JOIN projects ON projects.id = project_memberships.project_id
             WHERE project_memberships.project_id = :project_id
               AND project_memberships.user_id = :user_id
               AND COALESCE(projects.is_deleted, 0) = 0
               AND projects.status <> "archived"
               AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= CURDATE())
               AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= CURDATE())
             LIMIT 1',
            [
                'project_id' => $projectId,
                'user_id' => $userId,
            ]
        );

        return (int) ($count ?? 0) > 0;
    }

    public function projectFileBelongsToUserProject(int $fileId, int $userId): bool
    {
        if (
            $fileId <= 0
            || $userId <= 0
            || !$this->connection->tableExists('project_files')
            || !$this->connection->tableExists('projects')
            || !$this->connection->tableExists('project_memberships')
        ) {
            return false;
        }

        $count = $this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM project_files
             INNER JOIN projects ON projects.id = project_files.project_id
             INNER JOIN project_memberships ON project_memberships.project_id = projects.id
             WHERE project_files.id = :id
               AND project_memberships.user_id = :user_id
               AND COALESCE(project_files.is_deleted, 0) = 0
               AND COALESCE(projects.is_deleted, 0) = 0
               AND projects.status <> "archived"
               AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= CURDATE())
               AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= CURDATE())
             LIMIT 1',
            [
                'id' => $fileId,
                'user_id' => $userId,
            ]
        );

        return (int) ($count ?? 0) > 0;
    }

    private function listByOwner(string $table, string $ownerColumn, int $ownerId, string $scope): array
    {
        if (!$this->connection->tableExists($table)) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT ' . $this->fileSelectColumns($table) . '
             FROM ' . $table . '
             ' . $this->documentStatusJoin($table) . '
             WHERE ' . $ownerColumn . ' = :owner_id
               AND ' . $this->scopeWhereClause($scope, $table) . '
             ORDER BY ' . $table . '.is_deleted ASC, ' . $table . '.uploaded_at DESC',
            ['owner_id' => $ownerId]
        );
    }

    private function findFile(string $table, int $fileId): ?array
    {
        if (!$this->connection->tableExists($table)) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM ' . $table . ' WHERE id = :id LIMIT 1',
            ['id' => $fileId]
        );
    }

    private function storeGeneric(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $subDirectory,
        array $file,
        ?int $userId = null
    ): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Datei-Upload fehlgeschlagen.');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mimeType = $this->resolveMimeType($file);
        $mimeType = $this->normalizeMimeTypeForExtension($mimeType, $extension);
        $size = (int) ($file['size'] ?? 0);

        if (!in_array($extension, $this->config['allowed_extensions'] ?? [], true)) {
            throw new RuntimeException('Dateityp ist nicht freigegeben.');
        }

        if (!in_array($mimeType, $this->config['allowed_mime_types'] ?? [], true)) {
            throw new RuntimeException('MIME-Typ ist nicht freigegeben.');
        }

        if ($size > (int) ($this->config['max_filesize'] ?? 0)) {
            throw new RuntimeException('Datei ist zu gross.');
        }

        $root = (string) ($this->config['root'] ?? storage_path('app/uploads'));
        $targetDirectory = rtrim($root, '/') . '/' . date('Y/m') . '/' . $subDirectory;

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht angelegt werden.');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '-', strtolower((string) $file['name'])) ?: 'upload.bin';
        $storedName = uniqid('file_', true) . '-' . $safeName;
        $targetPath = $targetDirectory . '/' . $storedName;

        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? move_uploaded_file((string) $file['tmp_name'], $targetPath)
            : rename((string) $file['tmp_name'], $targetPath);

        if (!$moved) {
            throw new RuntimeException('Datei konnte nicht gespeichert werden.');
        }

        $this->normalizeImageOrientation($targetPath, $mimeType);
        clearstatcache(true, $targetPath);
        $size = is_file($targetPath) ? (int) filesize($targetPath) : $size;

        $documentStatusId = $this->defaultDocumentStatusId();
        $bindings = [
            'owner_id' => $ownerId,
            'original_name' => (string) $file['name'],
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'storage_path' => $targetPath,
            'uploaded_by_user_id' => $userId,
            'document_status_id' => $documentStatusId,
        ];

        if ($this->connection->tableExists($table)) {
            $hasStatusColumn = $this->connection->columnExists($table, 'document_status_id');
            $columns = [
                $ownerColumn,
                'original_name',
                'stored_name',
                'mime_type',
                'size_bytes',
                'storage_path',
                'uploaded_by_user_id',
            ];
            $placeholders = [
                ':owner_id',
                ':original_name',
                ':stored_name',
                ':mime_type',
                ':size_bytes',
                ':storage_path',
                ':uploaded_by_user_id',
            ];

            if ($hasStatusColumn) {
                $columns[] = 'document_status_id';
                $placeholders[] = ':document_status_id';
            } else {
                unset($bindings['document_status_id']);
            }

            $columns = array_merge($columns, ['uploaded_at', 'is_deleted', 'deleted_at', 'deleted_by_user_id']);
            $placeholders = array_merge($placeholders, ['NOW()', '0', 'NULL', 'NULL']);

            $this->connection->execute(
                'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
                $bindings
            );
        }

        $stored = [
            'id' => $this->connection->lastInsertId(),
            $ownerColumn => $ownerId,
            'original_name' => $bindings['original_name'],
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'uploaded_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'document_status_id' => $documentStatusId,
        ];

        return $this->publicFile($stored, $this->fileTypeForTable($table));
    }

    private function resolveMimeType(array $file): string
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

        $allowedMimeTypes = $this->config['allowed_mime_types'] ?? [];

        if ($detectedMimeType !== null && in_array($detectedMimeType, $allowedMimeTypes, true)) {
            return $detectedMimeType;
        }

        if ($detectedMimeType !== null && !in_array($detectedMimeType, ['application/octet-stream', 'binary/octet-stream'], true)) {
            return $detectedMimeType;
        }

        if ($reportedMimeType !== '' && in_array($reportedMimeType, $allowedMimeTypes, true)) {
            return $reportedMimeType;
        }

        return $detectedMimeType ?? $reportedMimeType ?: 'application/octet-stream';
    }

    private function normalizeMimeTypeForExtension(string $mimeType, string $extension): string
    {
        if ($mimeType !== 'application/zip') {
            return $mimeType;
        }

        return match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => $mimeType,
        };
    }

    private function archiveFile(string $table, int $fileId, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists($table)) {
            return true;
        }

        return $this->connection->execute(
            'UPDATE ' . $table . ' SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id WHERE id = :id',
            ['id' => $fileId, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    private function updateFileStatus(string $table, int $fileId, ?int $statusId): bool
    {
        if (!$this->connection->tableExists($table) || !$this->connection->columnExists($table, 'document_status_id')) {
            return false;
        }

        if ($statusId !== null && $statusId > 0 && !$this->documentStatusExists($statusId)) {
            throw new RuntimeException('Der gewaehlte Dokumentstatus ist nicht aktiv.');
        }

        return $this->connection->execute(
            'UPDATE ' . $table . ' SET document_status_id = :status_id WHERE id = :id AND is_deleted = 0',
            [
                'id' => $fileId,
                'status_id' => $statusId !== null && $statusId > 0 ? $statusId : null,
            ]
        );
    }

    private function scopeWhereClause(string $scope, ?string $table = null): string
    {
        $prefix = $table !== null && $table !== '' ? $table . '.' : '';

        return match ($scope) {
            'archived' => $prefix . 'is_deleted = 1',
            'all' => '1 = 1',
            default => $prefix . 'is_deleted = 0',
        };
    }

    private function publicFiles(array $files, string $type): array
    {
        return array_map(fn (array $file): array => $this->publicFile($file, $type), $files);
    }

    private function publicFile(array $file, string $type): array
    {
        $fileId = (int) ($file['id'] ?? 0);
        $mimeType = (string) ($file['mime_type'] ?? '');
        $downloadUrl = $this->downloadUrl($type, $fileId);
        $isImage = $this->isImageMimeType($mimeType);

        return [
            'id' => $fileId,
            'original_name' => (string) ($file['original_name'] ?? ''),
            'mime_type' => $mimeType,
            'size_bytes' => (int) ($file['size_bytes'] ?? 0),
            'uploaded_at' => $file['uploaded_at'] ?? null,
            'is_deleted' => (int) ($file['is_deleted'] ?? 0),
            'deleted_at' => $file['deleted_at'] ?? null,
            'is_image' => $isImage,
            'download_url' => $downloadUrl,
            'preview_url' => $isImage ? $downloadUrl : null,
            'document_status' => $this->documentStatusFromRow($file),
        ];
    }

    private function adminTimesheetFile(array $file): array
    {
        $fileId = (int) ($file['id'] ?? 0);
        $mimeType = (string) ($file['mime_type'] ?? '');
        $isDeleted = (int) ($file['is_deleted'] ?? 0) === 1;
        $downloadUrl = (!$isDeleted && $fileId > 0) ? '/admin/timesheet-files/' . $fileId . '/download' : null;
        $isImage = $this->isImageMimeType($mimeType);
        $isPreviewable = $isImage || $mimeType === 'application/pdf';

        return [
            'id' => $fileId,
            'original_name' => (string) ($file['original_name'] ?? ''),
            'mime_type' => $mimeType,
            'size_bytes' => (int) ($file['size_bytes'] ?? 0),
            'uploaded_at' => $file['uploaded_at'] ?? null,
            'is_deleted' => $isDeleted ? 1 : 0,
            'deleted_at' => $file['deleted_at'] ?? null,
            'is_image' => $isImage,
            'is_previewable' => $isPreviewable,
            'download_url' => $downloadUrl,
            'preview_url' => $isPreviewable ? $downloadUrl : null,
            'archive_url' => $fileId > 0 ? '/admin/timesheet-files/' . $fileId : null,
            'status_update_url' => $fileId > 0 ? '/admin/timesheet-files/' . $fileId . '/status' : null,
            'document_status' => $this->documentStatusFromRow($file),
        ];
    }

    private function downloadableFile(array $file): ?array
    {
        if ((int) ($file['is_deleted'] ?? 0) === 1) {
            return null;
        }

        $path = (string) ($file['storage_path'] ?? '');
        $root = rtrim((string) ($this->config['root'] ?? storage_path('app/uploads')), '/');
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
            'path' => $realPath,
            'original_name' => (string) ($file['original_name'] ?? 'download.bin'),
            'mime_type' => (string) ($file['mime_type'] ?? 'application/octet-stream'),
            'size_bytes' => (int) filesize($realPath),
            'is_image' => $this->isImageMimeType((string) ($file['mime_type'] ?? '')),
        ];
    }

    private function normalizeImageOrientation(string $path, string $mimeType): void
    {
        if ($mimeType !== 'image/jpeg' || !is_file($path)) {
            return;
        }

        if (
            !function_exists('exif_read_data')
            || !function_exists('imagecreatefromjpeg')
            || !function_exists('imagerotate')
            || !function_exists('imagejpeg')
        ) {
            return;
        }

        $exif = @exif_read_data($path);

        if (!is_array($exif)) {
            return;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);
        $degrees = match ($orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return;
        }

        $dimensions = @getimagesize($path);

        if (is_array($dimensions)) {
            $pixels = (int) ($dimensions[0] ?? 0) * (int) ($dimensions[1] ?? 0);
            $maxPixels = (int) ($this->config['max_image_pixels'] ?? 40000000);

            if ($pixels <= 0 || $pixels > $maxPixels) {
                return;
            }
        }

        $image = @imagecreatefromjpeg($path);

        if (!$image instanceof \GdImage) {
            return;
        }

        $rotated = @imagerotate($image, $degrees, 0);
        imagedestroy($image);

        if (!$rotated instanceof \GdImage) {
            return;
        }

        $saved = @imagejpeg($rotated, $path, 90);
        imagedestroy($rotated);

        if (!$saved) {
            throw new RuntimeException('Bild konnte nicht normalisiert werden.');
        }
    }

    private function downloadUrl(string $type, int $fileId): ?string
    {
        if ($fileId <= 0) {
            return null;
        }

        return match ($type) {
            'project' => '/api/v1/app/project-files/' . $fileId . '/download',
            'timesheet' => '/api/v1/app/timesheet-files/' . $fileId . '/download',
            default => null,
        };
    }

    private function fileTypeForTable(string $table): string
    {
        return match ($table) {
            'project_files' => 'project',
            'timesheet_files' => 'timesheet',
            default => 'asset',
        };
    }

    private function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::IMAGE_MIME_TYPES, true);
    }

    private function fileSelectColumns(string $table): string
    {
        $columns = [
            $table . '.id',
            $table . '.original_name',
            $table . '.stored_name',
            $table . '.mime_type',
            $table . '.size_bytes',
            $table . '.storage_path',
            $table . '.uploaded_at',
            $table . '.is_deleted',
            $table . '.deleted_at',
        ];

        if ($this->connection->columnExists($table, 'document_status_id')) {
            $columns[] = $table . '.document_status_id';
        }

        if ($this->connection->tableExists('document_status_profiles') && $this->connection->columnExists($table, 'document_status_id')) {
            $columns[] = 'document_status_profiles.label AS document_status_label';
            $columns[] = 'document_status_profiles.slug AS document_status_slug';
            $columns[] = 'document_status_profiles.color AS document_status_color';
            $columns[] = 'document_status_profiles.is_default AS document_status_is_default';
            $columns[] = 'document_status_profiles.is_deleted AS document_status_is_deleted';
        }

        return implode(', ', $columns);
    }

    private function documentStatusJoin(string $table): string
    {
        if (!$this->connection->tableExists('document_status_profiles') || !$this->connection->columnExists($table, 'document_status_id')) {
            return '';
        }

        return 'LEFT JOIN document_status_profiles ON document_status_profiles.id = ' . $table . '.document_status_id';
    }

    private function defaultDocumentStatusId(): ?int
    {
        if (!$this->connection->tableExists('document_status_profiles')) {
            return null;
        }

        $id = $this->connection->fetchColumn(
            'SELECT id
             FROM document_status_profiles
             WHERE is_deleted = 0
             ORDER BY is_default DESC, sort_order ASC, id ASC
             LIMIT 1'
        );

        return is_numeric($id) ? (int) $id : null;
    }

    private function documentStatusExists(int $statusId): bool
    {
        if (!$this->connection->tableExists('document_status_profiles')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM document_status_profiles WHERE id = :id AND is_deleted = 0',
            ['id' => $statusId]
        ) ?? 0) > 0;
    }

    private function documentStatusFromRow(array $file): ?array
    {
        $statusId = (int) ($file['document_status_id'] ?? 0);

        if ($statusId <= 0) {
            return null;
        }

        return [
            'id' => $statusId,
            'label' => (string) ($file['document_status_label'] ?? 'Unbearbeitet'),
            'slug' => (string) ($file['document_status_slug'] ?? ''),
            'color' => (string) ($file['document_status_color'] ?? '#64748b'),
            'is_default' => (int) ($file['document_status_is_default'] ?? 0) === 1,
            'is_deleted' => (int) ($file['document_status_is_deleted'] ?? 0),
        ];
    }
}
