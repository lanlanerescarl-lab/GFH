<?php

declare(strict_types=1);

namespace Gfh\Core\Controller;

use Gfh\Core\App;
use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Pages that belong to the platform itself rather than to one organization. */
final class SystemController
{
    /** PHP extensions the full system needs (used from Stage 1 on). */
    private const REQUIRED_EXTENSIONS = ['pdo_pgsql', 'gd', 'mbstring', 'sodium', 'json'];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * GET /health: proves the app is running. Production answers with the minimum;
     * other environments also list the PHP version and which required extensions are loaded.
     */
    public function health(Request $request): Response
    {
        $data = [
            'status' => 'ok',
            'time' => (new \DateTimeImmutable('now'))->format('c'),
            'timezone' => Config::TIMEZONE,
        ];

        if (!$this->app->config()->isProduction()) {
            $extensions = [];
            foreach (self::REQUIRED_EXTENSIONS as $extension) {
                $extensions[$extension] = extension_loaded($extension);
            }

            $data['environment'] = $this->app->config()->environment();
            $data['php_version'] = PHP_VERSION;
            $data['extensions'] = $extensions;
        }

        return Response::ok($data);
    }

    /** GET /: a small landing page for developers. Hidden (404) in production. */
    public function welcome(Request $request): Response
    {
        if ($this->app->config()->isProduction()) {
            throw HttpException::notFound();
        }

        return Response::html($this->app->view()->render('welcome', [
            'basePath' => $request->basePath(),
            'cssUrl' => $request->basePath() . '/assets/css/error.css',
        ]));
    }
}
