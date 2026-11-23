<?php
/**
 * The error summary at the top of a form: a heading and one link per problem, pointing at its field. The script-free
 * pages put focus on it with the autofocus-like attribute below, and the page script moves focus there on load.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var array<string, string> $errors field name => message (use "form" for a message that is about the whole form)
 */
?>
<?php if ($errors !== []): ?>
<div class="alert alert--error form-errors" id="form-errors" role="group" aria-labelledby="form-errors-title" tabindex="-1" data-focus-on-load>
    <h2 class="form-errors__title" id="form-errors-title">There is a problem</h2>
    <ul>
<?php foreach ($errors as $name => $message): ?>
        <li><?php if ($name === 'form'): ?><?= $this->e($message) ?><?php else: ?><a href="#field-<?= $this->e($name) ?>"><?= $this->e($message) ?></a><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
