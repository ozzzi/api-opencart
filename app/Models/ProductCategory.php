<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\OpenCart\ReadOnlyModel;

final class ProductCategory extends ReadOnlyModel
{
    protected $table = 'product_to_category';
}
