<?php

declare(strict_types=1);

namespace Gfh\Core;

use Gfh\Core\Config\Config;
use Gfh\Core\Config\ConfigException;
use Gfh\Core\Error\ErrorHandler;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Routing\Router;
use Gfh\Core\Support\Logger;
use Gfh\Core\View\View;

/**
 * The application object: holds configuration, logger and error handling,
 * and turns a Request into a Response. Later stages hang tenancy, auth and
 * the database connections off this class.
 */
final class App
{
    private function __construct(
        private readonly string $rootPath,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly View $view,
        private readonly ErrorHandler $errors,
    ) {
    }

    /** Loads settings, sets the time zone, and installs the error handlers. */
    public static function boot(string $rootPath): self
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        try {
            $config = Config::load($rootPath);
        } catch (ConfigException $exception) {
            self::bootFailure($exception->getMessage());
        }

        date_default_timezone_set(Config::TIMEZONE);

        $logger = new Logger(
            $rootPath . '/storage/logs/app.log',
            $config->string('LOG_LEVEL', $config->isProduction() ? 'warning' : 'debug'),
        );
        $view = new View($rootPath . '/core/pages');
        $errors = new ErrorHandler($config, $logger, $view);
        $errors->register();

        return new self($rootPath, $config, $logger, $view, $errors);
    }

    /** The problem is described in the server log; visitors only see a neutral message. */
    private static function bootFailure(string $message): never
    {
        error_log('GFH configuration error: ' . $message);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Configuration error: ' . $message . PHP_EOL);
            exit(1);
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "The application is not configured correctly. Please contact the administrator.\n";
        exit(1);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function view(): View
    {
        return $this->view;
    }

    /** Absolute path inside the project, for example path('storage/uploads'). */
    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->rootPath : $this->rootPath . '/' . ltrim($relative, '/');
    }

    /** Handles the current request and sends the response. */
    public function run(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request)->withHeader('X-Request-Id', $request->id());
        $response->send($request->method() === 'HEAD');
    }

    /** Request in, Response out. Never throws: every failure becomes an error response. */
    public function handle(Request $request): Response
    {
        $this->logger->setRequestId($request->id());

        try {
            $router = new Router();
            $define = require $this->path('core/routes.php');
            $define($router, $this);

            return $router->dispatch($request);
        } catch (\Throwable $exception) {
            return $this->errors->toResponse($exception, $request);
        }
    }
}
