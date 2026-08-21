<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Catalog;

use App\Services\Chat\Catalog\ProductImageUrlBuilder;
use Tests\TestCase;

final class ProductImageUrlBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'opencart.store_url' => 'https://shop.test',
            'opencart.image.strategy' => 'original',
            'opencart.image.width' => 500,
            'opencart.image.height' => 500,
            'opencart.image.ignore' => ['no_image.png', 'placeholder.png'],
        ]);
    }

    public function test_null_path_has_no_url(): void
    {
        $this->assertNull($this->builder()->build(null));
    }

    public function test_empty_path_has_no_url(): void
    {
        $this->assertNull($this->builder()->build('  '));
    }

    public function test_opencart_placeholder_has_no_url_so_the_widget_draws_its_own(): void
    {
        $this->assertNull($this->builder()->build('catalog/no_image.png'));
    }

    public function test_original_strategy_points_at_the_uploaded_file(): void
    {
        $this->assertSame(
            'https://shop.test/image/catalog/demo/hp_1.jpg',
            $this->builder()->build('catalog/demo/hp_1.jpg'),
        );
    }

    public function test_cache_strategy_mirrors_opencart_resize_naming(): void
    {
        config(['opencart.image.strategy' => 'cache']);

        $this->assertSame(
            'https://shop.test/image/cache/catalog/demo/hp_1-500x500.jpg',
            $this->builder()->build('catalog/demo/hp_1.jpg'),
        );
    }

    public function test_extensionless_path_is_left_alone_under_the_cache_strategy(): void
    {
        config(['opencart.image.strategy' => 'cache']);

        $this->assertSame(
            'https://shop.test/image/cache/catalog/banner',
            $this->builder()->build('catalog/banner'),
        );
    }

    public function test_spaces_and_cyrillic_are_encoded_per_segment(): void
    {
        $this->assertSame(
            'https://shop.test/image/catalog/%D0%BD%D0%BE%D1%83%D1%82%D0%B1%D1%83%D0%BA%201.jpg',
            $this->builder()->build('catalog/ноутбук 1.jpg'),
        );
    }

    public function test_trailing_slashes_do_not_double_up(): void
    {
        config(['opencart.store_url' => 'https://shop.test/']);

        $this->assertSame(
            'https://shop.test/image/catalog/a.jpg',
            $this->builder()->build('/catalog/a.jpg'),
        );
    }

    private function builder(): ProductImageUrlBuilder
    {
        return new ProductImageUrlBuilder;
    }
}
