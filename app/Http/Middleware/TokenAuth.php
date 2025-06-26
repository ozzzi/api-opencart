<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiToken = $request->bearerToken();

        if ($apiToken !== config('api.token')) {
            return new JsonResponse(
                [
                    'success' => 'false',
                    'message' => 'Unauthorized',
                ],
                401
            );
        }

        /** @var Response */
        return $next($request);
    }
}
