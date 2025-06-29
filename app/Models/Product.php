<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $product_id
 * @property string $sku
 * @property string $model
 * @property string $image
 * @property float $price
 * @property float $cost
 * @property-read Collection<int, ProductDescription> $descriptions
 * @property-read Manufacturer $manufacturer
 * @property-read ProductCategory $category
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Review> $reviews
 */
final class Product extends Model
{
    protected $table = 'product';

    protected $primaryKey = 'product_id';

    /**
     * @return HasMany<ProductDescription, $this>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class, 'product_id', 'product_id');
    }

    /**
     * @return HasOne<Manufacturer, $this>
     */
    public function manufacturer(): HasOne
    {
        return $this->hasOne(Manufacturer::class, 'manufacturer_id', 'manufacturer_id');
    }

    /**
     * @return HasOne<ProductCategory, $this>
     */
    public function category(): HasOne
    {
        return $this->hasOne(ProductCategory::class, 'product_id', 'product_id');
    }

    /**
     * @return BelongsToMany<Category, $this, Pivot, 'pivot'>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_to_category', 'product_id', 'category_id');
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'product_id', 'product_id');
    }
}
