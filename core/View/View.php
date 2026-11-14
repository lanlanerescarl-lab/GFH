<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Renders a PHP view file inside a folder and returns the HTML.
 * Inside the view, $this is this object, so output is escaped with <?= $this->e($value) ?>.
 * The template override lookup (orgs/{slug}/... before templates/...) is added in Stage 4,
 * on top of this class; this one only renders the core views (error and welcome pages).
 */
final class View
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    /** @param array<string, mixed> $data variables made available to the view */
    public function render(string $name, array $data = []): string
    {
        // Only simple names, so a view name can never climb out of the views folder.
        $file = $this->viewsPath . '/' . $name . '.php';
        if (preg_match('#^[A-Za-z0-9_/-]+$#', $name) !== 1 || !is_file($file)) {
            throw new \InvalidArgumentException(sprintf('View not found: %s', $name));
        }

        $render = function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }

            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }

    /** Escapes a value for safe output inside HTML text or a quoted attribute. */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
