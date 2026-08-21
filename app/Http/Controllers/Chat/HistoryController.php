<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ChatSession $session */
        $session = $request->attributes->get('chat_session');

        $order = $request->query('order', 'asc');
        $order = in_array($order, ['asc', 'desc'], true) ? $order : 'asc';

        $messages = $session->messages()
            ->with('feedback')
            ->whereIn('role', ['user', 'assistant'])
            ->whereNull('tool_calls')
            ->orderBy('id', $order)
            ->paginate(20)
            ->through(static fn (ChatMessage $message): array => (new ChatMessageResource($message))->resolve());

        return response()->json($messages);
    }
}
