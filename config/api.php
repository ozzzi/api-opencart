<?php

declare(strict_types=1);

return [
    'token' => env('API_TOKEN', ''),
    'ip_address' => env('API_IP_ADDRESS'),
    'admin_ip_address' => env('API_ADMIN_IP_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Subnets
    |--------------------------------------------------------------------------
    |
    | CIDR ranges whose requests bypass the single-IP allow list. Container IPs
    | inside a Docker network are assigned dynamically and change whenever the
    | images are rebuilt, so the whole private range is trusted instead of a
    | fixed address. Only the real TCP peer address is matched against these
    | ranges, never a forwarded header, so they cannot be spoofed.
    |
    */
    'allowed_subnets' => array_values(array_filter(array_map(
        mb_trim(...),
        explode(',', (string) env('API_ALLOWED_SUBNETS', '127.0.0.0/8,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'))
    ))),
];
