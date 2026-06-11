<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Tools\GetProductDetailsTool;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class GetProductDetailsToolTest extends TestCase
{
    private MockInterface $catalog;

    private GetProductDetailsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = Mockery::mock(OpenCartCatalogInterface::class);

        /** @var OpenCartCatalogInterface $catalog */
        $catalog = $this->catalog;

        $this->tool = new GetProductDetailsTool($catalog);
    }

    // ── contract ──────────────────────────────────────────────────────────────

    public function test_name_is_get_product_details(): void
    {
        $this->assertSame('get_product_details', $this->tool->getName());
    }

    public function test_schema_requires_product_id(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertSame(['product_id'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['product_id']['type']);
        $this->assertSame(1, $schema['properties']['product_id']['minimum']);
    }

    public function test_schema_lang_is_optional_with_enum(): void
    {
        $schema = $this->tool->getParameterSchema();

        $this->assertNotContains('lang', $schema['required']);
        $this->assertSame(['ru', 'uk'], $schema['properties']['lang']['enum']);
    }

    // ── execute: product not found ────────────────────────────────────────────

    public function test_execute_returns_found_false_when_product_missing(): void
    {
        $this->catalog
            ->expects('getProductDetails')
            ->with(99, 'ru')
            ->andReturn(null);

        $result = json_decode(
            $this->tool->execute(['product_id' => 99], $this->makeSession('ru')),
            true,
        );

        $this->assertFalse($result['found']);
        $this->assertSame(99, $result['product_id']);
    }

    // ── execute: product found ────────────────────────────────────────────────

    public function test_execute_returns_found_true_with_all_product_fields(): void
    {
        $details = $this->makeDetails(42, 'Ноутбук Dell XPS 15', 28000.0, 25500.0, true);

        $this->catalog
            ->expects('getProductDetails')
            ->with(42, 'ru')
            ->andReturn($details);

        $result = json_decode(
            $this->tool->execute(['product_id' => 42], $this->makeSession('ru')),
            true,
        );

        $this->assertTrue($result['found']);
        $this->assertSame(42, $result['product_id']);
        $this->assertSame('Ноутбук Dell XPS 15', $result['name']);
        $this->assertEquals(28000.0, $result['price']);
        $this->assertEquals(25500.0, $result['special_price']);
        $this->assertTrue($result['in_stock']);
        $this->assertSame(5, $result['quantity']);
        $this->assertSame(['Ноутбуки'], $result['categories']);
        $this->assertNotEmpty($result['attributes']);
        $this->assertSame('http://shop.test/dell-xps', $result['url']);
    }

    public function test_execute_includes_null_special_price_when_no_discount(): void
    {
        $details = $this->makeDetails(10, 'Мышь Logitech', 500.0, null, true);

        $this->catalog
            ->expects('getProductDetails')
            ->andReturn($details);

        $result = json_decode(
            $this->tool->execute(['product_id' => 10], $this->makeSession('ru')),
            true,
        );

        $this->assertNull($result['special_price']);
    }

    // ── execute: out of stock ─────────────────────────────────────────────────

    public function test_execute_returns_in_stock_false_for_unavailable_product(): void
    {
        $details = $this->makeDetails(7, 'Планшет Samsung', 12000.0, null, false);

        $this->catalog
            ->expects('getProductDetails')
            ->andReturn($details);

        $result = json_decode(
            $this->tool->execute(['product_id' => 7], $this->makeSession('ru')),
            true,
        );

        $this->assertFalse($result['in_stock']);
    }

    // ── execute: language forwarding ─────────────────────────────────────────

    public function test_execute_uses_session_language_by_default(): void
    {
        $this->catalog
            ->expects('getProductDetails')
            ->with(1, 'uk')
            ->andReturn(null);

        $this->tool->execute(['product_id' => 1], $this->makeSession('uk'));
    }

    public function test_execute_uses_explicit_lang_over_session(): void
    {
        $this->catalog
            ->expects('getProductDetails')
            ->with(1, 'uk')
            ->andReturn(null);

        $this->tool->execute(['product_id' => 1, 'lang' => 'uk'], $this->makeSession('ru'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeSession(string $lang = 'ru'): ChatSession
    {
        return ChatSession::factory()->make(['language' => $lang]);
    }

    /**
     * @return array{product_id:int,name:string,description:string,price:float,special_price:float|null,in_stock:bool,quantity:int,categories:list<string>,attributes:list<array{name:string,value:string}>,url:string,image:string}
     */
    private function makeDetails(
        int $productId,
        string $name,
        float $price,
        ?float $specialPrice,
        bool $inStock,
    ): array {
        return [
            'product_id'    => $productId,
            'name'          => $name,
            'description'   => 'Some description.',
            'price'         => $price,
            'special_price' => $specialPrice,
            'in_stock'      => $inStock,
            'quantity'      => $inStock ? 5 : 0,
            'categories'    => ['Ноутбуки'],
            'attributes'    => [['name' => 'RAM', 'value' => '16 GB']],
            'url'           => 'http://shop.test/dell-xps',
            'image'         => 'catalog/dell.jpg',
        ];
    }
}
