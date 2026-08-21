<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Catalog;

use App\Services\Chat\Catalog\OpenCartPriceFormatter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class OpenCartPriceFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'opencart.currency_fallback' => [
                'code' => 'UAH',
                'symbol_left' => '',
                'symbol_right' => ' ₴',
                'decimal_place' => 0,
                'value' => 1.0,
            ],
        ]);
    }

    public function test_falls_back_to_config_when_the_opencart_tables_are_unreachable(): void
    {
        $formatter = new OpenCartPriceFormatter;

        $this->assertSame('28,990 ₴', $formatter->format(28990.0));
        $this->assertSame('UAH', $formatter->currencyCode());
    }

    public function test_thousands_are_grouped_the_way_opencart_groups_them(): void
    {
        // OpenCart's Currency::format() hardcodes '.' and ',' — a card price must be
        // the same string the customer sees on the product page.
        $this->assertSame('1,234,567 ₴', (new OpenCartPriceFormatter)->format(1234567.0));
    }

    public function test_decimal_places_come_from_the_currency(): void
    {
        config(['opencart.currency_fallback.decimal_place' => 2]);

        $this->assertSame('28,990.50 ₴', (new OpenCartPriceFormatter)->format(28990.5));
    }

    public function test_a_left_hand_symbol_is_prefixed(): void
    {
        config([
            'opencart.currency_fallback.symbol_left' => '$',
            'opencart.currency_fallback.symbol_right' => '',
            'opencart.currency_fallback.code' => 'USD',
        ]);

        $formatter = new OpenCartPriceFormatter;

        $this->assertSame('$1,200', $formatter->format(1200.0));
        $this->assertSame('USD', $formatter->currencyCode());
    }

    public function test_the_exchange_rate_is_applied(): void
    {
        config(['opencart.currency_fallback.value' => 2.0]);

        $this->assertSame('200 ₴', (new OpenCartPriceFormatter)->format(100.0));
    }

    public function test_a_currency_row_from_the_shop_wins_over_the_fallback(): void
    {
        Cache::put('opencart:display_currency', [
            'code' => 'EUR',
            'symbol_left' => '€',
            'symbol_right' => '',
            'decimal_place' => 2,
            'value' => 1.0,
        ], 60);

        $formatter = new OpenCartPriceFormatter;

        $this->assertSame('€99.90', $formatter->format(99.9));
        $this->assertSame('EUR', $formatter->currencyCode());
    }
}
