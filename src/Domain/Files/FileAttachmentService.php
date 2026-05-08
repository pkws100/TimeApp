<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class FileAttachmentService
{
    public function __construct(private DatabaseConnection $connection, private array $config)
    {
    }

    public function listForProject(int $projectId, string $scope = 'active'): array
    {
        return $this->listByOwner('project_files', 'project_id', $projectId, $scope);
    }

    public function listForAsset(int $assetId, string $scope = 'active'): array
    {
        return $this->listByOwner('asset_files', 'asset_id', $assetId, $scope);
    }

    public function listForTimesheet(int $timesheetId, string $scope = 'active'): array
    {
        return $this->listByOwner('timesheet_files', 'timesheet_id', $timesheetId, $scope);
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

    private function listByOwner(string $table, string $ownerColumn, int $ownerId, string $scope): array
    {
        if (!$this->connection->tableExists($table)) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT id, original_name, stored_name, mime_type, size_bytes, storage_path, uploaded_at, is_deleted, deleted_at
             FROM ' . $table . '
             WHERE ' . $ownerColumn . ' = :owner_id
               AND ' . $this->scopeWhereClause($scope) . '
             ORDER BY is_deleted ASC, uploaded_at DESC',
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

        $bindings = [
            'owner_id' => $ownerId,
            'original_name' => (string) $file['name'],
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'storage_path' => $targetPath,
            'uploaded_by_user_id' => $userId,
        ];

        if ($this->connection->tableExists($table)) {
            $this->connection->execute(
                'INSERT INTO ' . $table . ' (
                    ' . $ownerColumn . ', original_name, stored_name, mime_type, size_bytes, storage_path, uploaded_by_user_id, uploaded_at, is_deleted, deleted_at, deleted_by_user_id
                ) VALUES (
                    :owner_id, :original_name, :stored_name, :mime_type, :size_bytes, :storage_path, :uploaded_by_user_id, NOW(), 0, NULL, NULL
                )',
                $bindings
            );
        }

        return [
            'id' => $this->connection->lastInsertId(),
            $ownerColumn => $ownerId,
            'original_name' => $bindings['original_name'],
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'storage_path' => $targetPath,
            'uploaded_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
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

        if ($reportedMimeType !== '' && in_array($reportedMimeType, $allowedMimeTypes, true)) {
            return $reportedMimeType;
        }

        return $detectedMimeType ?? $reportedMimeType ?: 'application/octet-stream';
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

    private function scopeWhereClause(string $scope): string
    {
        return match ($scope) {
            'archived' => 'is_deleted = 1',
            'all' => '1 = 1',
            default => 'is_deleted = 0',
        };
    }
}
