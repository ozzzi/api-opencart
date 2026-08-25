<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\DTO\RetrievedFragment;
use App\Services\Chat\Tools\SearchProductsTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class SearchProductsToolTest extends TestCase
{
    private MockInterface $retrieval;

    private SearchProductsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->retrieval = Mockery::mock(RetrievalServiceInterface::class);

        /** @var RetrievalServiceInterface $service */
        $service = $this->retrieval;

        $this->tool = new SearchProductsTool($service);
    }

    // ── contract ──────────────────────────────────────────────────────────────

    public function test_name_is_search_products(): void
    {
        $this->assertSame('search_products', $this->tool->getName());
    }

    public function test_schema_requires_only_query(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertSame(['query'], $schema['required']);
    }

    public function test_schema_defines_all_optional_filters(): void
    {
        $properties = $this->tool->getParameterSchema()['properties'];

        $this->assertArrayHasKey('price_min', $properties);
        $this->assertArrayHasKey('price_max', $properties);
        $this->assertArrayHasKey('in_stock', $properties);
        $this->assertArrayHasKey('limit', $properties);
    }

    public function test_schema_limit_bounded_1_to_4(): void
    {
        $limit = $this->tool->getParameterSchema()['properties']['limit'];

        $this->assertSame(1, $limit['minimum']);
        $this->assertSame(4, $limit['maximum']);
    }

    // ── execute: no results ───────────────────────────────────────────────────

    public function test_execute_returns_found_false_when_no_fragments(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->andReturn([]);

        $result = json_decode(
            $this->tool->execute(['query' => 'laptop'], $this->makeSession('ru')),
            true,
        );

        $this->assertFalse($result['found']);
        $this->assertEmpty($result['results']);
    }

    // ── execute: with results ─────────────────────────────────────────────────

    public function test_execute_returns_mapped_product_fields(): void
    {
        $fragment = $this->makeFragment(42, 'Ноутбук Dell XPS', 25000.0, true, 'Ноутбуки', 'http://shop.test/dell-xps');

        $this->retrieval
            ->expects('retrieveProducts')
            ->andReturn([$fragment]);

        $result = json_decode(
            $this->tool->execute(['query' => 'ноутбук'], $this->makeSession('ru')),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertCount(1, $result['results']);

        $item = $result['results'][0];
        $this->assertSame(42, $item['product_id']);
        $this->assertSame('Ноутбук Dell XPS', $item['name']);
        $this->assertEquals(25000.0, $item['price']);
        $this->assertTrue($item['in_stock']);
        $this->assertSame('Ноутбуки', $item['category']);
        $this->assertSame('http://shop.test/dell-xps', $item['url']);
    }

    // ── execute: filter passing ───────────────────────────────────────────────

    /**
     * The catalog is indexed in Ukrainian only, so filtering by the visitor's
     * detected language would return nothing for a Russian-speaking visitor.
     */
    public function test_execute_passes_no_language_filter(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $query, array $filters): bool {
                return ! array_key_exists('lang', $filters);
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук'], $this->makeSession('ru'));
    }

    public function test_execute_passes_price_range_filters(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $query, array $filters): bool {
                return ($filters['price_min'] ?? 0) === 10000.0
                    && ($filters['price_max'] ?? 0) === 30000.0;
            })
            ->andReturn([]);

        $this->tool->execute(
            ['query' => 'ноутбук', 'price_min' => 10000, 'price_max' => 30000],
            $this->makeSession('ru'),
        );
    }

    public function test_execute_defaults_to_limit_4(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $q, array $f, int $topK): bool {
                return $topK === 4;
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук'], $this->makeSession('ru'));
    }

    public function test_execute_passes_custom_limit(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $q, array $f, int $topK): bool {
                return $topK === 2;
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук', 'limit' => 2], $this->makeSession('ru'));
    }

    public function test_execute_does_not_pass_in_stock_false_by_default(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $q, array $filters): bool {
                return ! array_key_exists('in_stock', $filters);
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук'], $this->makeSession('ru'));
    }

    public function test_execute_passes_in_stock_false_when_explicitly_set(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $q, array $filters): bool {
                return ($filters['in_stock'] ?? true) === false;
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук', 'in_stock' => false], $this->makeSession('ru'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }

    private function makeFragment(
        int $productId,
        string $name,
        float $price,
        bool $inStock,
        string $category,
        string $url,
        float $score = 0.88,
    ): RetrievedFragment {
        return new RetrievedFragment(
            source: 'products',
            id: "{$productId}_ru",
            content: $name,
            score: $score,
            metadata: [
                'product_id' => $productId,
                'name'       => $name,
                'price'      => $price,
                'in_stock'   => $inStock,
                'category'   => $category,
                'url'        => $url,
                'image'      => '',
            ],
        );
    }
}
