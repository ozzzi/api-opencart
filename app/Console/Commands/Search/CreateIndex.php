<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Services\Search\Contracts\AdapterInterface;
use Illuminate\Console\Command;

final class CreateIndex extends Command
{
    protected $signature = 'search:create-index';

    protected $description = 'Create a new index';

    public function handle(AdapterInterface $searchManager): void
    {
        $searchManager->getIndexer()->createIndex('products');
    }
}
