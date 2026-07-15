<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $session_id
 * @property string $role
 * @property string|null $content
 * @property array|null $tool_calls
 * @property string|null $tool_name
 * @property string|null $tool_call_id
 * @property string|null $model
 * @property int|null $tokens_used
 * @property int|null $latency_ms
 * @property bool $fallback_used
 * @property Carbon $created_at
 */
final class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'chat_messages';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'tool_calls',
        'tool_name',
        'tool_call_id',
        'model',
        'tokens_used',
        'latency_ms',
        'fallback_used',
    ];

    /** @return BelongsTo<ChatSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /** @return HasOne<MessageFeedback, $this> */
    public function feedback(): HasOne
    {
        return $this->hasOne(MessageFeedback::class, 'message_id');
    }

    protected static function newFactory(): ChatMessageFactory
    {
        return ChatMessageFactory::new();
    }

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'fallback_used' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
