<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Bot\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat message in the shape the widget renders
 * (task-structured-output.md §3.3).
 *
 * The invariant this exists to hold: history returns the same `parts` structure the
 * live SSE stream produces, so the widget renders replayed and streaming messages
 * through one component.
 *
 * @mixin ChatMessage
 */
final class ChatMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ChatMessage $message */
        $message = $this->resource;

        return [
            'message_id' => $message->id,
            'role' => $message->role,
            'created_at' => $message->created_at->utc()->toIso8601ZuluString(),
            'feedback' => $message->feedback?->rating,
            'parts' => $this->resolveParts($message),
        ];
    }

    /**
     * User messages and assistant rows written before the `parts` column existed
     * carry only `content`; synthesize the single text part they represent so the
     * widget never has to special-case them.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveParts(ChatMessage $message): array
    {
        if (is_array($message->parts) && $message->parts !== []) {
            return array_values($message->parts);
        }

        $content = mb_trim((string) $message->content);

        return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
    }
}
