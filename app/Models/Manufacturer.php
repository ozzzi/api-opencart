<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\OpenCart\ReadOnlyModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $manufacturer_id
 * @property string $name
 * @property-read Collection<int, Product> $products
 */
final class Manufacturer extends ReadOnlyModel
{
    protected $table = 'manufacturer';

    protected $primaryKey = 'manufacturer_id';

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'manufacturer_id', 'manufacturer_id');
    }
}
