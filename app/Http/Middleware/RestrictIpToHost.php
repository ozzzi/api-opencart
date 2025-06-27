<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function str_contains;
use function explode;
use function is_string;
use function trim;

final class RestrictIpToHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal()) {
            /** @var Response */
            return $next($request);
        }

        $clientIP = $this->getClientIP($request);

        $allowedIPs = [
            config('api.ip_address'),
            '127.0.0.1',
            '::1',
            'localhost'
        ];

        if (!in_array($clientIP, $allowedIPs, true)) {
            return new JsonResponse(
                [
                    'success' => 'false',
                    'message' => 'Access denied',
                ],
                401
            );
        }

        /** @var Response */
        return $next($request);
    }

    private function getClientIP(Request $request): string|null
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            $serverValue = $_SERVER[$header] ?? null;

            if (!is_string($serverValue) || $serverValue === '') {
                continue;
            }

            $ip = $serverValue;

            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return $request->ip();
    }
}
