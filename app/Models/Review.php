<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\OpenCart\ReadOnlyModel;

/**
 * @property int $review_id
 * @property int $product_id
 * @property int $customer_id
 * @property string $author
 * @property string $text
 * @property string $reply
 * @property int $rating
 * @property int $status
 */
final class Review extends ReadOnlyModel
{
    protected $table = 'review';

    protected $primaryKey = 'review_id';
}
