<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $product_id
 * @property int $category_id
 */
final class OcProductToCategory extends ReadOnlyModel
{
    public $incrementing = false;
    protected $table = 'product_to_category';

    protected $primaryKey = 'product_id';

    /** @return HasMany<OcCategoryDescription, $this> */
    public function categoryDescriptions(): HasMany
    {
        return $this->hasMany(OcCategoryDescription::class, 'category_id', 'category_id');
    }
}
