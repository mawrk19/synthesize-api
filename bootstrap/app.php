<?php

use App\Exceptions\ErrorCodes;
use App\Modules\Iam\Exceptions\InvalidCredentialException;
use App\Support\ClientDebug;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidCredentialException $exception) {
            return response()->json([
                'error' => [
                    'code' => ErrorCodes::INVALID_LOGIN_CREDENTIALS->value,
                    'message' => $exception->getMessage(),
                ],
            ], 401);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Let framework / dedicated handlers format these
            if (
                $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof InvalidCredentialException
            ) {
                return null;
            }

            if (ClientDebug::enabled()) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $raw = $exception->getMessage();
                $safe = ClientDebug::publicError(
                    $raw !== '' ? $raw : null,
                    $status >= 500
                        ? 'An unexpected error occurred. Please try again later.'
                        : 'Unable to complete this request.',
                );

                return response()->json([
                    'message' => $safe,
                    'error' => [
                        'message' => $safe,
                    ],
                ], $status);
            }

            report($exception);

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again later.',
                'error' => [
                    'message' => 'An unexpected error occurred. Please try again later.',
                ],
            ], 500);
        });
    })->create();
