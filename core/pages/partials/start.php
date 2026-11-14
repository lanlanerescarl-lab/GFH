<?php
/**
 * Opens a simple page: document head, skip link and the main landmark. Closed by partials/end.php.
 *
 * @var \Gfh\Core\View\View $this
 * @var string $title
 * @var string $cssUrl
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= $this->e($title) ?> · GFH</title>
    <link rel="stylesheet" href="<?= $this->e($cssUrl) ?>">
</head>
<body>
    <a class="skip-link" href="#main">Skip to the main content</a>
    <main class="error-page" id="main" tabindex="-1">
