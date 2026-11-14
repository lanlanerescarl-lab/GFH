<?php

declare(strict_types=1);

namespace Gfh\Core\View;

/**
 * Renders the views of one app (admin, shop or pos) for one organization, through TemplateLocator, so every
 * include follows the same rule: the organization's file first, then the shared template.
 *
 * Inside a view, $this is this object:
 *   $this->e($value)                     escapes text for HTML (use it for every value)
 *   $this->partial('partials/nav', [...]) renders another file and returns its HTML
 *   $this->layout('layout', [...])       wraps this view's output in a layout; the output becomes the "content" section
 *   $this->start('title') ... $this->end()  captures a named section for the layout
 *   $this->section('title', 'default')   outputs a section (in the layout); sections hold HTML that is already escaped
 *
 * Variables passed to render() and partial() become plain variables in the file. Shared data (the organization
 * name, URLs, the CSRF token) is given once to the constructor and is available everywhere; data passed to a
 * call wins over shared data of the same name.
 */
final class AppView
{
    /** @var list<array{layout: ?string, layoutData: array<string, mixed>, sections: array<string, string>, open: list<string>}> */
    private array $frames = [];

    /** @param array<string, mixed> $shared */
    public function __construct(
        private readonly TemplateLocator $locator,
        private readonly string $organizationSlug,
        private readonly string $app,
        private readonly array $shared = [],
    ) {
        TemplateLocator::assertSlug($organizationSlug);
    }

    public function organizationSlug(): string
    {
        return $this->organizationSlug;
    }

    public function app(): string
    {
        return $this->app;
    }

    /**
     * Renders a view and, if it asked for one, its layout. Returns the final HTML.
     *
     * @param array<string, mixed> $data
     */
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
            // A view that failed halfway may have left a section buffer open; never leak its output.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        } finally {
            array_pop($this->frames);
        }
    }

    /**
     * Renders a file without a layout, for repeated pieces such as navigation or a form field.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        $level = ob_get_level();
        $depth = count($this->frames);
        // A partial shares the sections of the page it sits in, so it can feed the layout (for example a script tag).
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

    /**
     * Wraps the current view in a layout. Call it once, at the top of a view.
     *
     * @param array<string, mixed> $data extra variables for the layout
     */
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

    /** Returns a section's HTML, or $default (already-escaped HTML) when the view did not set it. */
    public function section(string $name, string $default = ''): string
    {
        return $this->currentFrame()['sections'][$name] ?? $default;
    }

    /** Escapes a value for safe output inside HTML text or a quoted attribute. */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $name, array $data): string
    {
        $file = $this->locator->resolve($this->organizationSlug, $this->app, $name);

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

    /** @return array{layout: ?string, layoutData: array<string, mixed>, sections: array<string, string>, open: list<string>} */
    private function currentFrame(): array
    {
        if ($this->frames === []) {
            throw new \LogicException('Sections can only be used from inside a view.');
        }

        return $this->frames[array_key_last($this->frames)];
    }
}
