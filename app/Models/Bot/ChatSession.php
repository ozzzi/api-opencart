<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Database\Factories\ChatSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $language
 * @property Carbon|null $consent_accepted_at
 * @property string|null $context_summary
 * @property array<string, mixed>|null $clarification_state
 * @property Carbon $last_activity_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ChatSession extends Model
{
    /** @use HasFactory<ChatSessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'chat_sessions';

    protected $fillable = [
        'ip_address',
        'user_agent',
        'language',
        'consent_accepted_at',
        'context_summary',
        'clarification_state',
        'last_activity_at',
    ];

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }

    /** @return HasOne<Lead, $this> */
    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class, 'session_id');
    }

    /** @return HasMany<MessageFeedback, $this> */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(MessageFeedback::class, 'session_id');
    }

    protected static function newFactory(): ChatSessionFactory
    {
        return ChatSessionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'consent_accepted_at' => 'datetime',
            'clarification_state' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }
}
