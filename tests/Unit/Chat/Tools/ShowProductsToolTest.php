<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Catalog\Contracts\PriceFormatterInterface;
use App\Services\Chat\Catalog\ProductImageUrlBuilder;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\DTO\Blocks\ProductsBlock;
use App\Services\Chat\Presentation\BlockCollector;
use App\Services\Chat\Presentation\ProductCardMapper;
use App\Services\Chat\Tools\ShowProductsTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class ShowProductsToolTest extends TestCase
{
    private MockInterface $catalog;

    private BlockCollector $blocks;

    private ShowProductsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'opencart.card_language' => 'uk',
            'opencart.store_url' => 'https://shop.test',
            'opencart.image.strategy' => 'original',
            'opencart.image.ignore' => [],
            'opencart.card_attributes_limit' => 4,
        ]);

        $this->catalog = Mockery::mock(OpenCartCatalogInterface::class);
        $this->blocks = new BlockCollector;

        /** @var OpenCartCatalogInterface $catalog */
        $catalog = $this->catalog;

        $this->tool = new ShowProductsTool(
            $catalog,
            new ProductCardMapper($this->priceFormatter(), new ProductImageUrlBuilder),
            $this->blocks,
        );
    }

    // ── contract ──────────────────────────────────────────────────────────────

    public function test_name_is_show_products(): void
    {
        $this->assertSame('show_products', $this->tool->getName());
    }

    public function test_schema_requires_product_ids(): void
    {
        $this->assertSame(['product_ids'], $this->tool->getParameterSchema()['required']);
    }

    public function test_schema_allows_one_to_four_products(): void
    {
        $ids = $this->tool->getParameterSchema()['properties']['product_ids'];

        $this->assertSame(1, $ids['minItems']);
        $this->assertSame(4, $ids['maxItems']);
        $this->assertSame('integer', $ids['items']['type']);
    }

    public function test_the_model_cannot_choose_the_card_language(): void
    {
        $this->assertArrayNotHasKey('lang', $this->tool->getParameterSchema()['properties']);
    }

    // ── emitted block ─────────────────────────────────────────────────────────

    public function test_a_products_block_is_emitted_from_live_data(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5', 31990.0, 28990.0);
        $this->allowProduct(57, 'Lenovo IdeaPad 3');

        $this->tool->execute(['product_ids' => [42, 57]], $this->makeSession());

        $blocks = $this->blocks->drain();

        $this->assertCount(1, $blocks);
        $this->assertInstanceOf(ProductsBlock::class, $blocks[0]);

        $payload = $blocks[0]->toArray();

        $this->assertSame('products', $payload['type']);
        $this->assertSame([42, 57], array_column($payload['items'], 'id'));
        $this->assertSame('28 990 ₴', $payload['items'][0]['price']['current']);
        $this->assertSame('31 990 ₴', $payload['items'][0]['price']['old']);
    }

    public function test_card_order_follows_the_order_the_model_asked_for(): void
    {
        $this->allowProduct(57, 'Lenovo IdeaPad 3');
        $this->allowProduct(42, 'Acer Aspire 5');

        $this->tool->execute(['product_ids' => [57, 42]], $this->makeSession());

        $this->assertSame([57, 42], array_column($this->blocks->drain()[0]->toArray()['items'], 'id'));
    }

    public function test_no_more_than_four_cards_are_emitted(): void
    {
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->allowProduct($id, "Product {$id}");
        }

        $this->tool->execute(['product_ids' => [1, 2, 3, 4, 5]], $this->makeSession());

        $this->assertCount(4, $this->blocks->drain()[0]->toArray()['items']);
    }

    // ── availability filtering (FR-4.4) ───────────────────────────────────────

    public function test_out_of_stock_products_are_kept_out_of_the_block(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5');
        $this->allowProduct(57, 'Lenovo IdeaPad 3', inStock: false);

        $this->tool->execute(['product_ids' => [42, 57]], $this->makeSession());

        $this->assertSame([42], array_column($this->blocks->drain()[0]->toArray()['items'], 'id'));
    }

    public function test_out_of_stock_products_are_reported_back_to_the_model(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5');
        $this->allowProduct(57, 'Lenovo IdeaPad 3', inStock: false);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [42, 57]], $this->makeSession()),
            true,
        );

        $this->assertTrue($result['shown']);
        $this->assertSame([57], $result['out_of_stock_ids']);
    }

    public function test_missing_products_are_reported_back_to_the_model(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5');
        $this->catalog->allows('getProductDetails')->with(99, 'uk')->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [42, 99]], $this->makeSession()),
            true,
        );

        $this->assertSame([99], $result['not_found_ids']);
    }

    public function test_nothing_showable_emits_no_block_and_tells_the_model_why(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5', inStock: false);
        $this->catalog->allows('getProductDetails')->with(99, 'uk')->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [42, 99]], $this->makeSession()),
            true,
        );

        $this->assertSame([], $this->blocks->drain());
        $this->assertFalse($result['shown']);
        $this->assertSame('no_available_products', $result['reason']);
        $this->assertSame([42], $result['out_of_stock_ids']);
        $this->assertSame([99], $result['not_found_ids']);
    }

    // ── model-facing payload ──────────────────────────────────────────────────

    public function test_the_model_gets_ids_and_names_only(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5', 31990.0, 28990.0, attributes: [
            ['name' => 'RAM', 'value' => '16 ГБ'],
        ]);

        $payload = $this->tool->execute(['product_ids' => [42]], $this->makeSession());
        $result = json_decode($payload, true);

        $this->assertSame(['product_id', 'name'], array_keys($result['items'][0]));
        $this->assertSame(42, $result['items'][0]['product_id']);
        $this->assertSame('Acer Aspire 5', $result['items'][0]['name']);
    }

    public function test_card_facts_are_withheld_so_the_model_cannot_restate_them(): void
    {
        $this->allowProduct(42, 'Acer Aspire 5', 31990.0, 28990.0, attributes: [
            ['name' => 'RAM', 'value' => '16 ГБ'],
        ]);

        $payload = $this->tool->execute(['product_ids' => [42]], $this->makeSession());

        $this->assertStringNotContainsString('28990', $payload);
        $this->assertStringNotContainsString('shop.test', $payload);
        $this->assertStringNotContainsString('16 ГБ', $payload);
    }

    // ── language ──────────────────────────────────────────────────────────────

    public function test_the_catalog_is_read_in_the_card_language_not_the_session_language(): void
    {
        $this->catalog->expects('getProductDetails')->with(42, 'uk')->andReturn(null);

        $this->tool->execute(['product_ids' => [42]], $this->makeSession('ru'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }

    /** @param list<array{name:string,value:string}> $attributes */
    private function allowProduct(
        int $productId,
        string $name = 'Product',
        float $price = 20000.0,
        ?float $specialPrice = null,
        bool $inStock = true,
        array $attributes = [],
    ): void {
        $this->catalog->allows('getProductDetails')
            ->with($productId, 'uk')
            ->andReturn([
                'product_id' => $productId,
                'name' => $name,
                'description' => 'Опис.',
                'price' => $price,
                'special_price' => $specialPrice,
                'in_stock' => $inStock,
                'quantity' => $inStock ? 10 : 0,
                'stock_status_id' => 5,
                'availability' => $inStock ? 'В наявності' : 'Немає в наявності',
                'categories' => ['Ноутбуки'],
                'attributes' => $attributes,
                'url' => "https://shop.test/product-{$productId}",
                'image' => 'catalog/img.jpg',
            ]);
    }

    private function priceFormatter(): PriceFormatterInterface
    {
        return new class implements PriceFormatterInterface {
            public function format(float $amount): string
            {
                return number_format($amount, 0, '.', ' ').' ₴';
            }

            public function currencyCode(): string
            {
                return 'UAH';
            }
        };
    }
}
