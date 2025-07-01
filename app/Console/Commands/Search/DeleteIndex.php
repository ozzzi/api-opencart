<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Services\Search\Contracts\AdapterInterface;
use Illuminate\Console\Command;

final class DeleteIndex extends Command
{
    protected $signature = 'search:delete-index';

    protected $description = 'Delete a search index';

    public function handle(AdapterInterface $searchManager): void
    {
        $searchManager->getIndexer()->deleteIndex('products');
    }
}
