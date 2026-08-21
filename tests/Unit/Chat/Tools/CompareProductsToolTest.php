<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Catalog\Contracts\PriceFormatterInterface;
use App\Services\Chat\Catalog\ProductImageUrlBuilder;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\DTO\Blocks\ProductComparisonBlock;
use App\Services\Chat\Presentation\BlockCollector;
use App\Services\Chat\Presentation\ProductCardMapper;
use App\Services\Chat\Tools\CompareProductsTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class CompareProductsToolTest extends TestCase
{
    private MockInterface $catalog;

    private BlockCollector $blocks;

    private CompareProductsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'opencart.card_language' => 'uk',
            'opencart.store_url' => 'http://shop.test',
            'opencart.image.strategy' => 'original',
            'opencart.image.ignore' => [],
            'opencart.card_attributes_limit' => 4,
            'opencart.comparison_labels.price' => 'Ціна',
            'opencart.comparison_labels.availability' => 'Наявність',
        ]);

        $this->catalog = Mockery::mock(OpenCartCatalogInterface::class);
        $this->blocks = new BlockCollector;

        /** @var OpenCartCatalogInterface $catalog */
        $catalog = $this->catalog;

        $this->tool = new CompareProductsTool(
            $catalog,
            new ProductCardMapper($this->priceFormatter(), new ProductImageUrlBuilder),
            $this->blocks,
        );
    }

    // ── contract ──────────────────────────────────────────────────────────────

    public function test_name_is_compare_products(): void
    {
        $this->assertSame('compare_products', $this->tool->getName());
    }

    public function test_schema_requires_product_ids(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertSame(['product_ids'], $schema['required']);
    }

    public function test_schema_product_ids_min_2_max_4(): void
    {
        $ids = $this->tool->getParameterSchema()['properties']['product_ids'];

        $this->assertSame(2, $ids['minItems']);
        $this->assertSame(4, $ids['maxItems']);
        $this->assertSame('integer', $ids['items']['type']);
    }

    public function test_the_model_cannot_choose_the_card_language(): void
    {
        $this->assertArrayNotHasKey('lang', $this->tool->getParameterSchema()['properties']);
    }

    // ── execute: all not found ────────────────────────────────────────────────

    public function test_execute_returns_found_false_when_all_products_missing(): void
    {
        $this->catalog->allows('getProductDetails')->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [1, 2]], $this->makeSession()),
            true,
        );

        $this->assertFalse($result['found']);
        $this->assertEqualsCanonicalizing([1, 2], $result['not_found_ids']);
    }

    public function test_no_block_is_emitted_when_nothing_was_found(): void
    {
        $this->catalog->allows('getProductDetails')->andReturn(null);

        $this->tool->execute(['product_ids' => [1, 2]], $this->makeSession());

        $this->assertSame([], $this->blocks->drain());
    }

    // ── execute: happy path ───────────────────────────────────────────────────

    public function test_execute_returns_found_true_with_two_products(): void
    {
        $this->allowProduct(10, 'Laptop A', 20000.0, null, true, [
            ['name' => 'RAM', 'value' => '8 GB'],
            ['name' => 'CPU', 'value' => 'Intel i5'],
        ]);
        $this->allowProduct(20, 'Laptop B', 25000.0, 22000.0, true, [
            ['name' => 'RAM', 'value' => '16 GB'],
            ['name' => 'CPU', 'value' => 'Intel i7'],
        ]);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession()),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['products']);
        $this->assertEmpty($result['not_found_ids']);
    }

    public function test_model_payload_carries_identity_and_stock_only(): void
    {
        $this->allowProduct(10, 'Laptop A', 20000.0, null, true);
        $this->allowProduct(20, 'Laptop B', 25000.0, 22000.0, false);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession()),
            true,
        );

        $this->assertSame(
            ['product_id', 'name', 'in_stock'],
            array_keys($result['products'][0]),
        );
        $this->assertTrue($result['products'][0]['in_stock']);
        $this->assertFalse($result['products'][1]['in_stock']);
    }

    public function test_prices_and_urls_are_withheld_from_the_model_so_prose_cannot_drift(): void
    {
        $this->allowProduct(10, 'Laptop A', 20000.0, null, true);
        $this->allowProduct(20, 'Laptop B', 25000.0, 22000.0, true);

        $payload = $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession());
        $result = json_decode($payload, true);

        $this->assertArrayNotHasKey('price', $result['products'][0]);
        $this->assertArrayNotHasKey('special_price', $result['products'][0]);
        $this->assertArrayNotHasKey('url', $result['products'][0]);
        $this->assertStringNotContainsString('20000', $payload);
    }

    // ── execute: attribute comparison table ───────────────────────────────────

    public function test_the_model_still_gets_the_attribute_table_to_reason_over(): void
    {
        $this->allowProduct(10, 'A', 1000.0, null, true, [
            ['name' => 'RAM', 'value' => '8 GB'],
            ['name' => 'CPU', 'value' => 'i5'],
        ]);
        $this->allowProduct(20, 'B', 2000.0, null, true, [
            ['name' => 'RAM', 'value' => '16 GB'],
            ['name' => 'CPU', 'value' => 'i7'],
        ]);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession()),
            true,
        );

        $comparison = $result['comparison'];

        $this->assertSame('8 GB', $comparison['RAM'][10]);
        $this->assertSame('16 GB', $comparison['RAM'][20]);
        $this->assertSame('i5', $comparison['CPU'][10]);
        $this->assertSame('i7', $comparison['CPU'][20]);
    }

    public function test_execute_fills_null_for_missing_attribute_in_one_product(): void
    {
        $this->allowProduct(10, 'A', 1000.0, null, true, [
            ['name' => 'RAM', 'value' => '8 GB'],
            ['name' => 'Battery', 'value' => '5000 mAh'],
        ]);
        $this->allowProduct(20, 'B', 2000.0, null, true, [
            ['name' => 'RAM', 'value' => '16 GB'],
        ]);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession()),
            true,
        );

        $this->assertSame('5000 mAh', $result['comparison']['Battery'][10]);
        $this->assertNull($result['comparison']['Battery'][20]);
    }

    // ── execute: emitted block ────────────────────────────────────────────────

    public function test_a_comparison_block_is_emitted_from_live_data(): void
    {
        $this->allowProduct(10, 'Laptop A', 20000.0, null, true, [['name' => 'RAM', 'value' => '8 GB']]);
        $this->allowProduct(20, 'Laptop B', 25000.0, 22000.0, true, [['name' => 'RAM', 'value' => '16 GB']]);

        $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession());

        $blocks = $this->blocks->drain();

        $this->assertCount(1, $blocks);
        $this->assertInstanceOf(ProductComparisonBlock::class, $blocks[0]);

        $payload = $blocks[0]->toArray();

        $this->assertSame([10, 20], array_column($payload['products'], 'id'));
        $this->assertSame(['Ціна', 'RAM', 'Наявність'], array_column($payload['rows'], 'label'));
        $this->assertSame(['20 000 ₴', '22 000 ₴'], $payload['rows'][0]['values']);
    }

    public function test_an_out_of_stock_product_still_gets_a_column(): void
    {
        $this->allowProduct(10, 'Laptop A', 20000.0, null, true);
        $this->allowProduct(20, 'Laptop B', 25000.0, null, false, availability: 'Немає в наявності');

        $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession());

        $payload = $this->blocks->drain()[0]->toArray();
        $rows = array_column($payload['rows'], 'values', 'label');

        $this->assertCount(2, $payload['products']);
        $this->assertSame(['В наявності', 'Немає в наявності'], $rows['Наявність']);
    }

    // ── execute: partial not found ────────────────────────────────────────────

    public function test_execute_includes_not_found_ids_when_some_missing(): void
    {
        $this->allowProduct(10, 'A', 1000.0, null, true);
        $this->catalog->allows('getProductDetails')->with(99, 'uk')->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 99]], $this->makeSession()),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertCount(1, $result['products']);
        $this->assertSame([99], $result['not_found_ids']);
    }

    // ── language ──────────────────────────────────────────────────────────────

    public function test_the_catalog_is_read_in_the_card_language_not_the_session_language(): void
    {
        $this->catalog->expects('getProductDetails')->with(1, 'uk')->andReturn(null);
        $this->catalog->expects('getProductDetails')->with(2, 'uk')->andReturn(null);

        $this->tool->execute(['product_ids' => [1, 2]], $this->makeSession('ru'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }

    /** @param list<array{name:string,value:string}> $attributes */
    private function allowProduct(
        int $productId,
        string $name,
        float $price,
        ?float $specialPrice,
        bool $inStock,
        array $attributes = [],
        string $availability = 'В наявності',
    ): void {
        $this->catalog->allows('getProductDetails')
            ->with($productId, 'uk')
            ->andReturn([
                'product_id' => $productId,
                'name' => $name,
                'description' => 'Description.',
                'price' => $price,
                'special_price' => $specialPrice,
                'in_stock' => $inStock,
                'quantity' => $inStock ? 10 : 0,
                'stock_status_id' => 5,
                'availability' => $availability,
                'categories' => ['Ноутбуки'],
                'attributes' => $attributes,
                'url' => "http://shop.test/product-{$productId}",
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
