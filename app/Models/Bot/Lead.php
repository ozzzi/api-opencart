<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $session_id
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $message
 * @property array|null $product_ids
 * @property string $status
 * @property Carbon|null $notified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Lead extends Model
{
    protected $table = 'leads';

    protected $fillable = [
        'session_id',
        'name',
        'phone',
        'email',
        'message',
        'product_ids',
        'status',
        'notified_at',
    ];

    /** @return BelongsTo<ChatSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'notified_at' => 'datetime',
        ];
    }
}
