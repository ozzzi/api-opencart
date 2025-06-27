<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $product_id
 * @property int $language_id
 * @property string $name
 * @property string $description
 * @property-read string $descriptionRaw
 */
final class ProductDescription extends Model
{
    protected $table = 'product_description';

    protected function descriptionRaw(): Attribute
    {
        return Attribute::make(
            get: fn () => strip_tags(html_entity_decode($this->description, ENT_QUOTES, 'UTF-8')),
        );
    }
}
