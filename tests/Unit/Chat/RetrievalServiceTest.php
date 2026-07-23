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
use Tests\TestCase;

final class RetrievalServiceTest extends TestCase
{
    private MockInterface $embeddingClient;

    private MockInterface $osClient;

    private RetrievalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['opensearch.distance_threshold' => 0.0]);

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
        config(['opensearch.distance_threshold' => 0.8]);

        $this->osClient->expects('search')->andReturn(['hits' => ['hits' => [
            $this->hit('1_ru', 0.95, 1),
            $this->hit('2_ru', 0.50, 2),
        ]]]);

        $fragments = $this->service->retrieveProducts('query', [], 5);

        $this->assertCount(1, $fragments);
        $this->assertSame('1_ru', $fragments[0]->id);
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
