<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Renders views, layouts and partials from templates/superadmin/ with the same section, partial and
 * escaping API as AppView, without requiring an organization slug.
 */
final class SuperAdminView
{
    /** @var list<array{layout: ?string, layoutData: array<string, mixed>, sections: array<string, string>, open: list<string>}> */
    private array $frames = [];

    /** @param array<string, mixed> $shared */
    public function __construct(
        private readonly string $templatesPath,
        private readonly array $shared = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        $level = ob_get_level();
        $this->frames[] = ['layout' => null, 'layoutData' => [], 'sections' => [], 'open' => []];

        try {
            $html = $this->renderFile($name, $data);
            $frame = $this->frames[array_key_last($this->frames)];

            if ($frame['layout'] !== null) {
                $this->frames[array_key_last($this->frames)]['sections']['content'] = $html;
                $html = $this->renderFile($frame['layout'], $frame['layoutData'] + $data);
            }

            return $html;
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        } finally {
            array_pop($this->frames);
        }
    }

    /** @param array<string, mixed> $data */
    public function partial(string $name, array $data = []): string
    {
        $level = ob_get_level();
        $depth = count($this->frames);
        if ($depth === 0) {
            $this->frames[] = ['layout' => null, 'layoutData' => [], 'sections' => [], 'open' => []];
        }

        $layoutBefore = $this->frames[array_key_last($this->frames)]['layout'];

        try {
            $html = $this->renderFile($name, $data);
            if ($this->frames[array_key_last($this->frames)]['layout'] !== $layoutBefore) {
                throw new \LogicException('A partial cannot choose a layout. Call layout() from the view instead.');
            }

            return $html;
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        } finally {
            if ($depth === 0) {
                array_pop($this->frames);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function layout(string $name, array $data = []): void
    {
        if ($this->frames === []) {
            throw new \LogicException('layout() can only be called from inside a view.');
        }

        $this->frames[array_key_last($this->frames)]['layout'] = $name;
        $this->frames[array_key_last($this->frames)]['layoutData'] = $data;
    }

    public function start(string $section): void
    {
        $this->currentFrame();
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $section) !== 1 || $section === 'content') {
            throw new \LogicException(sprintf('"%s" cannot be used as a section name.', $section));
        }

        $this->frames[array_key_last($this->frames)]['open'][] = $section;
        ob_start();
    }

    public function end(): void
    {
        $frame = $this->currentFrame();
        if ($frame['open'] === []) {
            throw new \LogicException('end() was called without a matching start().');
        }

        $name = array_pop($this->frames[array_key_last($this->frames)]['open']);
        $this->frames[array_key_last($this->frames)]['sections'][$name] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->currentFrame()['sections'][$name] ?? $default;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $name, array $data): string
    {
        $file = $this->resolve($name);

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

        return $render($file, $data + $this->shared);
    }

    private function resolve(string $name): string
    {
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#D', $name) !== 1) {
            throw new \InvalidArgumentException('That is not a valid template name.');
        }

        $file = $this->templatesPath . '/' . $name . '.php';
        $realFolder = realpath($this->templatesPath);
        $realFile = is_file($file) ? realpath($file) : false;

        if ($realFolder === false || $realFile === false) {
            throw new \InvalidArgumentException(sprintf('Template not found: superadmin/%s', $name));
        }

        $prefix = rtrim(str_replace('\\', '/', $realFolder), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $realFile), $prefix)) {
            throw new \InvalidArgumentException(sprintf('Template not found: superadmin/%s', $name));
        }

        return $file;
    }

    /** @return array{layout: ?string, layoutData: array<string, mixed>, sections: array<string, string>, open: list<string>} */
    private function currentFrame(): array
    {
        if ($this->frames === []) {
            throw new \LogicException('Sections can only be used from inside a view.');
        }

        return $this->frames[array_key_last($this->frames)];
    }
}
