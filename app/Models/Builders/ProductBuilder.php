<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Product>
 */

final class ProductBuilder extends Builder
{
    public function active(): self
    {
        return $this->where('status', 1);
    }
}
