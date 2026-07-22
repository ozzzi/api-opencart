<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_values;
use function explode;
use function is_string;
use function mb_trim;
use function str_contains;

final class RestrictIpToHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal()) {
            /** @var Response */
            return $next($request);
        }

        if (!$this->isFromAllowedSubnet($request) && !$this->isAllowedClientIP($request)) {
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

    /**
     * Match the real TCP peer against the allowed CIDR ranges.
     *
     * Sibling containers reach this API over the Docker bridge network, where
     * their address is reassigned on every rebuild. The peer address is taken
     * straight from the connection rather than from a forwarded header, so a
     * remote client cannot claim a private address it does not hold.
     */
    private function isFromAllowedSubnet(Request $request): bool
    {
        /** @var list<string> $allowedSubnets */
        $allowedSubnets = config('api.allowed_subnets', []);

        $remoteAddress = $request->server('REMOTE_ADDR');

        if ($allowedSubnets === [] || !is_string($remoteAddress) || $remoteAddress === '') {
            return false;
        }

        return IpUtils::checkIp($remoteAddress, $allowedSubnets);
    }

    private function isAllowedClientIP(Request $request): bool
    {
        $allowedIPs = array_values(array_filter([
            config('api.ip_address'),
            config('api.admin_ip_address'),
        ], is_string(...)));

        if ($allowedIPs === []) {
            return false;
        }

        $clientIP = $this->getClientIP($request);

        if ($clientIP === null) {
            return false;
        }

        return IpUtils::checkIp($clientIP, $allowedIPs);
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
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            $serverValue = $_SERVER[$header] ?? null;

            if (!is_string($serverValue) || $serverValue === '') {
                continue;
            }

            $ip = $serverValue;

            if (str_contains($ip, ',')) {
                $ip = mb_trim(explode(',', $ip)[0]);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return $request->ip();
    }
}
