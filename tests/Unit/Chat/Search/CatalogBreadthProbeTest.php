<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Search;

use App\Services\Chat\Search\CatalogBreadthProbe;
use App\Services\Chat\Search\HybridSearcher;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use RuntimeException;
use Tests\TestCase;

final class CatalogBreadthProbeTest extends TestCase
{
    private MockInterface $client;

    private CatalogBreadthProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opensearch.indices.products', 'chat_products');
        config()->set('bot.clarification.sample_size', 30);
        config()->set('bot.clarification.price_buckets', [700, 1500]);

        $this->client = Mockery::mock(Client::class);

        /** @var Client $client */
        $client = $this->client;

        $this->probe = new CatalogBreadthProbe($client);
    }

    // ── request shape ─────────────────────────────────────────────────────────

    public function test_every_term_must_match(): void
    {
        $body = $this->captureRequestBody();

        $this->assertSame('and', $body['query']['bool']['must'][0]['multi_match']['operator']);
    }

    public function test_probe_weighs_the_same_fields_as_the_real_search(): void
    {
        $body = $this->captureRequestBody();

        $this->assertSame(
            HybridSearcher::TEXT_FIELDS,
            $body['query']['bool']['must'][0]['multi_match']['fields'],
        );
    }

    public function test_out_of_stock_products_are_excluded(): void
    {
        $body = $this->captureRequestBody();

        $this->assertSame([['term' => ['in_stock' => true]]], $body['query']['bool']['filter']);
    }

    /**
     * Nothing in the chat is language-scoped (§6.1 architect); products are
     * deduplicated by identity instead.
     */
    public function test_no_language_filter_is_applied(): void
    {
        $body = $this->captureRequestBody();

        $this->assertStringNotContainsString('"lang"', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function test_results_are_collapsed_per_product(): void
    {
        $body = $this->captureRequestBody();

        $this->assertSame(['field' => 'product_id'], $body['collapse']);
        $this->assertSame(['cardinality' => ['field' => 'product_id']], $body['aggs']['unique_products']);
    }

    public function test_price_buckets_become_open_ended_ranges(): void
    {
        $body = $this->captureRequestBody();

        $this->assertSame(
            [['to' => 700.0], ['from' => 700.0, 'to' => 1500.0], ['from' => 1500.0]],
            $body['aggs']['price_ranges']['range']['ranges'],
        );
    }

    // ── response parsing ──────────────────────────────────────────────────────

    public function test_breadth_counts_distinct_products_not_documents(): void
    {
        $this->client->expects('search')->andReturn($this->response(uniqueProducts: 74, totalDocuments: 148));

        $this->assertSame(74, $this->probe->run('крем')->totalHits);
    }

    public function test_price_facet_and_sample_names_are_extracted(): void
    {
        $this->client->expects('search')->andReturn($this->response());

        $breadth = $this->probe->run('крем');

        $this->assertSame([['to' => 700.0, 'count' => 40], ['from' => 700.0, 'count' => 34]], $breadth->priceRanges);
        $this->assertSame(['min' => 60.0, 'max' => 8640.0, 'avg' => 1775.0], $breadth->priceStats);
        $this->assertSame(['Крем для обличчя Ramosu', 'Крем від зморшок Metacos'], $breadth->sampleNames);
    }

    public function test_empty_price_buckets_are_dropped(): void
    {
        $response = $this->response();
        $response['aggregations']['price_ranges']['buckets'][] = ['from' => 9000, 'products' => ['value' => 0]];

        $this->client->expects('search')->andReturn($response);

        $this->assertCount(2, $this->probe->run('крем')->priceRanges);
    }

    /**
     * `category` is stored per language, so a facet on it counts the same category
     * twice under two names and lets OpenCart's service categories outrank the
     * real ones. The question is built from names and price instead.
     */
    public function test_no_category_facet_is_requested(): void
    {
        $body = $this->captureRequestBody();

        $this->assertArrayNotHasKey('categories', $body['aggs']);
    }

    public function test_a_failing_search_yields_zero_breadth(): void
    {
        $this->client->expects('search')->andThrow(new RuntimeException('opensearch down'));

        $breadth = $this->probe->run('крем');

        $this->assertSame(0, $breadth->totalHits);
        $this->assertSame([], $breadth->sampleNames);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function captureRequestBody(): array
    {
        $captured = [];

        $this->client
            ->expects('search')
            ->andReturnUsing(function (array $params) use (&$captured): array {
                $captured = $params['body'];

                return $this->response();
            });

        $this->probe->run('крем');

        return $captured;
    }

    /** @return array<string, mixed> */
    private function response(int $uniqueProducts = 74, int $totalDocuments = 74): array
    {
        return [
            'hits' => [
                'total' => ['value' => $totalDocuments],
                'hits'  => [
                    ['_source' => ['product_id' => 1, 'name' => 'Крем для обличчя Ramosu']],
                    ['_source' => ['product_id' => 2, 'name' => 'Крем від зморшок Metacos']],
                ],
            ],
            'aggregations' => [
                'unique_products' => ['value' => $uniqueProducts],
                'price_ranges'    => ['buckets' => [
                    ['to' => 700, 'products' => ['value' => 40]],
                    ['from' => 700, 'products' => ['value' => 34]],
                ]],
                'price_stats' => ['count' => 74, 'min' => 60, 'max' => 8640, 'avg' => 1775],
            ],
        ];
    }
}
