<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\RetrievalService;
use App\Services\Chat\Search\HybridSearcher;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use OpenSearch\Namespaces\SearchPipelineNamespace;
use RuntimeException;
use Tests\TestCase;

final class RetrievalServiceTest extends TestCase
{
    private MockInterface $embeddingClient;

    private MockInterface $osClient;

    private RetrievalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bot.retrieval.min_score' => 0.0]);

        $this->embeddingClient = Mockery::mock(EmbeddingClientInterface::class);
        $this->embeddingClient->allows('embed')->andReturn([[0.1, 0.2, 0.3]]);

        $this->osClient = Mockery::mock(Client::class);

        // Pipeline available -> HybridSearcher takes the single native `hybrid` query path.
        $pipeline = Mockery::mock(SearchPipelineNamespace::class);
        $pipeline->allows('get')->andReturn(['id' => 'chat-hybrid']);
        $this->osClient->allows('searchPipeline')->andReturn($pipeline);

        /** @var EmbeddingClientInterface $embeddingClient */
        $embeddingClient = $this->embeddingClient;

        $this->service = new RetrievalService($embeddingClient, new HybridSearcher($this->osClient));
    }

    public function test_retrieve_products_requests_exactly_top_k_and_keeps_every_hit(): void
    {
        // One document per product+lang — nothing to collapse.
        $hits = [
            $this->hit('1_ru', 0.95, 1),
            $this->hit('2_ru', 0.85, 2),
            $this->hit('3_ru', 0.70, 3),
        ];

        $this->osClient
            ->expects('search')
            ->with(Mockery::on(fn (array $params) => $params['body']['size'] === 3))
            ->andReturn(['hits' => ['hits' => $hits]]);

        $fragments = $this->service->retrieveProducts('query', [], 3);

        $this->assertCount(3, $fragments);
        $this->assertSame([1, 2, 3], array_map(
            static fn ($fragment): int => $fragment->metadata['product_id'],
            $fragments,
        ));
        $this->assertSame('1_ru', $fragments[0]->id);
    }

    public function test_retrieve_products_drops_hits_below_the_score_threshold(): void
    {
        config(['bot.retrieval.min_score' => 0.8]);

        $this->osClient->expects('search')->andReturn(['hits' => ['hits' => [
            $this->hit('1_ru', 0.95, 1),
            $this->hit('2_ru', 0.50, 2),
        ]]]);

        $fragments = $this->service->retrieveProducts('query', [], 5);

        $this->assertCount(1, $fragments);
        $this->assertSame('1_ru', $fragments[0]->id);
    }

    public function test_rrf_fallback_ignores_the_normalized_score_threshold(): void
    {
        config(['bot.retrieval.min_score' => 0.8]);

        $osClient = Mockery::mock(Client::class);

        // Pipeline missing -> HybridSearcher falls back to app-side RRF, whose
        // scores max out around 1/61 and must not be compared to the threshold.
        $pipeline = Mockery::mock(SearchPipelineNamespace::class);
        $pipeline->allows('get')->andThrow(new RuntimeException('no such pipeline'));
        $osClient->allows('searchPipeline')->andReturn($pipeline);

        $osClient->expects('search')->twice()->andReturn(
            ['hits' => ['hits' => [$this->hit('1_ru', 12.4, 1)]]],
            ['hits' => ['hits' => [$this->hit('1_ru', 0.87, 1)]]],
        );

        /** @var EmbeddingClientInterface $embeddingClient */
        $embeddingClient = $this->embeddingClient;

        $service = new RetrievalService($embeddingClient, new HybridSearcher($osClient));

        $fragments = $service->retrieveProducts('query', [], 3);

        $this->assertCount(1, $fragments);
        $this->assertLessThan(0.8, $fragments[0]->score);
    }

    public function test_bm25_query_covers_language_specific_subfields(): void
    {
        $captured = [];

        $this->osClient
            ->expects('search')
            ->with(Mockery::on(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            }))
            ->andReturn(['hits' => ['hits' => []]]);

        $this->service->retrieveProducts('паракорд', [], 3);

        $fields = $captured['body']['query']['hybrid']['queries'][0]['bool']['must'][0]['multi_match']['fields'];

        $this->assertContains('name.uk^3', $fields);
        $this->assertContains('name.ru^3', $fields);
        $this->assertContains('description.uk', $fields);
        $this->assertContains('category.ru^2', $fields);
    }

    public function test_retrieve_kb_returns_every_chunk(): void
    {
        $hits = [
            $this->hit('10_0', 0.9, null, 'kb'),
            $this->hit('10_1', 0.8, null, 'kb'),
        ];

        $this->osClient
            ->expects('search')
            ->andReturn(['hits' => ['hits' => $hits]]);

        $fragments = $this->service->retrieveKb('query', 'ru', 5);

        $this->assertCount(2, $fragments);
    }

    /** @return array{_id:string,_score:float,_source:array<string,mixed>} */
    private function hit(string $id, float $score, ?int $productId, string $type = 'products'): array
    {
        $source = $type === 'products'
            ? ['product_id' => $productId, 'name' => 'Product', 'description' => 'desc', 'in_stock' => true]
            : ['article_id' => 10, 'title' => 'KB', 'content' => 'content'];

        return ['_id' => $id, '_score' => $score, '_source' => $source];
    }
}
