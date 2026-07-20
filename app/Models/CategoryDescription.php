<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\OpenCart\ReadOnlyModel;

/**
 * @property int $category_id
 * @property int $language_id
 * @property string $name
 * @property string $description
 */
final class CategoryDescription extends ReadOnlyModel
{
    protected $table = 'category_description';
}
