<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Jobs\IndexProductJob;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
use App\Services\Chat\Search\TextChunker;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use Tests\TestCase;

final class IndexProductJobTest extends TestCase
{
    private MockInterface $catalog;

    private MockInterface $embeddingClient;

    private MockInterface $osClient;

    private OpenSearchIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = Mockery::mock(OpenCartCatalogInterface::class);

        $this->embeddingClient = Mockery::mock(EmbeddingClientInterface::class);
        $this->embeddingClient->allows('embed')->andReturnUsing(
            fn (array $texts) => array_fill(0, count($texts), [0.1, 0.2, 0.3]),
        );

        $this->osClient = Mockery::mock(Client::class);

        $this->indexer = new OpenSearchIndexer($this->osClient);
    }

    public function test_handle_deletes_existing_chunks_and_returns_when_product_missing(): void
    {
        $this->catalog->expects('getProductDocuments')->with(99)->andReturn([]);

        $this->osClient
            ->expects('deleteByQuery')
            ->with(Mockery::on(
                fn (array $params) => $params['index'] === $this->productsIndex()
                    && $params['body']['query']['term']['product_id'] === 99,
            ))
            ->andReturn([]);

        $this->osClient->shouldNotReceive('index');

        $this->runJob(99);
    }

    public function test_handle_indexes_short_description_as_a_single_chunk(): void
    {
        $this->catalog->expects('getProductDocuments')->with(42)->andReturn([$this->makeDocument(
            productId: 42,
            lang: 'ru',
            description: 'A short product description.',
        )]);

        $this->osClient->allows('deleteByQuery')->andReturn([]);

        $captured = [];
        $this->osClient->expects('index')->once()->andReturnUsing(function (array $params) use (&$captured) {
            $captured[] = $params;

            return ['result' => 'created'];
        });

        $this->runJob(42);

        $this->assertCount(1, $captured);
        $this->assertSame('42_ru_0', $captured[0]['id']);
        $this->assertSame(0, $captured[0]['body']['chunk_index']);
        $this->assertSame(42, $captured[0]['body']['product_id']);
    }

    public function test_handle_chunks_a_long_description_into_multiple_docs(): void
    {
        $longDescription = implode(' ', array_fill(0, 5000, 'слово'));

        $this->catalog->expects('getProductDocuments')->with(7)->andReturn([$this->makeDocument(
            productId: 7,
            lang: 'ru',
            description: $longDescription,
        )]);

        $this->osClient->allows('deleteByQuery')->andReturn([]);

        $captured = [];
        $this->osClient->allows('index')->andReturnUsing(function (array $params) use (&$captured) {
            $captured[] = $params;

            return ['result' => 'created'];
        });

        $this->runJob(7);

        $this->assertGreaterThan(1, count($captured));

        foreach ($captured as $i => $params) {
            $this->assertSame(7, $params['body']['product_id']);
            $this->assertSame($i, $params['body']['chunk_index']);
            $this->assertSame("7_ru_{$i}", $params['id']);
        }
    }

    public function test_handle_deletes_stale_chunks_before_reindexing(): void
    {
        $this->catalog->expects('getProductDocuments')->with(5)->andReturn([$this->makeDocument(
            productId: 5,
            lang: 'ru',
            description: 'Another short description.',
        )]);

        $this->osClient
            ->expects('deleteByQuery')
            ->with(Mockery::on(
                fn (array $params) => $params['body']['query']['term']['product_id'] === 5,
            ))
            ->once()
            ->andReturn([]);

        $this->osClient->allows('index')->andReturn(['result' => 'created']);

        $this->runJob(5);
    }

    public function test_handle_skips_untranslated_variant_with_no_content(): void
    {
        $this->catalog->expects('getProductDocuments')->with(4866)->andReturn([$this->makeDocument(
            productId: 4866,
            lang: 'uk',
            description: '',
            name: '',
            category: '',
        )]);

        $this->osClient->allows('deleteByQuery')->andReturn([]);
        $this->osClient->shouldNotReceive('index');
        $this->embeddingClient->shouldNotReceive('embed');

        $this->runJob(4866);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{product_id:int,lang:string,name:string,description:string,attributes:string,category:string,price:float,in_stock:bool,url:string,image:string}
     */
    private function makeDocument(
        int $productId,
        string $lang,
        string $description,
        string $name = 'Тестовый товар',
        string $category = 'Ноутбуки',
    ): array {
        return [
            'product_id' => $productId,
            'lang' => $lang,
            'name' => $name,
            'description' => $description,
            'attributes' => 'Цвет: чёрный',
            'category' => $category,
            'price' => 999.99,
            'in_stock' => true,
            'url' => 'http://shop.test/product',
            'image' => 'catalog/test.jpg',
        ];
    }

    private function productsIndex(): string
    {
        return (string) config('opensearch.indices.products');
    }

    private function runJob(int $productId): void
    {
        $job = new IndexProductJob($productId);

        /** @var OpenCartCatalogInterface $catalog */
        $catalog = $this->catalog;

        /** @var EmbeddingClientInterface $embeddingClient */
        $embeddingClient = $this->embeddingClient;

        $job->handle($catalog, $embeddingClient, $this->indexer, new TextChunker);
    }
}
