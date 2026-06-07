<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $url_alias_id
 * @property string $query   e.g. "product_id=42"
 * @property string $keyword
 *
 * @method static Builder<static> forProduct(int $productId)
 */
final class OcUrlAlias extends ReadOnlyModel
{
    protected $table = 'url_alias';

    protected $primaryKey = 'url_alias_id';

    /** @param Builder<static> $query */
    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('query', "product_id={$productId}");
    }
}
