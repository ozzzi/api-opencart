<?php

declare(strict_types=1);

namespace App\Services\Chat\Catalog;

use App\Models\OpenCart\OcCurrency;
use App\Models\OpenCart\OcSetting;
use App\Services\Chat\Catalog\Contracts\PriceFormatterInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Formats card prices exactly as the OpenCart 2.3 storefront does.
 *
 * OpenCart's `Currency::format()` is `symbol_left . number_format($value * $rate,
 * $decimal_place, '.', ',') . symbol_right` — the separators are hardcoded in the
 * framework, so they are hardcoded here too. Matching them byte-for-byte is the
 * whole point of reading the currency out of the shop's own database: a card must
 * never show a price string the customer cannot find on the product page.
 *
 * Known limitations (out of scope for MVP): `product.price` is stored in the shop's
 * base currency and excludes tax, so per-customer-group tax rules and multi-currency
 * switching are not applied.
 */
final class OpenCartPriceFormatter implements PriceFormatterInterface
{
    private const string CACHE_KEY = 'opencart:display_currency';

    private const int CACHE_TTL_SECONDS = 3600;

    public function format(float $amount): string
    {
        $currency = $this->currency();

        $formatted = number_format(
            $amount * $currency['value'],
            $currency['decimal_place'],
            '.',
            ',',
        );

        return $currency['symbol_left'].$formatted.$currency['symbol_right'];
    }

    public function currencyCode(): string
    {
        return $this->currency()['code'];
    }

    /**
     * Resolve the storefront's display currency, cached because it changes about
     * as often as the shop is rebuilt.
     *
     * A cache (rather than a property memo) is deliberate: this service is a
     * singleton, and under Octane a memo would survive for the worker's whole
     * lifetime with no way to pick up a currency change.
     *
     * @return array{code:string,symbol_left:string,symbol_right:string,decimal_place:int,value:float}
     */
    private function currency(): array
    {
        /** @var array{code:string,symbol_left:string,symbol_right:string,decimal_place:int,value:float} */
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->resolveCurrency(),
        );
    }

    /**
     * @return array{code:string,symbol_left:string,symbol_right:string,decimal_place:int,value:float}
     */
    private function resolveCurrency(): array
    {
        try {
            $code = OcSetting::query()
                ->where('store_id', 0)
                ->where('key', 'config_currency')
                ->value('value');

            $currency = $code === null
                ? null
                : OcCurrency::query()->where('code', $code)->first();

            if ($currency !== null) {
                return [
                    'code' => (string) $currency->code,
                    'symbol_left' => (string) $currency->symbol_left,
                    'symbol_right' => (string) $currency->symbol_right,
                    'decimal_place' => (int) $currency->decimal_place,
                    'value' => ((float) $currency->value) ?: 1.0,
                ];
            }
        } catch (Throwable) {
            // The OpenCart connection is unavailable or the schema differs — fall
            // through to the configured defaults rather than failing a whole reply.
        }

        return $this->fallbackCurrency();
    }

    /**
     * @return array{code:string,symbol_left:string,symbol_right:string,decimal_place:int,value:float}
     */
    private function fallbackCurrency(): array
    {
        /** @var array<string, mixed> $fallback */
        $fallback = config('opencart.currency_fallback', []);

        return [
            'code' => (string) ($fallback['code'] ?? 'UAH'),
            'symbol_left' => (string) ($fallback['symbol_left'] ?? ''),
            'symbol_right' => (string) ($fallback['symbol_right'] ?? ' ₴'),
            'decimal_place' => (int) ($fallback['decimal_place'] ?? 0),
            'value' => (float) ($fallback['value'] ?? 1.0),
        ];
    }
}
