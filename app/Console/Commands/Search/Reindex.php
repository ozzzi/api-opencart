<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Enums\Language;
use App\Models\Product;
use App\Services\Search\SearchManager;
use Illuminate\Console\Command;

use function array_filter;

final class Reindex extends Command
{
    private const BATCH_SIZE = 200;

    protected $signature = 'search:reindex';

    protected $description = 'Reindex all products';

    public function handle(SearchManager $searchManager): void
    {
        $indexer = $searchManager->getIndexer();

        // @phpstan-ignore-next-line
        $countProducts = Product::query()->active()->count();
        $countIterates = (int) ceil($countProducts / self::BATCH_SIZE);
        $bar = $this->output->createProgressBar($countIterates);
        $bar->start();

        // @phpstan-ignore-next-line
        Product::query()
            ->active()
            ->with(['descriptions', 'category', 'manufacturer', 'reviews'])
            ->chunk(self::BATCH_SIZE, function ($products) use ($bar, $indexer) {
                $data = [];

                foreach ($products as $product) {
                    $description = $product->descriptions->keyBy('language_id')->toArray();

                    $nameRu = $description[Language::RU->value]['name'] ?? '';
                    $nameUa = $description[Language::UA->value]['name'] ?? '';

                    $descriptionRu = $description[Language::RU->value]['description'] ?? '';
                    $descriptionUa = $description[Language::UA->value]['description'] ?? '';

                    $data[] = [
                        'product_id' => (int) $product->product_id,
                        'name_' . Language::RU->toLowerCase() => $nameRu,
                        'name_' . Language::UA->toLowerCase() => $nameUa,
                        'name_suggest' => [
                            'input' => array_filter([$nameRu, $nameUa]),
                        ],
                        'description_' . Language::RU->toLowerCase() => $this->clearHtml($descriptionRu),
                        'description_' . Language::UA->toLowerCase() => $this->clearHtml($descriptionUa),
                        'model' => $product->model,
                        'sku' => $product->sku,
                        'price' => (float) $product->price,
                        'category_id' => (int) $product->category?->category_id,
                        'rating' => (float) ($product->reviews->avg('rating') ?: 0.0),
                        'sort_order' => (int) $product->sort_order,
                        'viewed' => (int) $product->viewed,
                    ];
                }

                $indexer->bulkAdd('products', $data);
                $bar->advance();
            });

        $bar->finish();
        $this->info('Reindex finished');
    }

    private function clearHtml(string $value): string
    {
        return strip_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
    }
}
