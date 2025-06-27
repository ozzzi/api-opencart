<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $category_id
 * @property string $image
 * @property int $parent_id
 * @property-read CategoryDescription $description
 */
final class Category extends Model
{
    protected $table = 'category';

    protected $primaryKey = 'category_id';

    /**
     * @return HasMany<CategoryDescription>
     */
    public function description(): HasMany
    {
        return $this->hasMany(CategoryDescription::class, 'category_id', 'category_id');
    }
}
