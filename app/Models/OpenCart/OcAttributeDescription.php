<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

/**
 * @property int $attribute_id
 * @property int $language_id
 * @property string $name
 */
final class OcAttributeDescription extends ReadOnlyModel
{
    public $incrementing = false;
    protected $table = 'attribute_description';

    protected $primaryKey = 'attribute_id';
}
