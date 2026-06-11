<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

final class OpenSearchClientFactory
{
    public static function make(): Client
    {
        /** @var array{host:string,port:int,user:string,password:string,ssl:bool,ssl_verification:bool,debug:bool} $config */
        $config = config('opensearch');

        $scheme = $config['ssl'] ? 'https' : 'http';

        $builder = ClientBuilder::create()
            ->setHosts(["{$scheme}://{$config['host']}:{$config['port']}"])
            ->setRetries(1);

        if ($config['user'] !== '' && $config['password'] !== '') {
            $builder->setBasicAuthentication($config['user'], $config['password']);
        }

        if ($config['ssl'] && ! $config['ssl_verification']) {
            $builder->setSSLVerification(false);
        }

        return $builder->build();
    }
}
