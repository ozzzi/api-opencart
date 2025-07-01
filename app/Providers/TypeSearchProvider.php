<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Language;
use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\DTO\Fields\FloatArrayField;
use App\Services\Search\DTO\Fields\FloatField;
use App\Services\Search\DTO\Fields\IntegerField;
use App\Services\Search\DTO\Fields\StringField;
use App\Services\Search\Engines\TypeSense\TypesenseAdapter;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Illuminate\Support\ServiceProvider;
use Typesense\Client;

final class TypeSearchProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterInterface::class, function () {
            $schema = $this->initSchema();
            $client = $this->initClient();

            return new TypesenseAdapter($client, $schema);
        });
    }

    private function initSchema(): Schema
    {
        return new Schema([
            'products' => new SchemaConfig(
                primaryKey: 'id',
                fields: [
                    new StringField(name:'name_' . Language::RU->toLowerCase(), sortable: true, weight: 5),
                    new StringField(name:'name_' . Language::UA->toLowerCase(), sortable: true, weight: 5),
                    new StringField(name:'description_' . Language::RU->toLowerCase(), weight: 1),
                    new StringField(name:'description_' . Language::UA->toLowerCase(), weight: 1),
                    new StringField(name:'model', options: ['token_separators' => ['-', '.', '/']], weight: 10),
                    new StringField(name:'sku', weight: 10),
                    new FloatField(name:'price', sortable: true),
                    new IntegerField(name:'category_id', facet: true),
                    new IntegerField(name:'manufacturer_id', facet: true),
                    new FloatField(name:'rating', sortable: true),
                    new IntegerField(name:'sort_order', sortable: true),
                    new IntegerField(name:'viewed', sortable: true),
                    new FloatArrayField(name: 'embedding', searchable: true, options: [
                        'embed' => [
                            'from' => [
                                'name_ru', 'name_ua',
                            ],
                            'model_config' => [
                                'model_name' => config('services.search.model'),
                            ],
                        ],
                    ], weight: 5),
                ],
            ),
        ]);
    }

    private function initClient(): Client
    {
        return new Client([
            'api_key' => config('services.search.key'),
            'nodes' => [
                [
                    'host' => config('services.search.host'),
                    'port' => config('services.search.port'),
                    'protocol' => 'http',
                ],
            ],
        ]);
    }
}
