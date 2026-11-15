<?php
/**
 * Button partial. Variants: primary, secondary, ghost, danger. Sizes: sm, md, lg.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var string $label
 * @var string $variant
 * @var string $size
 * @var string $type
 * @var bool $disabled
 * @var bool $loading
 * @var array<string, string> $attrs optional extra HTML attributes
 */
$variant ??= 'primary';
$size ??= 'md';
$type ??= 'button';
$disabled ??= false;
$loading ??= false;
$attrs ??= [];

$classes = ['button'];
if (in_array($variant, ['secondary', 'ghost', 'danger'], true)) {
    $classes[] = 'button--' . $variant;
}
if (in_array($size, ['sm', 'lg'], true)) {
    $classes[] = 'button--' . $size;
}
if ($loading) {
    $classes[] = 'is-loading';
}

$extra = '';
foreach ($attrs as $attrName => $attrVal) {
    if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $attrName) === 1) {
        $extra .= ' ' . $attrName . '="' . $this->e($attrVal) . '"';
    }
}
?>
<button class="<?= $this->e(implode(' ', $classes)) ?>" type="<?= $this->e($type) ?>"<?= ($disabled || $loading) ? ' disabled' : '' ?><?= $loading ? ' aria-busy="true"' : '' ?><?= $extra ?>>
<?php if ($loading): ?>
    <span class="button__spinner" aria-hidden="true"></span>
<?php endif; ?>
    <span><?= $this->e($label) ?></span>
</button>
