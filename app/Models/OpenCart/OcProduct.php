<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $product_id
 * @property float $price
 * @property int $quantity
 * @property int $status
 * @property int $stock_status_id
 * @property string|null $image
 * @property string $date_modified
 *
 * @method static Builder<static> active()
 */
final class OcProduct extends ReadOnlyModel
{
    protected $table = 'product';

    protected $primaryKey = 'product_id';

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    /** @return HasMany<OcProductDescription, $this> */
    public function descriptions(): HasMany
    {
        return $this->hasMany(OcProductDescription::class, 'product_id', 'product_id');
    }

    /** @return HasMany<OcProductToCategory, $this> */
    public function productCategories(): HasMany
    {
        return $this->hasMany(OcProductToCategory::class, 'product_id', 'product_id');
    }

    /** @return HasMany<OcProductAttribute, $this> */
    public function attributes(): HasMany
    {
        return $this->hasMany(OcProductAttribute::class, 'product_id', 'product_id');
    }

    /** @return HasMany<OcProductSpecial, $this> */
    public function specials(): HasMany
    {
        return $this->hasMany(OcProductSpecial::class, 'product_id', 'product_id');
    }

    /** @return HasMany<OcProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(OcProductImage::class, 'product_id', 'product_id');
    }

    /** @return HasOne<OcStockStatus, $this> */
    public function stockStatus(): HasOne
    {
        return $this->hasOne(OcStockStatus::class, 'stock_status_id', 'stock_status_id');
    }
}
