<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ClarificationGateInterface;
use App\Services\Chat\Contracts\RetrievalServiceInterface;
use App\Services\Chat\DTO\CatalogBreadth;
use App\Services\Chat\DTO\ClarificationDecision;
use App\Services\Chat\DTO\RetrievedFragment;
use App\Services\Chat\Tools\SearchProductsTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class SearchProductsToolTest extends TestCase
{
    private MockInterface $retrieval;

    private MockInterface $gate;

    private SearchProductsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->retrieval = Mockery::mock(RetrievalServiceInterface::class);
        $this->gate = Mockery::mock(ClarificationGateInterface::class);

        // Most cases exercise the ordinary path; the gate gets its own section.
        $this->gate->allows('evaluate')->andReturn(ClarificationDecision::proceed())->byDefault();

        /** @var RetrievalServiceInterface $service */
        $service = $this->retrieval;
        /** @var ClarificationGateInterface $gate */
        $gate = $this->gate;

        $this->tool = new SearchProductsTool($service, $gate);
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
        $this->assertArrayHasKey('skip_clarification', $properties);
        $this->assertArrayHasKey('limit', $properties);
    }

    /**
     * Out-of-stock products are never offered (FR-4.4) and RetrievalService forces
     * the filter regardless, so advertising the argument only misleads the model.
     */
    public function test_schema_does_not_advertise_in_stock(): void
    {
        $this->assertArrayNotHasKey('in_stock', $this->tool->getParameterSchema()['properties']);
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

    public function test_execute_does_not_pass_in_stock_filter(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $q, array $filters): bool {
                return ! array_key_exists('in_stock', $filters);
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук'], $this->makeSession('ru'));
    }

    public function test_execute_ignores_a_stray_in_stock_argument(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(function (string $q, array $filters): bool {
                return ! array_key_exists('in_stock', $filters);
            })
            ->andReturn([]);

        $this->tool->execute(['query' => 'ноутбук', 'in_stock' => false], $this->makeSession('ru'));
    }

    // ── execute: clarification gate ───────────────────────────────────────────

    public function test_execute_returns_breadth_and_no_products_when_gate_asks(): void
    {
        $this->gate
            ->expects('evaluate')
            ->andReturn(ClarificationDecision::ask($this->makeBreadth(), 1));

        $this->retrieval->shouldNotReceive('retrieveProducts');

        $result = json_decode(
            $this->tool->execute(['query' => 'браслет'], $this->makeSession('ru')),
            true,
        );

        $this->assertSame('need_clarification', $result['status']);
        $this->assertSame('broad_query', $result['reason']);
        $this->assertSame(74, $result['total_hits']);
        $this->assertSame(1, $result['clarification_round']);
        $this->assertSame([], $result['products']);
        $this->assertContains('Браслет "Кобра"', $result['sample_names']);
        $this->assertArrayNotHasKey('categories', $result);
    }

    public function test_execute_marks_an_ordinary_answer_as_ok(): void
    {
        $this->retrieval
            ->expects('retrieveProducts')
            ->andReturn([$this->makeFragment(42, 'Браслет "Кобра"', 180.0, true, 'Браслети', 'http://shop.test/kobra')]);

        $result = json_decode(
            $this->tool->execute(['query' => 'браслет кобра'], $this->makeSession('ru')),
            true,
        );

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['found']);
    }

    public function test_execute_spreads_across_categories_when_rounds_are_spent(): void
    {
        $this->gate
            ->expects('evaluate')
            ->andReturn(ClarificationDecision::diversify());

        // Asks for three times the limit so there is something to spread across.
        $this->retrieval
            ->expects('retrieveProducts')
            ->withArgs(fn (string $q, array $f, int $topK): bool => $topK === 6)
            ->andReturn([
                $this->makeFragment(1, 'Браслет А', 100.0, true, 'Браслети', 'http://s/1', 0.9),
                $this->makeFragment(2, 'Браслет Б', 110.0, true, 'Браслети', 'http://s/2', 0.8),
                $this->makeFragment(3, 'Темляк', 120.0, true, 'Темляки', 'http://s/3', 0.7),
                $this->makeFragment(4, 'Брелок', 130.0, true, 'Брелки', 'http://s/4', 0.6),
            ]);

        $result = json_decode(
            $this->tool->execute(['query' => 'браслет', 'limit' => 2], $this->makeSession('ru')),
            true,
        );

        $categories = array_column($result['results'], 'category');

        $this->assertSame(['Браслети', 'Темляки'], $categories);
    }

    public function test_execute_tops_up_from_leftovers_when_categories_run_out(): void
    {
        $this->gate
            ->expects('evaluate')
            ->andReturn(ClarificationDecision::diversify());

        $this->retrieval
            ->expects('retrieveProducts')
            ->andReturn([
                $this->makeFragment(1, 'Браслет А', 100.0, true, 'Браслети', 'http://s/1', 0.9),
                $this->makeFragment(2, 'Браслет Б', 110.0, true, 'Браслети', 'http://s/2', 0.8),
            ]);

        $result = json_decode(
            $this->tool->execute(['query' => 'браслет', 'limit' => 2], $this->makeSession('ru')),
            true,
        );

        $this->assertCount(2, $result['results']);
        $this->assertSame([1, 2], array_column($result['results'], 'product_id'));
    }

    // ── evidence for grounding ────────────────────────────────────────────────

    /**
     * Ranking alone cannot tell the assistant whether the lanyard it is about to
     * recommend actually has a skull on it. Without this the model receives a
     * name and a price and has to take the shortlist on faith.
     */
    public function test_results_report_which_query_terms_the_product_matched(): void
    {
        config()->set('bot.clarification.stop_words', []);

        $this->retrieval->allows('retrieveProducts')->andReturn([
            $this->makeFragment(
                1,
                'Темляк "Мумія"',
                240.0,
                true,
                'Темляки',
                '/temlyak-mumiya',
                description: 'Плетений темляк, прикрашений намистом-черепом ручної роботи.',
            ),
            $this->makeFragment(2, 'Темляк паракордовий', 180.0, true, 'Темляки', '/temlyak-550'),
        ]);

        $results = json_decode(
            $this->tool->execute(['query' => 'темляк с черепом'], new ChatSession()),
            true,
        )['results'];

        $this->assertSame(['темляк', 'черепом'], $results[0]['matched_terms']);
        $this->assertSame(['темляк'], $results[1]['matched_terms']);
    }

    public function test_a_snippet_shows_the_matched_text(): void
    {
        config()->set('bot.clarification.stop_words', []);

        $this->retrieval->allows('retrieveProducts')->andReturn([
            $this->makeFragment(
                1,
                'Темляк "Мумія"',
                240.0,
                true,
                'Темляки',
                '/temlyak-mumiya',
                description: 'Плетений темляк, прикрашений намистом-черепом ручної роботи.',
            ),
        ]);

        $results = json_decode(
            $this->tool->execute(['query' => 'темляк с черепом'], new ChatSession()),
            true,
        )['results'];

        $this->assertStringContainsString('намистом-черепом', $results[0]['snippet']);
    }

    public function test_a_product_with_no_description_gets_an_empty_snippet(): void
    {
        config()->set('bot.clarification.stop_words', []);

        $this->retrieval->allows('retrieveProducts')->andReturn([
            $this->makeFragment(2, 'Темляк паракордовий', 180.0, true, 'Темляки', '/temlyak-550'),
        ]);

        $results = json_decode(
            $this->tool->execute(['query' => 'темляк с черепом'], new ChatSession()),
            true,
        )['results'];

        $this->assertSame('', $results[0]['snippet']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }


    private function makeBreadth(): CatalogBreadth
    {
        return new CatalogBreadth(
            totalHits: 74,
            priceRanges: [['to' => 150.0, 'count' => 40], ['from' => 150.0, 'count' => 34]],
            priceStats: ['min' => 60.0, 'max' => 420.0, 'avg' => 175.0],
            sampleNames: ['Браслет "Кобра"', 'Браслет "Змійка"', 'Браслет "Піранья"'],
        );
    }

    private function makeFragment(
        int $productId,
        string $name,
        float $price,
        bool $inStock,
        string $category,
        string $url,
        float $score = 0.88,
        string $description = '',
    ): RetrievedFragment {
        return new RetrievedFragment(
            source: 'products',
            id: "{$productId}_ru",
            content: $name,
            score: $score,
            metadata: [
                'product_id'  => $productId,
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'in_stock'    => $inStock,
                'category'    => $category,
                'url'         => $url,
                'image'       => '',
            ],
        );
    }
}
