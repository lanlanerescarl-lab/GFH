<?php
/**
 * Developer landing page, shown only outside production.
 *
 * @var \Gfh\Core\View\View $this
 * @var string $basePath
 * @var string $cssUrl
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GFH platform</title>
    <link rel="stylesheet" href="<?= $this->e($cssUrl) ?>">
</head>
<body>
    <main class="error-page" id="main">
        <p class="error-page__code">Stage 0 · Foundation</p>
        <h1>The GFH platform is running</h1>
        <p>The core framework is installed. Organizations, sign-in and the three apps arrive in the next stages.</p>
        <ul>
            <li><a href="<?= $this->e($basePath) ?>/health">Health check (JSON)</a></li>
        </ul>
    </main>
</body>
</html>
