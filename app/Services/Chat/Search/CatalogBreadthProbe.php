<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

use App\Services\Chat\DTO\CatalogBreadth;
use OpenSearch\Client;
use Throwable;

/**
 * Measures how wide a product query is and returns a slice of the catalog for it.
 *
 * Deliberately *not* hybrid: a single strict BM25 request, no embedding.  The
 * hybrid path is built to answer "give me the best N", not "how many match at
 * all" — HybridSearcher discards hits.total, and in RRF mode there is no single
 * result set to count.  One plain request is reproducible, cheap, and returns
 * the aggregations in the same round trip.
 *
 * Runs only when the clarification gate's preconditions already hold, so the
 * extra round trip never touches ordinary searches.
 */
final class CatalogBreadthProbe
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * A failed probe returns an empty breadth rather than throwing: the gate
     * then stays shut and the assistant answers exactly as it does today.
     */
    public function run(string $query): CatalogBreadth
    {
        try {
            $response = $this->client->search($this->buildParams($query));
        } catch (Throwable) {
            return CatalogBreadth::empty();
        }

        return $this->toBreadth($response);
    }

    /** @return array<string, mixed> */
    private function buildParams(string $query): array
    {
        $uniqueProducts = ['cardinality' => ['field' => 'product_id']];

        return [
            'index' => (string) config('opensearch.indices.products'),
            'body'  => [
                'size'             => (int) config('bot.clarification.sample_size', 30),
                '_source'          => ['product_id', 'name'],
                'track_total_hits' => true,
                'collapse' => ['field' => 'product_id'],
                'query'    => [
                    'bool' => [
                        'must' => [[
                            'multi_match' => [
                                'query'  => $query,
                                'fields' => HybridSearcher::TEXT_FIELDS,
                                'type'   => 'best_fields',
                                'operator' => 'and',
                            ],
                        ]],
                        'filter' => [['term' => ['in_stock' => true]]],
                    ],
                ],
                'aggs' => [
                    'unique_products' => $uniqueProducts,
                    'price_ranges'    => [
                        'range' => ['field' => 'price', 'ranges' => $this->buildPriceRanges()],
                        'aggs'  => ['products' => $uniqueProducts],
                    ],
                    'price_stats' => ['stats' => ['field' => 'price']],
                ],
            ],
        ];
    }

    /**
     * Turns the configured ascending boundaries into OpenSearch range buckets:
     * [50, 150] becomes "up to 50", "50–150", "from 150".
     *
     * @return list<array{from?: float, to?: float}>
     */
    private function buildPriceRanges(): array
    {
        /** @var list<int|float> $buckets */
        $buckets = array_values(array_filter(
            (array) config('bot.clarification.price_buckets', []),
            static fn ($value): bool => is_numeric($value),
        ));

        if ($buckets === []) {
            return [];
        }

        sort($buckets);

        $ranges = [['to' => (float) $buckets[0]]];

        for ($i = 1; $i < count($buckets); $i++) {
            $ranges[] = ['from' => (float) $buckets[$i - 1], 'to' => (float) $buckets[$i]];
        }

        $ranges[] = ['from' => (float) end($buckets)];

        return $ranges;
    }

    /** @param array<string, mixed> $response */
    private function toBreadth(array $response): CatalogBreadth
    {
        $aggs = $response['aggregations'] ?? [];

        return new CatalogBreadth(
            totalHits: (int) ($aggs['unique_products']['value'] ?? 0),
            priceRanges: $this->extractPriceRanges($aggs),
            priceStats: $this->priceStats($aggs),
            sampleNames: $this->sampleNames($response),
        );
    }

    /**
     * @param  array<string, mixed> $aggs
     * @return list<array{from?: float, to?: float, count: int}>
     */
    private function extractPriceRanges(array $aggs): array
    {
        $ranges = [];

        foreach ($aggs['price_ranges']['buckets'] ?? [] as $bucket) {
            $count = (int) ($bucket['products']['value'] ?? 0);

            if ($count === 0) {
                continue;
            }

            $range = [];

            if (isset($bucket['from'])) {
                $range['from'] = (float) $bucket['from'];
            }

            if (isset($bucket['to'])) {
                $range['to'] = (float) $bucket['to'];
            }

            $range['count'] = $count;

            $ranges[] = $range;
        }

        return $ranges;
    }

    /**
     * @param  array<string, mixed>                       $aggs
     * @return array{min: float, max: float, avg: float}|null
     */
    private function priceStats(array $aggs): ?array
    {
        $stats = $aggs['price_stats'] ?? null;

        if (! is_array($stats) || ($stats['count'] ?? 0) === 0) {
            return null;
        }

        return [
            'min' => round((float) ($stats['min'] ?? 0), 2),
            'max' => round((float) ($stats['max'] ?? 0), 2),
            'avg' => round((float) ($stats['avg'] ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, mixed> $response
     * @return list<string>
     */
    private function sampleNames(array $response): array
    {
        $names = [];

        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $name = mb_trim((string) ($hit['_source']['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
