<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Presentation;

use App\Services\Chat\Catalog\Contracts\PriceFormatterInterface;
use App\Services\Chat\Catalog\ProductImageUrlBuilder;
use App\Services\Chat\Presentation\ProductCardMapper;
use Tests\TestCase;

final class ProductCardMapperTest extends TestCase
{
    private ProductCardMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'opencart.store_url' => 'https://shop.test',
            'opencart.image.strategy' => 'original',
            'opencart.image.ignore' => [],
            'opencart.card_attributes_limit' => 4,
            'opencart.comparison_labels.price' => 'Ціна',
            'opencart.comparison_labels.availability' => 'Наявність',
        ]);

        $this->mapper = new ProductCardMapper(
            new class implements PriceFormatterInterface {
                public function format(float $amount): string
                {
                    return number_format($amount, 0, '.', ' ').' ₴';
                }

                public function currencyCode(): string
                {
                    return 'UAH';
                }
            },
            new ProductImageUrlBuilder,
        );
    }

    // ── product cards ─────────────────────────────────────────────────────────

    public function test_card_serializes_to_the_documented_key_set(): void
    {
        $card = $this->mapper->toCard($this->details())->toArray();

        $this->assertSame(
            ['id', 'name', 'url', 'image', 'price', 'in_stock', 'availability', 'attributes'],
            array_keys($card),
        );
        $this->assertSame(
            ['current', 'current_raw', 'old', 'old_raw', 'currency'],
            array_keys($card['price']),
        );
    }

    public function test_card_carries_live_catalog_values(): void
    {
        $card = $this->mapper->toCard($this->details())->toArray();

        $this->assertSame(42, $card['id']);
        $this->assertSame('Acer Aspire 5', $card['name']);
        $this->assertSame('https://shop.test/acer-aspire-5', $card['url']);
        $this->assertSame('https://shop.test/image/catalog/acer.jpg', $card['image']);
        $this->assertTrue($card['in_stock']);
        $this->assertSame('В наявності', $card['availability']);
    }

    public function test_an_active_special_becomes_the_current_price_and_pushes_the_regular_price_to_old(): void
    {
        $card = $this->mapper->toCard($this->details(price: 31990.0, special: 28990.0))->toArray();

        $this->assertSame('28 990 ₴', $card['price']['current']);
        $this->assertSame(28990.0, $card['price']['current_raw']);
        $this->assertSame('31 990 ₴', $card['price']['old']);
        $this->assertSame(31990.0, $card['price']['old_raw']);
        $this->assertSame('UAH', $card['price']['currency']);
    }

    public function test_without_a_special_there_is_no_old_price(): void
    {
        $card = $this->mapper->toCard($this->details(price: 31990.0, special: null))->toArray();

        $this->assertSame('31 990 ₴', $card['price']['current']);
        $this->assertNull($card['price']['old']);
        $this->assertNull($card['price']['old_raw']);
    }

    public function test_card_attributes_are_capped_and_relabelled(): void
    {
        $card = $this->mapper->toCard($this->details(attributes: [
            ['name' => 'Процесор', 'value' => 'Ryzen 5'],
            ['name' => 'RAM', 'value' => '16 ГБ'],
            ['name' => 'Накопичувач', 'value' => '512 ГБ SSD'],
            ['name' => 'Екран', 'value' => '15.6"'],
            ['name' => 'Вага', 'value' => '1.8 кг'],
        ]))->toArray();

        $this->assertCount(4, $card['attributes']);
        $this->assertSame(['label' => 'Процесор', 'value' => 'Ryzen 5'], $card['attributes'][0]);
    }

    public function test_attributes_without_a_value_are_dropped(): void
    {
        $card = $this->mapper->toCard($this->details(attributes: [
            ['name' => 'Процесор', 'value' => 'Ryzen 5'],
            ['name' => 'Колір', 'value' => '  '],
        ]))->toArray();

        $this->assertSame(['Процесор'], array_column($card['attributes'], 'label'));
    }

    public function test_products_block_wraps_the_cards(): void
    {
        $block = $this->mapper->toProductsBlock([$this->details(), $this->details(id: 57)])->toArray();

        $this->assertSame('products', $block['type']);
        $this->assertSame([42, 57], array_column($block['items'], 'id'));
    }

    public function test_an_empty_products_block_encodes_as_a_json_array(): void
    {
        $json = json_encode($this->mapper->toProductsBlock([])->toArray());

        $this->assertSame('{"type":"products","items":[]}', $json);
    }

    // ── comparison ────────────────────────────────────────────────────────────

    public function test_comparison_columns_carry_identity_only(): void
    {
        $block = $this->mapper->toComparisonBlock([
            $this->details(),
            $this->details(id: 57, name: 'Lenovo IdeaPad 3'),
        ])->toArray();

        $this->assertSame('product_comparison', $block['type']);
        $this->assertSame(['id', 'name', 'url', 'image'], array_keys($block['products'][0]));
        $this->assertSame([42, 57], array_column($block['products'], 'id'));
    }

    public function test_comparison_rows_run_price_then_attributes_then_availability(): void
    {
        $block = $this->mapper->toComparisonBlock([
            $this->details(attributes: [['name' => 'Процесор', 'value' => 'Ryzen 5']]),
            $this->details(id: 57, attributes: [['name' => 'Процесор', 'value' => 'Core i5']]),
        ])->toArray();

        $this->assertSame(['Ціна', 'Процесор', 'Наявність'], array_column($block['rows'], 'label'));
        $this->assertSame(['Ryzen 5', 'Core i5'], $block['rows'][1]['values']);
    }

    public function test_a_product_missing_an_attribute_contributes_null_in_that_row(): void
    {
        $block = $this->mapper->toComparisonBlock([
            $this->details(attributes: [['name' => 'Процесор', 'value' => 'Ryzen 5']]),
            $this->details(id: 57, attributes: [['name' => 'Вага', 'value' => '1.8 кг']]),
        ])->toArray();

        $rows = array_column($block['rows'], 'values', 'label');

        $this->assertSame(['Ryzen 5', null], $rows['Процесор']);
        $this->assertSame([null, '1.8 кг'], $rows['Вага']);
    }

    public function test_out_of_stock_products_stay_in_a_comparison(): void
    {
        $block = $this->mapper->toComparisonBlock([
            $this->details(),
            $this->details(id: 57, inStock: false, availability: 'Немає в наявності'),
        ])->toArray();

        $rows = array_column($block['rows'], 'values', 'label');

        $this->assertCount(2, $block['products']);
        $this->assertSame(['В наявності', 'Немає в наявності'], $rows['Наявність']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  list<array{name:string,value:string}> $attributes
     * @return array<string, mixed>
     */
    private function details(
        int $id = 42,
        string $name = 'Acer Aspire 5',
        float $price = 28990.0,
        ?float $special = null,
        bool $inStock = true,
        string $availability = 'В наявності',
        array $attributes = [],
    ): array {
        return [
            'product_id' => $id,
            'name' => $name,
            'description' => 'Опис',
            'price' => $price,
            'special_price' => $special,
            'in_stock' => $inStock,
            'quantity' => $inStock ? 5 : 0,
            'stock_status_id' => 5,
            'availability' => $availability,
            'categories' => ['Ноутбуки'],
            'attributes' => $attributes,
            'url' => 'https://shop.test/acer-aspire-5',
            'image' => 'catalog/acer.jpg',
        ];
    }
}
