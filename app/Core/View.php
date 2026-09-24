<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renders resources/views/{name}.php with an $app array in scope.
 * Missing views fall back to app/Views/fallback.php (developer placeholder).
 */
final class View
{
    public static function exists(string $view): bool
    {
        return is_file(self::path($view));
    }

    public static function render(string $view, array $app): string
    {
        $file = self::path($view);
        if (!is_file($file)) {
            $app['missing_view'] = $view;
            $file = BASE_PATH . '/app/Views/fallback.php';
        }
        return (static function (string $__file, array $app): string {
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($file, $app);
    }

    private static function path(string $view): string
    {
        $view = str_replace(['..', '\\'], ['', '/'], $view);
        return Config::get('paths.views') . '/' . $view . '.php';
    }
}
