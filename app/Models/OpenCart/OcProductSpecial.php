<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $product_special_id
 * @property int $product_id
 * @property int $customer_group_id
 * @property int $priority
 * @property float $price
 * @property string|null $date_start
 * @property string|null $date_end
 *
 * @method static Builder<static> active()
 */
final class OcProductSpecial extends ReadOnlyModel
{
    protected $table = 'product_special';

    protected $primaryKey = 'product_special_id';

    /** Active special: date range valid or open-ended, ordered by priority. */
    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('date_start')->orWhere('date_start', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('date_end')->orWhere('date_end', '>=', $today))
            ->orderBy('priority');
    }
}
