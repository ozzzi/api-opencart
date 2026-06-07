<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $stock_status_id
 * @property int $language_id
 * @property string $name
 */
final class OcStockStatus extends ReadOnlyModel
{
    protected $table = 'stock_status';

    protected $primaryKey = 'stock_status_id';
}
