<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $category_id
 * @property int $language_id
 * @property string $name
 * @property string $description
 * @property string $meta_title
 * @property string $meta_description
 * @property string $meta_keyword
 */
final class OcCategoryDescription extends ReadOnlyModel
{
    public $incrementing = false;
    protected $table = 'category_description';

    protected $primaryKey = 'category_id';
}
