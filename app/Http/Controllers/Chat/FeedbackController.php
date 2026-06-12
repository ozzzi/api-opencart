<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ChatFeedbackRequest;
use App\Models\Bot\ChatSession;
use App\Models\Bot\MessageFeedback;
use Illuminate\Http\JsonResponse;

final class FeedbackController extends Controller
{
    public function __invoke(ChatFeedbackRequest $request): JsonResponse
    {
        /** @var ChatSession $session */
        $session = $request->attributes->get('chat_session');

        $validated = $request->validated();

        $feedback = MessageFeedback::create([
            'message_id' => $validated['message_id'],
            'session_id' => $session->id,
            'rating'     => $validated['rating'],
            'comment'    => $validated['comment'] ?? null,
        ]);

        return response()->json(['feedback_id' => $feedback->id], 201);
    }
}
