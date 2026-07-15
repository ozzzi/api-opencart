<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\DTO\RetrievedFragment;
use App\Services\Chat\Search\HybridSearcher;

/**
 * Retrieves relevant knowledge base fragments or product records for a query.
 *
 * When no relevant fragments are found the caller receives an empty array —
 * the orchestrator/tool layer is then responsible for telling the model to
 * acknowledge the gap and suggest a lead instead of hallucinating (FR-3.5).
 */
final class RetrievalService implements RetrievalServiceInterface
{
    public function __construct(
        private readonly EmbeddingClientInterface $embeddingClient,
        private readonly HybridSearcher $searcher,
    ) {
    }

    /**
     * Retrieve KB fragments relevant to a query.
     *
     * Filters: lang = $lang, is_active = true.
     *
     * @return list<RetrievedFragment>
     */
    public function retrieveKb(string $query, string $lang = 'ru', int $topK = 5): array
    {
        $vector = $this->embed($query);

        $filters = [
            ['term' => ['lang'      => $lang]],
            ['term' => ['is_active' => true]],
        ];

        $hits = $this->searcher->search(
            index: (string) config('opensearch.indices.kb'),
            queryText: $query,
            queryVector: $vector,
            filters: $filters,
            topK: $topK,
        );

        return $this->toFragments($hits, 'kb');
    }

    /**
     * Retrieve products relevant to a query.
     *
     * Always filters in_stock = true so out-of-stock items are never suggested.
     *
     * Supported $filters keys:
     *   lang         (string)  — defaults to 'ru'
     *   category     (string)  — keyword match
     *   price_min    (float)   — range gte
     *   price_max    (float)   — range lte
     *   in_stock     (bool)    — always forced to true; key kept for explicitness
     *
     * @param  array<string, mixed> $filters
     * @return list<RetrievedFragment>
     */
    public function retrieveProducts(string $query, array $filters = [], int $topK = 5): array
    {
        $vector = $this->embed($query);

        $osFilters = [
            ['term' => ['in_stock' => true]],
        ];

        if (isset($filters['lang'])) {
            $osFilters[] = ['term' => ['lang' => (string) $filters['lang']]];
        }

        if (isset($filters['category'])) {
            $osFilters[] = ['term' => ['category.keyword' => (string) $filters['category']]];
        }

        $rangeFilter = [];

        if (isset($filters['price_min'])) {
            $rangeFilter['gte'] = (float) $filters['price_min'];
        }

        if (isset($filters['price_max'])) {
            $rangeFilter['lte'] = (float) $filters['price_max'];
        }

        if ($rangeFilter !== []) {
            $osFilters[] = ['range' => ['price' => $rangeFilter]];
        }

        $hits = $this->searcher->search(
            index: (string) config('opensearch.indices.products'),
            queryText: $query,
            queryVector: $vector,
            filters: $osFilters,
            topK: $topK * 3,
        );

        return $this->toFragments($hits, 'products', $topK);
    }

    // -------------------------------------------------------------------------

    /**
     * Embed a single query text and return its vector.
     *
     * @return list<float>
     */
    private function embed(string $query): array
    {
        $vectors = $this->embeddingClient->embed([$query]);

        return $vectors[0] ?? [];
    }

    /**
     * @param  list<array{_id:string,_score:float,_source:array<string,mixed>}> $hits
     * @return list<RetrievedFragment>
     */
    private function toFragments(array $hits, string $source, ?int $limit = null): array
    {
        $minScore = (float) config('opensearch.distance_threshold', 0.3);

        $fragments = [];
        $seenProductIds = [];

        foreach ($hits as $hit) {
            if ($hit['_score'] < $minScore) {
                continue;
            }

            $src = $hit['_source'];

            if ($source === 'products') {
                $productId = $src['product_id'] ?? null;

                if ($productId !== null) {
                    if (isset($seenProductIds[$productId])) {
                        continue;
                    }

                    $seenProductIds[$productId] = true;
                }
            }

            $content = match ($source) {
                'kb'       => mb_trim(($src['title'] ?? '').' '.($src['content'] ?? '')),
                'products' => mb_trim(($src['name'] ?? '').' '.($src['description'] ?? '')),
                default    => implode(' ', array_filter((array) $src, 'is_string')),
            };

            $fragments[] = new RetrievedFragment(
                source: $source,
                id: $hit['_id'],
                content: $content,
                score: $hit['_score'],
                metadata: $src,
            );

            if ($limit !== null && count($fragments) >= $limit) {
                break;
            }
        }

        return $fragments;
    }
}
