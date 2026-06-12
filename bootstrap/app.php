<?php

declare(strict_types=1);

use App\Exceptions\Chat\DailyBudgetExceededException;
use App\Exceptions\Chat\LlmUnavailableException;
use App\Exceptions\Chat\RateLimitExceededException;
use App\Exceptions\Chat\SessionNotFoundException;
use App\Http\Middleware\ChatSessionToken;
use App\Http\Middleware\RestrictIpToHost;
use App\Http\Middleware\TokenAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            TokenAuth::class,
            RestrictIpToHost::class,
        ]);

        $middleware->alias([
            'chat.session' => ChatSessionToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (SessionNotFoundException $e) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        });

        $exceptions->render(function (RateLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 429)
                ->header('Retry-After', (string) $e->retryAfterSeconds);
        });

        $exceptions->render(function (DailyBudgetExceededException $e) {
            return response()->json(['message' => 'Service temporarily unavailable.'], 503)
                ->header('Retry-After', '3600');
        });

        $exceptions->render(function (LlmUnavailableException $e) {
            return response()->json(['message' => 'Service temporarily unavailable.'], 503);
        });
    })->create();
