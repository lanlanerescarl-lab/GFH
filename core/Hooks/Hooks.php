<?php

declare(strict_types=1);

namespace Gfh\Core\Hooks;

/**
 * The hook registry. Core code passes a value through every callback registered for a hook name and uses what
 * comes out; with nothing registered the value passes through unchanged, so every hook has a working default.
 * Extensions register callbacks when they load (Stage 4 adds the loader). A callback takes ($value, array $context)
 * and returns the new value. It may throw an HttpException to refuse an action (for example, selling an expired batch).
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
    ];

    /** @var array<string, array<int, list<callable>>> hook name => priority => callbacks */
    private array $callbacks = [];

    /** Lower priority numbers run first. */
    public function add(string $name, callable $callback, int $priority = 10): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a hook name. Known hooks: %s.', $name, implode(', ', self::NAMES)));
        }

        $this->callbacks[$name][$priority][] = $callback;
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
        foreach ($this->callbacks[$name] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, $context);
            }
        }

        return $value;
    }
}
