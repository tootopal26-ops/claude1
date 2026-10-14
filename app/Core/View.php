<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates under /app/Views. Output is escaped in the templates
 * with e(); the layout receives the rendered page as $content.
 */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $html = self::capture($template, $data);
        if ($layout !== null) {
            $html = self::capture($layout, $data + ['content' => $html]);
        }
        return $html;
    }

    /** Render a partial from inside a template. */
    public static function partial(string $name, array $data = []): string
    {
        return self::capture('partials/' . $name, $data);
    }

    /** @param array<string,mixed> $__data */
    private static function capture(string $__template, array $__data): string
    {
        $__file = SMB_ROOT . '/app/Views/' . $__template . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View not found: {$__template}");
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
