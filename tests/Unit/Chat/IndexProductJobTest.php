<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Jobs\IndexProductJob;
use App\Services\Chat\Contracts\EmbeddingClientInterface;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Search\OpenSearchIndexer;
use Mockery;
use Mockery\MockInterface;
use OpenSearch\Client;
use Tests\TestCase;

final class IndexProductJobTest extends TestCase
{
    private const int MAX_EMBED_CHARS = 8000;

    private MockInterface $catalog;

    private MockInterface $embeddingClient;

    private MockInterface $osClient;

    private OpenSearchIndexer $indexer;

    /** @var list<list<string>> Texts passed to each embed() call. */
    private array $embeddedTexts = [];

    /** @var list<array<string, mixed>> Params of each index() call. */
    private array $indexedDocuments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = Mockery::mock(OpenCartCatalogInterface::class);

        $this->embeddingClient = Mockery::mock(EmbeddingClientInterface::class);
        $this->embeddingClient->allows('embed')->andReturnUsing(function (array $texts) {
            $this->embeddedTexts[] = $texts;

            return array_fill(0, count($texts), [0.1, 0.2, 0.3]);
        });

        $this->osClient = Mockery::mock(Client::class);

        $this->indexer = new OpenSearchIndexer($this->osClient);
    }

    public function test_handle_deletes_existing_documents_and_returns_when_product_missing(): void
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

    public function test_handle_indexes_one_document_per_language(): void
    {
        $this->catalog->expects('getProductDocuments')->with(42)->andReturn([
            $this->makeDocument(productId: 42, lang: 'ru', description: 'Короткое описание товара.'),
            $this->makeDocument(productId: 42, lang: 'uk', description: 'Короткий опис товару.'),
        ]);

        $this->osClient->allows('deleteByQuery')->andReturn([]);
        $this->captureIndexedDocuments();

        $this->runJob(42);

        $this->assertCount(2, $this->indexedDocuments);
        $this->assertSame(['42_ru', '42_uk'], array_column($this->indexedDocuments, 'id'));

        foreach ($this->indexedDocuments as $params) {
            $this->assertSame(42, $params['body']['product_id']);
            $this->assertArrayNotHasKey('chunk_index', $params['body']);
        }

        // Both language variants go out in a single embed() call.
        $this->assertCount(1, $this->embeddedTexts);
        $this->assertCount(2, $this->embeddedTexts[0]);
    }

    public function test_handle_embeds_name_category_and_attributes_before_the_description(): void
    {
        $this->catalog->expects('getProductDocuments')->with(42)->andReturn([$this->makeDocument(
            productId: 42,
            lang: 'ru',
            description: 'Короткое описание товара.',
        )]);

        $this->osClient->allows('deleteByQuery')->andReturn([]);
        $this->osClient->allows('index')->andReturn(['result' => 'created']);

        $this->runJob(42);

        $this->assertSame(
            'Тестовый товар Ноутбуки Цвет: чёрный Короткое описание товара.',
            $this->embeddedTexts[0][0],
        );
    }

    public function test_handle_truncates_the_embedded_text_but_indexes_the_full_description(): void
    {
        $longDescription = str_repeat('слово ', 5000);

        $this->catalog->expects('getProductDocuments')->with(7)->andReturn([$this->makeDocument(
            productId: 7,
            lang: 'ru',
            description: $longDescription,
        )]);

        $this->osClient->allows('deleteByQuery')->andReturn([]);
        $this->captureIndexedDocuments();

        $this->runJob(7);

        $this->assertSame(self::MAX_EMBED_CHARS, mb_strlen($this->embeddedTexts[0][0]));

        // Still a single document, carrying the untruncated description.
        $this->assertCount(1, $this->indexedDocuments);
        $this->assertSame('7_ru', $this->indexedDocuments[0]['id']);
        $this->assertSame($longDescription, $this->indexedDocuments[0]['body']['description']);
    }

    public function test_handle_deletes_stale_documents_before_reindexing(): void
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

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Record every document handed to OpenSearch into $this->indexedDocuments. */
    private function captureIndexedDocuments(): void
    {
        $this->osClient->allows('index')->andReturnUsing(function (array $params) {
            $this->indexedDocuments[] = $params;

            return ['result' => 'created'];
        });
    }

    /**
     * @return array{product_id:int,lang:string,name:string,description:string,attributes:string,category:string,price:float,in_stock:bool,url:string,image:string}
     */
    private function makeDocument(int $productId, string $lang, string $description): array
    {
        return [
            'product_id' => $productId,
            'lang' => $lang,
            'name' => 'Тестовый товар',
            'description' => $description,
            'attributes' => 'Цвет: чёрный',
            'category' => 'Ноутбуки',
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

        $job->handle($catalog, $embeddingClient, $this->indexer);
    }
}
