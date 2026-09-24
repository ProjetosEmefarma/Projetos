<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use GdImage;

/**
 * Validates uploaded images and re-encodes them (strips metadata and any
 * embedded payload). Files live in storage/ (not web-accessible) and are
 * served through authenticated endpoints.
 */
final class ImageService
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Item photo: full (max 1200px) + thumb (max 320px), JPEG.
     * @return array{photo_path: string, thumb_path: string}
     */
    public static function storeItemPhoto(array $file): array
    {
        $image = self::load($file, 'photo');
        $name = bin2hex(random_bytes(12));
        $full = "uploads/items/{$name}.jpg";
        $thumb = "uploads/items/{$name}_t.jpg";
        self::saveJpeg(self::resize($image, (int) Config::get('uploads.image_max_side', 1200)), $full);
        self::saveJpeg(self::resize($image, (int) Config::get('uploads.thumb_max_side', 320)), $thumb);
        return ['photo_path' => $full, 'thumb_path' => $thumb];
    }

    /** Company logo: PNG (keeps transparency), max 600px. */
    public static function storeLogo(array $file): string
    {
        $image = self::load($file, 'logo');
        $path = 'uploads/branding/logo_' . bin2hex(random_bytes(6)) . '.png';
        $resized = self::resize($image, 600);
        imagesavealpha($resized, true);
        imagepng($resized, self::absolute($path), 6);
        return $path;
    }

    /** Signature captured on a canvas as a data URL (PNG). Flattened on white so PDFs show the ink. */
    public static function storeSignature(string $dataUrl): string
    {
        return self::persistSignature($dataUrl)['path'];
    }

    /** @return array{path:string, png:?string} */
    public static function persistSignature(string $dataUrl): array
    {
        if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
            throw HttpException::validation(['signature' => 'Assinatura inválida. Assine no campo indicado.']);
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bin === false || strlen($bin) < 400) {
            throw HttpException::validation(['signature' => 'Assine no campo indicado antes de confirmar.']);
        }
        $bin = self::flattenOnWhite($bin);
        $path = 'signatures/' . bin2hex(random_bytes(12)) . '.png';
        $abs = self::absolute($path);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0775, true);
        }
        file_put_contents($abs, $bin);
        return ['path' => $path, 'png' => base64_encode($bin)];
    }

    public static function flattenOnWhite(string $bin): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $bin;
        }
        $image = @imagecreatefromstring($bin);
        if (!$image instanceof GdImage) {
            return $bin;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagepng($canvas, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        return $out !== '' ? $out : $bin;
    }

    public static function writeBytes(string $relative, string $bytes): string
    {
        $abs = self::absolute($relative);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0775, true);
        }
        file_put_contents($abs, $bytes);
        return $abs;
    }

    public static function absolute(string $relative): string
    {
        $relative = str_replace(['..', '\\'], ['', '/'], $relative);
        return Config::get('paths.storage') . '/' . ltrim($relative, '/');
    }

    public static function delete(?string ...$paths): void
    {
        foreach ($paths as $path) {
            if ($path && is_file(self::absolute($path))) {
                @unlink(self::absolute($path));
            }
        }
    }

    // -----------------------------------------------------------------

    private static function load(array $file, string $field): GdImage
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $maxMb = (int) Config::get('uploads.max_image_mb', 5);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw HttpException::validation([$field => "Imagem maior que {$maxMb} MB."]);
        }
        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            throw HttpException::validation([$field => 'Falha no envio da imagem. Tente novamente.']);
        }
        $tmp = (string) $file['tmp_name'];
        if (!Config::isTesting() && !is_uploaded_file($tmp)) {
            throw HttpException::validation([$field => 'Arquivo inválido.']);
        }
        if (filesize($tmp) > $maxMb * 1024 * 1024) {
            throw HttpException::validation([$field => "Imagem maior que {$maxMb} MB."]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw HttpException::validation([$field => 'Formato não suportado. Envie JPG, PNG ou WEBP.']);
        }
        $data = (string) file_get_contents($tmp);
        $image = @imagecreatefromstring($data);
        if (!$image instanceof GdImage) {
            throw HttpException::validation([$field => 'Imagem inválida ou corrompida.']);
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $image = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            };
        }
        return $image;
    }

    private static function resize(GdImage $src, int $maxSide): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    private static function saveJpeg(GdImage $image, string $relative): void
    {
        // Flatten transparency on white before JPEG encoding
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        imagejpeg($canvas, self::absolute($relative), 82);
    }
}
