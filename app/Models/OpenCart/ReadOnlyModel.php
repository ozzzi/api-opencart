<?php

declare(strict_types=1);

namespace App\Models\OpenCart;

use App\Exceptions\ReadOnlyModelException;
use Illuminate\Database\Eloquent\Model;

abstract class ReadOnlyModel extends Model
{
    public $timestamps = false;
    protected $connection = 'opencart';

    final public function save(array $options = []): bool
    {
        throw ReadOnlyModelException::forModel(static::class);
    }

    final public function delete(): bool|null
    {
        throw ReadOnlyModelException::forModel(static::class);
    }
}
