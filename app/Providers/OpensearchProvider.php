<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Language;
use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\DTO\Fields\FloatField;
use App\Services\Search\DTO\Fields\IntegerField;
use App\Services\Search\DTO\Fields\StringField;
use App\Services\Search\Engines\Opensearch\OpensearchAdapter;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Illuminate\Support\ServiceProvider;
use OpenSearch\Client;
use OpenSearch\GuzzleClientFactory;

final class OpensearchProvider extends ServiceProvider
{
    public function register(): void
    {
        $schema = $this->initSchema();

        $this->app->singleton(AdapterInterface::class, function () use ($schema) {
            $client = $this->initClient();

            return new OpensearchAdapter($client, $schema);
        });
    }

    private function initSchema(): Schema
    {
        return new Schema([
            'products' => new SchemaConfig(
                primaryKey: 'product_id',
                fields: [
                    new IntegerField(name:'product_id', options: ['type' => 'integer']),
                    new StringField(
                        name:'name_' . Language::RU->toLowerCase(),
                        sortable: true,
                        options: [
                            'type' => 'text',
                            'analyzer' => 'russian_analyzer',
                            'fields' => [
                                'phonetic' => [
                                    'type' => 'text',
                                    'analyzer' => 'phonetic_analyzer',
                                    'boost' => 3.0,
                                ],
                                'edge_ngram' => [
                                    'type' => 'text',
                                    'analyzer' => 'edge_ngram_analyzer'
                                ]
                            ],
                            'boost' => 5,
                        ],
                        weight: 5
                    ),

                    new StringField(
                        name:'name_' . Language::UA->toLowerCase(),
                        sortable: true,
                        options: [
                            'type' => 'text',
                            'analyzer' => 'ukrainian_analyzer',
                            'fields' => [
                                'phonetic' => [
                                    'type' => 'text',
                                    'analyzer' => 'phonetic_analyzer',
                                    'boost' => 3.0,
                                ],
                                'edge_ngram' => [
                                    'type' => 'text',
                                    'analyzer' => 'edge_ngram_analyzer'
                                ]
                            ],
                            'boost' => 5,
                        ],
                        weight: 5
                    ),

                    new StringField(
                        name:'description_' . Language::RU->toLowerCase(),
                        options: [
                            'type' => 'text',
                            'analyzer' => 'russian_analyzer',
                        ],
                        weight: 1
                    ),
                    new StringField(
                        name:'description_' . Language::UA->toLowerCase(),
                        options: [
                            'type' => 'text',
                            'analyzer' => 'ukrainian_analyzer',
                        ],
                        weight: 1
                    ),

                    new StringField(
                        name:'model',
                        options: [
                            'type' => 'text',
                            'analyzer' => 'exact_analyzer',
                            'fields' => [
                                'edge_ngram' => [
                                    'type' => 'text',
                                    'analyzer' => 'edge_ngram_analyzer'
                                ]
                            ],
                            'boost' => 10,
                        ],
                        weight: 10
                    ),

                    new StringField(
                        name:'sku',
                        options: [
                            'type' => 'text',
                            'analyzer' => 'exact_analyzer',
                            'fields' => [
                                'edge_ngram' => [
                                    'type' => 'text',
                                    'analyzer' => 'edge_ngram_analyzer'
                                ]
                            ],
                            'boost' => 10,
                        ],
                        weight: 10
                    ),
                    new FloatField(name:'price', sortable: true, options: ['type' => 'float']),
                    new IntegerField(name:'category_id', facet: true, options: ['type' => 'integer']),
                    new FloatField(name:'rating', sortable: true, options: ['type' => 'float']),
                    new IntegerField(name:'sort_order', sortable: true, options: ['type' => 'integer']),
                    new IntegerField(name:'viewed', sortable: true, options: ['type' => 'integer']),
                ],
            ),
        ]);
    }

    private function initClient(): Client
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
