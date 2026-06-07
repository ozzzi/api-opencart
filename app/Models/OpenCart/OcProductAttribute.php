<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $product_id
 * @property int $attribute_id
 * @property int $language_id
 * @property string $text
 */
final class OcProductAttribute extends ReadOnlyModel
{
    public $incrementing = false;
    protected $table = 'product_attribute';

    protected $primaryKey = 'product_id';

    /** @return HasMany<OcAttributeDescription, $this> */
    public function attributeDescriptions(): HasMany
    {
        return $this->hasMany(OcAttributeDescription::class, 'attribute_id', 'attribute_id');
    }
}
