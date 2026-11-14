<?php

declare(strict_types=1);

namespace Gfh\Core\Error;

use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Logger;
use Gfh\Core\View\View;

/**
 * Turns every failure into a safe response.
 * Users see a friendly page (or the JSON error envelope) and a reference id.
 * Stack traces go to storage/logs/app.log only. In local debug mode the page also shows
 * the exception class, message and file; never a stack trace, and never in production.
 */
final class ErrorHandler
{
    private const TITLES = [
        400 => 'We could not understand that request',
        401 => 'Please sign in',
        403 => 'Access denied',
        404 => 'Page not found',
        405 => 'Action not available',
        413 => 'That request is too large',
        422 => 'Please check your details',
        429 => 'Too many attempts',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleUncaught(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    /** Turns PHP warnings and notices into exceptions so they cannot pass silently. */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        // Deprecation notices should not take a page down; record them and carry on.
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            $this->logger->warning('Deprecated: ' . $message, ['file' => $file, 'line' => $line]);

            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /** Last line of defence for exceptions that nothing caught. */
    public function handleUncaught(\Throwable $exception): void
    {
        if (PHP_SAPI === 'cli') {
            $this->logThrowable($exception, null);
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);

            return;
        }

        try {
            $request = Request::fromGlobals();
            $response = $this->toResponse($exception, $request)->withHeader('X-Request-Id', $request->id());
        } catch (\Throwable) {
            $response = self::fallbackResponse();
        }

        $response->send();
    }

    /** Catches fatal errors (for example running out of memory) that cannot be thrown as exceptions. */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);

        if (headers_sent()) {
            $this->logThrowable($exception, null);

            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->handleUncaught($exception);
    }

    /** Builds the response for any throwable. Server faults are logged with their full details. */
    public function toResponse(\Throwable $exception, Request $request): Response
    {
        if ($exception instanceof HttpException) {
            $status = $exception->status();
            $code = $exception->errorCode();
            $message = $exception->getMessage();
            $fields = $exception->fields();
            $headers = $exception->headers();
        } else {
            $status = 500;
            $code = 'server_error';
            $message = 'Something went wrong on our side. Please try again in a moment.';
            $fields = [];
            $headers = [];
        }

        if ($status >= 500) {
            $this->logThrowable($exception, $request);
        }

        $debug = null;
        if ($this->config->debug() && !($exception instanceof HttpException)) {
            $debug = [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'location' => basename($exception->getFile()) . ':' . $exception->getLine(),
            ];
        }

        if ($request->wantsJson()) {
            $response = Response::error($code, $message, $status, $fields, $headers);
            if ($debug === null) {
                return $response;
            }

            // Debug mode only: same envelope plus a debug block, built here to keep Response::error strict.
            return Response::json([
                'ok' => false,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'fields' => $fields === [] ? new \stdClass() : $fields,
                    'debug' => $debug,
                ],
            ], $status, $headers);
        }

        try {
            $html = $this->view->render('error', [
                'status' => $status,
                'title' => self::TITLES[$status] ?? ($status >= 500 ? 'Something went wrong' : 'Request problem'),
                'message' => $message,
                'requestId' => $request->id(),
                'homeUrl' => $request->basePath() . '/',
                'cssUrl' => $request->basePath() . '/assets/css/error.css',
                'debug' => $debug,
            ]);
        } catch (\Throwable $viewFailure) {
            $this->logThrowable($viewFailure, $request);

            return self::fallbackResponse($status);
        }

        return Response::html($html, $status, $headers);
    }

    private function logThrowable(\Throwable $exception, ?Request $request): void
    {
        $context = [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        if ($request !== null) {
            $context['request'] = $request->method() . ' ' . $request->path();
        }

        $this->logger->error($exception->getMessage(), $context);
    }

    /** Plain page used when even the error view cannot be rendered. */
    private static function fallbackResponse(int $status = 500): Response
    {
        return Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong</title></head>'
            . '<body><main><h1>Something went wrong</h1><p>Please try again in a moment.</p></main></body></html>',
            $status >= 400 ? $status : 500,
        );
    }
}
