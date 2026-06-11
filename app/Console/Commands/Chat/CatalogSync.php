<?php

declare(strict_types=1);

namespace App\Console\Commands\Chat;

use App\Jobs\IndexProductJob;
use App\Jobs\RemoveProductFromIndexJob;
use App\Models\OpenCart\OcProduct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

#[Signature('chat:catalog-sync')]
#[Description('Incrementally sync changed OpenCart products to the search index.')]
final class CatalogSync extends Command
{
    private const string CACHE_KEY = 'chat:catalog:last_sync_at';

    public function handle(): int
    {
        $lastSync = Cache::get(self::CACHE_KEY);
        $syncStartedAt = Carbon::now();

        $query = OcProduct::query();

        if ($lastSync !== null) {
            $query->where('date_modified', '>', $lastSync);
        }

        $indexed = 0;
        $removed = 0;

        $query->orderBy('product_id')->chunk(100, function ($products) use (&$indexed, &$removed): void {
            foreach ($products as $product) {
                if ($product->status === 1) {
                    IndexProductJob::dispatch($product->product_id);
                    $indexed++;
                } else {
                    RemoveProductFromIndexJob::dispatch($product->product_id);
                    $removed++;
                }
            }
        });

        Cache::forever(self::CACHE_KEY, $syncStartedAt->toISOString());

        $since = $lastSync ?? 'beginning';
        $this->line("  Sync complete since <fg=gray>{$since}</>: <fg=green>{$indexed}</> queued for indexing, <fg=yellow>{$removed}</> queued for removal.");

        return self::SUCCESS;
    }
}
