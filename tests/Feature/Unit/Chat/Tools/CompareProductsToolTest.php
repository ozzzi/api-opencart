<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Tools\CompareProductsTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class CompareProductsToolTest extends TestCase
{
    private MockInterface $catalog;

    private CompareProductsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = Mockery::mock(OpenCartCatalogInterface::class);

        /** @var OpenCartCatalogInterface $catalog */
        $catalog = $this->catalog;

        $this->tool = new CompareProductsTool($catalog);
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

    public function test_schema_lang_is_optional(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertNotContains('lang', $schema['required']);
        $this->assertSame(['ru', 'uk'], $schema['properties']['lang']['enum']);
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

    // ── execute: happy path ───────────────────────────────────────────────────

    public function test_execute_returns_found_true_with_two_products(): void
    {
        $this->catalog->allows('getProductDetails')
            ->with(10, 'ru')
            ->andReturn($this->makeDetails(10, 'Laptop A', 20000.0, null, true, [
                ['name' => 'RAM', 'value' => '8 GB'],
                ['name' => 'CPU', 'value' => 'Intel i5'],
            ]));

        $this->catalog->allows('getProductDetails')
            ->with(20, 'ru')
            ->andReturn($this->makeDetails(20, 'Laptop B', 25000.0, 22000.0, true, [
                ['name' => 'RAM', 'value' => '16 GB'],
                ['name' => 'CPU', 'value' => 'Intel i7'],
            ]));

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession('ru')),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['products']);
        $this->assertEmpty($result['not_found_ids']);
    }

    public function test_execute_products_summary_contains_key_fields(): void
    {
        $this->catalog->allows('getProductDetails')
            ->with(10, 'ru')
            ->andReturn($this->makeDetails(10, 'Laptop A', 20000.0, null, true));

        $this->catalog->allows('getProductDetails')
            ->with(20, 'ru')
            ->andReturn($this->makeDetails(20, 'Laptop B', 25000.0, 22000.0, false));

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession('ru')),
            true,
        );

        $a = $result['products'][0];
        $this->assertSame(10, $a['product_id']);
        $this->assertSame('Laptop A', $a['name']);
        $this->assertEquals(20000.0, $a['price']);
        $this->assertNull($a['special_price']);
        $this->assertTrue($a['in_stock']);

        $b = $result['products'][1];
        $this->assertSame(20, $b['product_id']);
        $this->assertEquals(22000.0, $b['special_price']);
        $this->assertFalse($b['in_stock']);
    }

    // ── execute: attribute comparison table ───────────────────────────────────

    public function test_execute_builds_attribute_comparison_table(): void
    {
        $this->catalog->allows('getProductDetails')
            ->with(10, 'ru')
            ->andReturn($this->makeDetails(10, 'A', 1000.0, null, true, [
                ['name' => 'RAM', 'value' => '8 GB'],
                ['name' => 'CPU', 'value' => 'i5'],
            ]));

        $this->catalog->allows('getProductDetails')
            ->with(20, 'ru')
            ->andReturn($this->makeDetails(20, 'B', 2000.0, null, true, [
                ['name' => 'RAM', 'value' => '16 GB'],
                ['name' => 'CPU', 'value' => 'i7'],
            ]));

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession('ru')),
            true,
        );

        $comparison = $result['comparison'];

        $this->assertArrayHasKey('RAM', $comparison);
        $this->assertArrayHasKey('CPU', $comparison);
        $this->assertSame('8 GB', $comparison['RAM'][10]);
        $this->assertSame('16 GB', $comparison['RAM'][20]);
        $this->assertSame('i5', $comparison['CPU'][10]);
        $this->assertSame('i7', $comparison['CPU'][20]);
    }

    public function test_execute_fills_null_for_missing_attribute_in_one_product(): void
    {
        $this->catalog->allows('getProductDetails')
            ->with(10, 'ru')
            ->andReturn($this->makeDetails(10, 'A', 1000.0, null, true, [
                ['name' => 'RAM', 'value' => '8 GB'],
                ['name' => 'Battery', 'value' => '5000 mAh'],
            ]));

        $this->catalog->allows('getProductDetails')
            ->with(20, 'ru')
            ->andReturn($this->makeDetails(20, 'B', 2000.0, null, true, [
                ['name' => 'RAM', 'value' => '16 GB'],
                // No Battery attribute
            ]));

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 20]], $this->makeSession('ru')),
            true,
        );

        $comparison = $result['comparison'];

        $this->assertSame('5000 mAh', $comparison['Battery'][10]);
        $this->assertNull($comparison['Battery'][20]);
    }

    // ── execute: partial not found ────────────────────────────────────────────

    public function test_execute_includes_not_found_ids_when_some_missing(): void
    {
        $this->catalog->allows('getProductDetails')
            ->with(10, 'ru')
            ->andReturn($this->makeDetails(10, 'A', 1000.0, null, true));

        $this->catalog->allows('getProductDetails')
            ->with(99, 'ru')
            ->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [10, 99]], $this->makeSession('ru')),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertCount(1, $result['products']);
        $this->assertSame([99], $result['not_found_ids']);
    }

    // ── execute: language forwarding ─────────────────────────────────────────

    public function test_execute_uses_session_language_by_default(): void
    {
        $this->catalog->expects('getProductDetails')->with(1, 'uk')->andReturn(null);
        $this->catalog->expects('getProductDetails')->with(2, 'uk')->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [1, 2]], $this->makeSession('uk')),
            true,
        );

        $this->assertFalse($result['found']);
    }

    public function test_execute_uses_explicit_lang_over_session(): void
    {
        $this->catalog->expects('getProductDetails')->with(1, 'uk')->andReturn(null);
        $this->catalog->expects('getProductDetails')->with(2, 'uk')->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_ids' => [1, 2], 'lang' => 'uk'], $this->makeSession('ru')),
            true,
        );

        $this->assertFalse($result['found']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }

    /**
     * @param  list<array{name:string,value:string}>  $attributes
     * @return array<string, mixed>
     */
    private function makeDetails(
        int $productId,
        string $name,
        float $price,
        ?float $specialPrice,
        bool $inStock,
        array $attributes = [],
    ): array {
        return [
            'product_id'    => $productId,
            'name'          => $name,
            'description'   => 'Description.',
            'price'         => $price,
            'special_price' => $specialPrice,
            'in_stock'      => $inStock,
            'quantity'      => $inStock ? 10 : 0,
            'categories'    => ['Ноутбуки'],
            'attributes'    => $attributes,
            'url'           => "http://shop.test/product-{$productId}",
            'image'         => 'catalog/img.jpg',
        ];
    }
}
