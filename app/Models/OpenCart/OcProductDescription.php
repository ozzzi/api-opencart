<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $product_id
 * @property int $language_id
 * @property string $name
 * @property string $description
 * @property string $meta_title
 * @property string $meta_description
 * @property string $meta_keyword
 * @property string $tag
 */
final class OcProductDescription extends ReadOnlyModel
{
    public $incrementing = false;
    protected $table = 'product_description';

    protected $primaryKey = 'product_id';
}
