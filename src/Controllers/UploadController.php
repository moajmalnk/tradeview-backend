<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Uuid;

final class UploadController
{
    private const MAX_BYTES = 5_242_880; // 5 MB
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function store(Request $request): void
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            Response::error('VALIDATION_ERROR', 'file is required (multipart field name: file).', 422);

            return;
        }

        $file = $_FILES['file'];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            Response::error('UPLOAD_FAILED', 'Upload failed (code ' . $error . ').', 400);

            return;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            Response::error('VALIDATION_ERROR', 'File must be between 1 byte and 5 MB.', 422);

            return;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::error('UPLOAD_FAILED', 'Invalid upload.', 400);

            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            Response::error('VALIDATION_ERROR', 'Only JPEG, PNG, WebP, or GIF images are allowed.', 422);

            return;
        }

        $ext = self::ALLOWED[$mime];
        $id = Uuid::v4();
        $basename = $id . '.' . $ext;
        $dir = dirname(__DIR__, 2) . '/public/uploads/capital';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Response::error('UPLOAD_FAILED', 'Could not create upload directory.', 500);

            return;
        }

        $dest = $dir . '/' . $basename;
        if (!move_uploaded_file($tmp, $dest)) {
            Response::error('UPLOAD_FAILED', 'Could not store file.', 500);

            return;
        }

        $original = (string) ($file['name'] ?? $basename);
        $original = substr(preg_replace('/[^\w.\- ()]+/u', '_', $original) ?: $basename, 0, 180);

        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? 'https://tradeapi.finbro.cloud'), '/');
        $url = $baseUrl . '/uploads/capital/' . $basename;

        Response::data([
            'id' => $id,
            'name' => $original,
            'mime' => $mime,
            'size' => $size,
            'url' => $url,
        ], 201);
    }
}
