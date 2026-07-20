<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

use OpenSearch\Client;
use OpenSearch\GuzzleClientFactory;

final class OpenSearchClientFactory
{
    public static function make(): Client
    {
        return (new GuzzleClientFactory())
            ->create([
                'base_uri' => sprintf(
                    '%s:%s',
                    config('services.search.host'),
                    config('services.search.port')
                ),
                'auth' => [config('services.search.user'), config('services.search.key')],
                'verify' => config('services.search.ssl'),
            ]);
    }
}
