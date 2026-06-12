<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Chat\SessionNotFoundException;
use App\Services\Chat\ConversationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChatSessionToken
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->header('X-Chat-Session');

        if (! $sessionId) {
            return new JsonResponse(['message' => 'Missing session token.'], 401);
        }

        try {
            $session = $this->conversationService->getSession((string) $sessionId);
        } catch (SessionNotFoundException) {
            return new JsonResponse(['message' => 'Invalid or expired session.'], 401);
        }

        $request->attributes->set('chat_session', $session);

        return $next($request);
    }
}
