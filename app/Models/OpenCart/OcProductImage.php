<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $product_image_id
 * @property int $product_id
 * @property string $image
 * @property int $sort_order
 */
final class OcProductImage extends ReadOnlyModel
{
    protected $table = 'product_image';

    protected $primaryKey = 'product_image_id';
}
