<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Language;
use App\Services\Search\Contracts\AdapterInterface;
use App\Services\Search\DTO\Fields\FloatField;
use App\Services\Search\DTO\Fields\IntegerField;
use App\Services\Search\DTO\Fields\StringField;
use App\Services\Search\Engines\Meilisearch\MeilisearchAdapter;
use App\Services\Search\Schema\Schema;
use App\Services\Search\Schema\SchemaConfig;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client;

final class MeiliSearchProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterInterface::class, function () {
            $schema = $this->initSchema();
            $client = $this->initClient();

            return new MeilisearchAdapter($client, $schema);
        });
    }

    private function initSchema(): Schema
    {
        return new Schema([
            'products' => new SchemaConfig(
                primaryKey: 'product_id',
                fields: [
                    new StringField(name:'name_' . Language::RU->toLowerCase(), sortable: true, weight: 5),
                    new StringField(name:'name_' . Language::UA->toLowerCase(), sortable: true, weight: 5),
                    new StringField(name:'description_' . Language::RU->toLowerCase(), weight: 1),
                    new StringField(name:'description_' . Language::UA->toLowerCase(), weight: 1),
                    new StringField(name:'model', weight: 10),
                    new StringField(name:'sku', weight: 10),
                    new FloatField(name:'price', sortable: true),
                    new IntegerField(name:'category_id', facet: true),
                    new IntegerField(name:'manufacturer_id', facet: true),
                    new FloatField(name:'rating', sortable: true),
                    new IntegerField(name:'sort_order', sortable: true),
                    new IntegerField(name:'viewed', sortable: true),
                ],
            ),
        ]);
    }

    private function initClient(): Client
    {
        return new Client(
            url: sprintf('%s:%s', config('services.search.host'), config('services.search.port')),
            apiKey: config('services.search.key')
        );
    }
}
