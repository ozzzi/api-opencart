<?php

declare(strict_types=1);

namespace App\Models\Bot;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string|null $session_id
 * @property int|null $message_id
 * @property string $model
 * @property string $type
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property float $cost_usd
 * @property int|null $latency_ms
 * @property string $provider
 * @property bool $success
 * @property string|null $error_message
 * @property Carbon $created_at
 */
final class LlmApiCall extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'llm_api_calls';

    protected $fillable = [
        'session_id',
        'message_id',
        'model',
        'type',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
        'latency_ms',
        'provider',
        'success',
        'error_message',
    ];

    /** @return BelongsTo<ChatSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
