<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $category_id
 * @property int $language_id
 * @property string $name
 * @property string $description
 */
final class CategoryDescription extends Model
{
    protected $table = 'category_description';
}
