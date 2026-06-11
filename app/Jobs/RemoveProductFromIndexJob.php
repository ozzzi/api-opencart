<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Chat\Search\OpenSearchIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([10, 60])]
final class RemoveProductFromIndexJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $productId,
    ) {
        $this->onQueue('indexing');
    }

    public function handle(OpenSearchIndexer $indexer): void
    {
        $indexer->deleteByQuery(
            (string) config('opensearch.indices.products'),
            ['query' => ['term' => ['product_id' => $this->productId]]],
        );
    }
}
