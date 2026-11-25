<?php

declare(strict_types=1);

namespace Gfh\Core\Hooks;

use Gfh\Core\Http\HttpException;

/**
 * The hook registry. Core code passes a value through every callback registered for a hook name and uses what
 * comes out; with nothing registered the value passes through unchanged, so every hook has a working default.
 * Extensions register callbacks when they load (Stage 4 adds the loader). A callback takes ($value, array $context)
 * and returns the new value.
 *
 * Every callback runs on its own (Batch 8.3), so one broken extension cannot turn a page into a 500:
 *  - an HttpException passes through untouched, because extensions throw it on purpose (a refused save, a refused sale);
 *  - any other Throwable is caught, logged once per request and callback, and skipped: the value from before that
 *    callback is kept and the next callbacks still run;
 *  - a callback that returns a different type than it received (for example null or a string for a list) is ignored
 *    and logged the same way, unless the hook is listed in MAY_CHANGE_TYPE (none is today).
 * Not catchable by any PHP code: a fatal error, an exit() and a timeout inside a callback.
 */
final class Hooks
{
    public const NAVIGATION_ADMIN = 'navigation.admin';
    public const NAVIGATION_SHOP = 'navigation.shop';
    public const PRODUCT_FORM_TABS = 'product.form.tabs';
    public const PRODUCT_FORM_SAVE = 'product.form.save';
    public const PRODUCT_DETAIL_SECTIONS = 'product.detail.sections';
    public const CHECKOUT_FIELDS = 'checkout.fields';
    public const CHECKOUT_ADJUSTMENTS = 'checkout.adjustments';
    public const ORDER_PROGRESS_STEPS = 'order.progress.steps';
    public const ORDER_DETAIL_PANELS = 'order.detail.panels';
    public const STOCK_PICK_STRATEGY = 'stock.pick_strategy';
    public const VALIDATION_ATTRIBUTES = 'validation.attributes';
    public const DASHBOARD_WIDGETS = 'dashboard.widgets';
    public const REPORTS_TABS = 'reports.tabs';
    public const ACCOUNT_MENU = 'account.menu';

    public const NAMES = [
        self::NAVIGATION_ADMIN,
        self::NAVIGATION_SHOP,
        self::PRODUCT_FORM_TABS,
        self::PRODUCT_FORM_SAVE,
        self::PRODUCT_DETAIL_SECTIONS,
        self::CHECKOUT_FIELDS,
        self::CHECKOUT_ADJUSTMENTS,
        self::ORDER_PROGRESS_STEPS,
        self::ORDER_DETAIL_PANELS,
        self::STOCK_PICK_STRATEGY,
        self::VALIDATION_ATTRIBUTES,
        self::DASHBOARD_WIDGETS,
        self::REPORTS_TABS,
        self::ACCOUNT_MENU,
    ];

    /**
     * Hooks whose callbacks may return a different type than they received. None today: every hook value is a list or
     * an array, and docs/extensions.md says so. Add a hook name here only together with that document.
     */
    public const MAY_CHANGE_TYPE = [];

    /** The longest exception message that is written to the log. */
    private const LOG_MESSAGE_LIMIT = 300;

    /** @var array<string, array<int, list<array{callback: callable, owner: ?string, id: int}>>> hook name => priority => callbacks */
    private array $callbacks = [];
    private int $sequence = 0;
    /** @var array<int, true> ids of the callbacks that were already reported in this request */
    private array $reported = [];
    /** @var array<string, true> problems with returned values that were already reported in this request */
    private array $reportedProblems = [];

    /**
     * @param (\Closure(string, array<string, mixed>): void)|null $log receives one message and a small context for each
     *                                                              callback that failed; without it failures are skipped silently
     */
    public function __construct(private readonly ?\Closure $log = null)
    {
    }

    /**
     * Lower priority numbers run first. $owner names the extension that registered the callback, for the log.
     */
    public function add(string $name, callable $callback, int $priority = 10, ?string $owner = null): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a hook name. Known hooks: %s.', $name, implode(', ', self::NAMES)));
        }

        $this->callbacks[$name][$priority][] = ['callback' => $callback, 'owner' => $owner, 'id' => ++$this->sequence];
    }

    public function has(string $name): bool
    {
        return !empty($this->callbacks[$name]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function filter(string $name, mixed $value, array $context = []): mixed
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a hook name.', $name));
        }

        if (empty($this->callbacks[$name])) {
            return $value;
        }

        ksort($this->callbacks[$name]);
        foreach ($this->callbacks[$name] as $entries) {
            foreach ($entries as $entry) {
                try {
                    $result = ($entry['callback'])($value, $context);
                } catch (HttpException $refusal) {
                    // On purpose: a callback refuses an action (422 for a bad value, 409 for an expired batch).
                    throw $refusal;
                } catch (\Throwable $failure) {
                    $this->report($name, $entry, sprintf('%s: %s', $failure::class, self::cleanMessage($failure->getMessage())));
                    continue;
                }

                if (self::kind($result) !== self::kind($value) && !in_array($name, self::MAY_CHANGE_TYPE, true)) {
                    $this->report($name, $entry, sprintf('returned %s where %s was expected', self::kind($result), self::kind($value)));
                    continue;
                }

                $value = $result;
            }
        }

        return $value;
    }

    /**
     * Writes one log entry per request and callback: which hook, which extension (or callback) and what went wrong.
     * Never the stack trace, never the hook's value or context, which can hold a customer's input.
     *
     * @param array{callback: callable, owner: ?string, id: int} $entry
     */
    private function report(string $name, array $entry, string $problem): void
    {
        if ($this->log === null || isset($this->reported[$entry['id']])) {
            return;
        }
        $this->reported[$entry['id']] = true;

        try {
            ($this->log)(
                sprintf('Hook callback skipped: %s (%s) %s', $name, $entry['owner'] ?? self::describe($entry['callback']), $problem),
                ['hook' => $name, 'extension' => $entry['owner'], 'callback' => self::describe($entry['callback'])],
            );
        } catch (\Throwable) {
            // A broken log must not bring the page down either.
        }
    }

    /**
     * Logs a problem with what a hook's callbacks returned (a malformed checkout field, say) when core ignores part of
     * it. Same rules as a skipped callback: once per request and key, one cleaned line, never the value, never fatal.
     *
     * @param string $key what makes two reports the same one (for example the field id), so a page that reads the
     *                    hook twice still logs it once
     */
    public function problem(string $name, string $message, string $key): void
    {
        $id = 'problem:' . $name . ':' . $key;
        if ($this->log === null || isset($this->reportedProblems[$id])) {
            return;
        }
        $this->reportedProblems[$id] = true;

        try {
            ($this->log)(sprintf('Hook value problem: %s: %s', $name, self::cleanMessage($message)), ['hook' => $name]);
        } catch (\Throwable) {
            // A broken log must not bring the page down either.
        }
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            default => 'object',
        };
    }

    /** One line of plain text: no line breaks, no credentials in an address or a key=value pair, at most 300 characters. */
    private static function cleanMessage(string $message): string
    {
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        $message = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/\s@]*@#i', '$1***@', $message);
        $message = (string) preg_replace('/\b(password|passwd|secret|token|api[_-]?key|csrf|_csrf|authorization|cookie)\b(\s*[=:]\s*)\S+/i', '$1$2***', $message);

        return mb_substr(trim($message), 0, self::LOG_MESSAGE_LIMIT);
    }

    /** A short name for a callback that has no extension: the class and method, or the file and line of a closure. */
    private static function describe(callable $callback): string
    {
        if ($callback instanceof \Closure) {
            $reflection = new \ReflectionFunction($callback);

            return sprintf('closure in %s:%d', basename((string) $reflection->getFileName()), (int) $reflection->getStartLine());
        }
        if (is_array($callback)) {
            return (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]) . '::' . (string) $callback[1];
        }

        return is_object($callback) ? $callback::class : (string) $callback;
    }
}
