<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;

/** PDF / image notes (NF) stored outside the web root. */
final class DocumentService
{
    public static function storeNota(array $file, string $field = 'invoice'): string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw HttpException::validation([$field => 'Não foi possível ler o arquivo da nota.']);
        }
        if (!Config::isTesting() && !is_uploaded_file($tmp)) {
            throw HttpException::validation([$field => 'Upload inválido.']);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 10 * 1024 * 1024) {
            throw HttpException::validation([$field => 'A nota deve ter no máximo 10 MB.']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };
        if ($ext === null) {
            throw HttpException::validation([$field => 'Anexe a nota em PDF, JPG ou PNG.']);
        }
        $relative = 'uploads/invoices/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = ImageService::absolute($relative);
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        if (!@copy($tmp, $dest)) {
            throw HttpException::validation([$field => 'Falha ao gravar a nota.']);
        }
        return $relative;
    }

    public static function mime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
