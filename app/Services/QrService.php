<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\QrEncoder;

/** Unique TRADE codes (EME-2026-000001) and QR images. */
final class QrService
{
    public static function nextPublicCode(): string
    {
        $year = date('Y');
        return sprintf('EME-%s-%06d', $year, Sequence::next('trade', $year));
    }

    public static function nextExitCode(): string
    {
        $year = date('Y');
        return sprintf('SAI-%s-%04d', $year, Sequence::next('exit', $year));
    }

    public static function svg(string $code): string
    {
        return QrEncoder::svg($code, 4);
    }

    public static function png(string $code, int $scale = 6): string
    {
        return QrEncoder::png($code, $scale);
    }

    public static function pngDataUri(string $code, int $scale = 6): string
    {
        return QrEncoder::pngDataUri($code, $scale);
    }
}
