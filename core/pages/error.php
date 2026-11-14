<?php
/**
 * Friendly error page.
 *
 * @var \Gfh\Core\View\View $this
 * @var int $status
 * @var string $title
 * @var string $message
 * @var string $requestId
 * @var string $homeUrl
 * @var string $cssUrl
 * @var array{type: string, message: string, location: string}|null $debug
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title) ?> · GFH</title>
    <link rel="stylesheet" href="<?= $this->e($cssUrl) ?>">
</head>
<body>
    <main class="error-page" id="main">
        <p class="error-page__code">Error <?= $this->e($status) ?></p>
        <h1><?= $this->e($title) ?></h1>
        <p><?= $this->e($message) ?></p>
        <?php if ($debug !== null): ?>
            <div class="error-page__debug" role="note">
                <strong>Debug (local only)</strong><br>
                <?= $this->e($debug['type']) ?>: <?= $this->e($debug['message']) ?><br>
                <?= $this->e($debug['location']) ?>
            </div>
        <?php endif; ?>
        <p><a class="button" href="<?= $this->e($homeUrl) ?>">Back to the start</a></p>
        <p class="error-page__ref">Reference: <?= $this->e($requestId) ?></p>
    </main>
</body>
</html>
