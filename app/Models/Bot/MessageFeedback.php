<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $message_id
 * @property string $session_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon $created_at
 */
final class MessageFeedback extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'message_feedback';

    protected $fillable = [
        'message_id',
        'session_id',
        'rating',
        'comment',
    ];

    /** @return BelongsTo<ChatMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    /** @return BelongsTo<ChatSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
