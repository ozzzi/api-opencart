<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $currency_id
 * @property string $title
 * @property string $code
 * @property string $symbol_left
 * @property string $symbol_right
 * @property int $decimal_place
 * @property float $value
 * @property int $status
 */
final class OcCurrency extends ReadOnlyModel
{
    protected $table = 'currency';

    protected $primaryKey = 'currency_id';

    /**
     * OpenCart 2.3 stores `decimal_place` as a varchar and `value` as a decimal
     * string; cast both so arithmetic and number_format() behave.
     */
    protected function casts(): array
    {
        return [
            'decimal_place' => 'integer',
            'value' => 'float',
            'status' => 'integer',
        ];
    }
}
