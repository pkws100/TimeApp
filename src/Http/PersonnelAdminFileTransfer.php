<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Personnel\Compliance\PersonnelFileRuntime;
use App\Domain\Personnel\Compliance\PersonnelFileUnavailable;
use App\Domain\Personnel\Compliance\PersonnelSyntheticHttpBoundary;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;

final class PersonnelAdminFileTransfer
{
    private const EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private PersonnelSyntheticHttpBoundary $boundary, private PersonnelDocumentService $documents)
    {
    }

    public function upload(Request $request, int $documentId, int $predecessor, int $revision, string $requestId): array
    {
        $this->documents->authorizeFileUpload($documentId);
        $contentType = (string) $request->server('CONTENT_TYPE', '');
        if (preg_match('/\Amultipart\/form-data\s*;/i', $contentType) !== 1) {
            throw new PersonnelFileUnavailable('transfer');
        }
        $files = $request->files();
        $file = $files['file'] ?? null;
        if (count($files) !== 1 || !is_array($file)) {
            throw new PersonnelFileUnavailable('transfer');
        }
        $size = $file['size'] ?? null;
        $error = $file['error'] ?? null;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || (is_int($size) && $size > PersonnelFileRuntime::MAX_BYTES)) {
            throw new PersonnelFileUnavailable('size');
        }

        $temporary = $file['tmp_name'] ?? null;
        $originalName = $file['name'] ?? null;
        $mime = $file['type'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_int($size) || $size < 1 || !is_string($temporary)
            || dirname($temporary) !== $this->boundary->incomingDirectory() || !is_uploaded_file($temporary)
            || !is_string($originalName) || strlen($originalName) > 240 || !is_string($mime)) {
            throw new PersonnelFileUnavailable('transfer');
        }
        if (!isset(self::EXTENSIONS[$mime])) {
            throw new PersonnelFileUnavailable('format');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $stream = PersonnelPrivatePath::openPrivateFile($temporary);
        try {
            $this->boundary->assertSyntheticUpload($stream, $size);

            return $this->documents->uploadFile($documentId, $predecessor, $stream, $size, $requestId, $mime, $extension, $revision);
        } finally {
            fclose($stream);
        }
    }

    public function download(int $documentId, int $versionId): Response
    {
        $download = $this->documents->downloadFile($documentId, $versionId);
        try {
            $extension = self::EXTENSIONS[$download->mimeType] ?? null;
            if ($extension === null || $download->sizeBytes < 1 || $download->sizeBytes > PersonnelFileRuntime::MAX_BYTES) {
                throw new PersonnelFileUnavailable();
            }
            $bytes = stream_get_contents($download->stream(), $download->sizeBytes + 1);
            if ($bytes === false || strlen($bytes) !== $download->sizeBytes) {
                throw new PersonnelFileUnavailable();
            }

            return new Response($bytes, 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) $download->sizeBytes,
                'Content-Disposition' => 'attachment; filename="personalunterlage.' . $extension . '"',
            ]);
        } finally {
            $download->close();
        }
    }
}
