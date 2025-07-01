<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

use App\Services\Search\DTO\Result;

interface Searcher
{
    /**
     * @param array<int, array<string, string|int|float>> $filters
     * @param array<string, string> $sorts
     * @param array<string, mixed> $options
     */
    public function search(
        string $indexName,
        string $query = '',
        array $filters = [],
        array $sorts = [],
        int $limit = 10,
        int $offset = 0,
        array $options = [],
    ): Result;
}
