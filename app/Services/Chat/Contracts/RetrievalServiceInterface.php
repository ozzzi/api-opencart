<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Services\Chat\DTO\RetrievedFragment;

interface RetrievalServiceInterface
{
    /**
     * Retrieve KB fragments relevant to a query.
     *
     * @return list<RetrievedFragment>
     */
    public function retrieveKb(string $query, int $topK = 5): array;

    /**
     * Retrieve products relevant to a query.
     *
     * @param  array<string, mixed>  $filters
     * @return list<RetrievedFragment>
     */
    public function retrieveProducts(string $query, array $filters = [], int $topK = 5): array;
}
