<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $product_id
 * @property string $sku
 * @property string $model
 * @property string $image
 * @property float $price
 * @property float $cost
 * @property-read Collection<ProductDescription> $descriptions
 * @property-read Manufacturer $manufacturer
 * @property-read ProductCategory $category
 * @property-read Collection<Category> $categories
 * @property-read Collection<Review> $reviews
 */
final class Product extends Model
{
    protected $table = 'product';

    protected $primaryKey = 'product_id';

    /**
     * @return HasMany<ProductDescription>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class, 'product_id', 'product_id');
    }

    /**
     * @return HasOne<Manufacturer>
     */
    public function manufacturer(): HasOne
    {
        return $this->hasOne(Manufacturer::class, 'manufacturer_id', 'manufacturer_id');
    }

    /**
     * @return HasOne<ProductCategory>
     */
    public function category(): HasOne
    {
        return $this->hasOne(ProductCategory::class, 'product_id', 'product_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_to_category', 'product_id', 'category_id');
    }

    /**
     * @return HasMany<Review>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'product_id', 'product_id');
    }
}
