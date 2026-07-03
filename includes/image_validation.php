<?php

declare(strict_types=1);

function requireUploadedFile(string $fieldName): array
{
    if (!isset($_FILES[$fieldName])) {
        respondJson(
            400,
            "missing {$fieldName} file",
            null,
            400
        );
    }

    $file = $_FILES[$fieldName];

    if (!is_array($file)) {
        respondJson(
            400,
            'invalid upload data',
            null,
            400
        );
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respondJson(
            400,
            'upload failed',
            ['error' => $file['error'] ?? null],
            400
        );
    }

    return $file;
}

function validateFileSize(array $file, int $maxBytes): void
{
    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > $maxBytes) {
        respondJson(
            400,
            'invalid file size',
            ['maxBytes' => $maxBytes],
            400
        );
    }
}

function detectMimeType(array $file): string
{
    $temporaryPath = $file['tmp_name'] ?? '';

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        respondJson(
            400,
            'invalid uploaded file',
            null,
            400
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($temporaryPath);

    if ($mimeType === false) {
        respondJson(
            400,
            'unable to detect file type',
            null,
            400
        );
    }

    return $mimeType;
}

function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        respondJson(
            500,
            'failed to create upload directory',
            null,
            500
        );
    }
}

function generateFileName(
    string $prefix,
    string $extension
): string {
    return sprintf(
        '%s_%s_%s.%s',
        $prefix,
        date('Ymd_His'),
        bin2hex(random_bytes(4)),
        $extension
    );
}
