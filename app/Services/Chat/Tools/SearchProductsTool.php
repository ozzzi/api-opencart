<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ClarificationGateInterface;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\DTO\CatalogBreadth;
use App\Services\Chat\DTO\RetrievedFragment;
use App\Services\Chat\Search\QueryTerms;
use App\Services\Chat\Tools\Contracts\ToolInterface;

final class SearchProductsTool implements ToolInterface
{
    private const int SNIPPET_RADIUS = 120;

    public function __construct(
        private readonly RetrievalServiceInterface $retrieval,
        private readonly ClarificationGateInterface $gate,
    ) {
    }

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Search the product catalog for items matching a user query. '
            .'Supports optional filters for price range. '
            .'Returns 2–4 matching products with name, price, availability, and a direct URL. '
            .'When the query is too broad to pick anything meaningful, returns '
            .'status "need_clarification" with a slice of the catalog and no products at all — '
            .'ask one narrowing question built only from that slice. '
            .'Always use this tool before recommending any product.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Product search query (e.g. "laptop for students", "wireless headphones").',
                ],
                'price_min' => [
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => 'Minimum price (inclusive).',
                ],
                'price_max' => [
                    'type'        => 'number',
                    'minimum'     => 0,
                    'description' => 'Maximum price (inclusive).',
                ],
                'skip_clarification' => [
                    'type'        => 'boolean',
                    'description' => 'Set to true when the customer said the choice does not matter '
                        .'and asked to just show what is available.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 4,
                    'description' => 'Maximum number of products to return. Defaults to 4.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        $query = (string) $arguments['query'];
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 4;

        $decision = $this->gate->evaluate($session, $arguments);

        if ($decision->needsClarification && $decision->breadth !== null) {
            return $this->clarificationResponse($decision->breadth, $decision->round);
        }

        $filters = [];

        if (isset($arguments['price_min'])) {
            $filters['price_min'] = (float) $arguments['price_min'];
        }

        if (isset($arguments['price_max'])) {
            $filters['price_max'] = (float) $arguments['price_max'];
        }

        // With the question budget spent the query is still undiscriminating, so a
        // flat top-N would look as random as ever. Fetch wider and spread the pick
        // across categories instead (FR-4.11).
        $fetchLimit = $decision->diversify ? $limit * 3 : $limit;

        $fragments = $this->retrieval->retrieveProducts($query, $filters, $fetchLimit);

        if ($decision->diversify) {
            $fragments = $this->spreadAcrossCategories($fragments, $limit);
        }

        if ($fragments === []) {
            return json_encode(
                ['status' => 'empty', 'results' => [], 'found' => false],
                JSON_UNESCAPED_UNICODE,
            );
        }

        $terms = QueryTerms::significant($query);

        $results = array_map(function (RetrievedFragment $fragment) use ($terms): array {
            $src = $fragment->metadata;
            $matched = $this->matchedTerms($terms, $src);

            return [
                'product_id'    => $src['product_id'] ?? null,
                'name'          => $src['name'] ?? $fragment->content,
                'price'         => $src['price'] ?? null,
                'in_stock'      => $src['in_stock'] ?? true,
                'category'      => $src['category'] ?? null,
                'url'           => $src['url'] ?? null,
                'image'         => $src['image'] ?? null,
                'score'         => round($fragment->score, 4),
                'matched_terms' => $matched,
                'snippet'       => $this->snippet((string) ($src['description'] ?? ''), $matched),
            ];
        }, $fragments);

        return json_encode(
            ['status' => 'ok', 'results' => $results, 'found' => true],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function clarificationResponse(CatalogBreadth $breadth, int $round): string
    {
        return json_encode(
            [
                'status'              => 'need_clarification',
                'reason'              => 'broad_query',
                'total_hits'          => $breadth->totalHits,
                'clarification_round' => $round,
                'price_ranges'        => $breadth->priceRanges,
                'price_stats'         => $breadth->priceStats,
                'sample_names'        => $breadth->sampleNames,
                'products'            => [],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  list<string>         $terms
     * @param  array<string, mixed> $source
     * @return list<string>
     */
    private function matchedTerms(array $terms, array $source): array
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($source['name'] ?? ''),
            (string) ($source['description'] ?? ''),
            (string) ($source['attributes'] ?? ''),
            (string) ($source['category'] ?? ''),
        ])));

        if ($haystack === '') {
            return [];
        }

        return array_values(array_filter(
            $terms,
            static fn (string $term): bool => mb_strpos($haystack, $term) !== false,
        ));
    }

    /**
     * @param list<string> $matched
     */
    private function snippet(string $description, array $matched): string
    {
        if ($description === '' || $matched === []) {
            return '';
        }

        $position = false;
        $found = '';

        foreach ($matched as $term) {
            $position = mb_stripos($description, $term);

            if ($position !== false) {
                $found = $term;

                break;
            }
        }

        if ($position === false) {
            return '';
        }

        $start = max(0, $position - self::SNIPPET_RADIUS);
        $length = self::SNIPPET_RADIUS * 2 + mb_strlen($found);

        $snippet = mb_trim(mb_substr($description, $start, $length));

        return ($start > 0 ? '…' : '')
            .$snippet
            .($start + $length < mb_strlen($description) ? '…' : '');
    }

    /**
     * At most one product per category, then top up by score. Keeps the original
     * relevance order within each pass, so the best match still leads.
     *
     * @param  list<RetrievedFragment> $fragments
     * @return list<RetrievedFragment>
     */
    private function spreadAcrossCategories(array $fragments, int $limit): array
    {
        $picked = [];
        $seenCategories = [];
        $leftovers = [];

        foreach ($fragments as $fragment) {
            $category = (string) ($fragment->metadata['category'] ?? '');

            if ($category !== '' && isset($seenCategories[$category])) {
                $leftovers[] = $fragment;

                continue;
            }

            $seenCategories[$category] = true;
            $picked[] = $fragment;

            if (count($picked) === $limit) {
                return $picked;
            }
        }

        foreach ($leftovers as $fragment) {
            if (count($picked) === $limit) {
                break;
            }

            $picked[] = $fragment;
        }

        return $picked;
    }
}
